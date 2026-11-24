"""Synthetic handoff with excluded SFTP writers only. Not a production publisher."""
import fcntl
import hashlib
import json
import os
import re
import secrets
from pathlib import Path
import select
import signal
import stat
import struct
import subprocess
import time

ROOT = Path("/sandbox")
SECOND = os.environ.get("F02_SFTP_SECOND_DEVICE") == "1"
JAIL = ROOT / ("quarantine-b" if SECOND else "quarantine")
SOURCE = JAIL / "incoming"
SOURCE_UID = 2003 if SECOND else 2001
EXPECTED = ROOT / ("complete-b" if SECOND else "complete")
PUBLISHED = Path(os.environ["F02_SFTP_PUBLISHED"])
DEVICE = os.environ["F02_SFTP_DEVICE"]
READER_GID = int(os.environ["F02_SFTP_READER_GID"])
FINAL = PUBLISHED / DEVICE
PRIVATE = PUBLISHED / (".publisher-private-" + DEVICE)
MAX_BYTES = 1024 * 1024


def require(ok, stage):
    if not ok:
        raise RuntimeError("F02 handoff failed: " + stage)


def identity(st):
    return (st.st_dev, st.st_ino, st.st_mode, st.st_nlink, st.st_size,
            st.st_mtime_ns, st.st_ctime_ns)


def publish(name, completion_confirmed=False, expected_digest=None, claim=None, checkpoint=None):
    # Fixed synthetic directory and names; no external path or shell input.
    require(name in {"backup.cfg", "symlink.cfg", "hardlink.cfg", "empty.cfg"}, "name")
    if name == "backup.cfg":
        require(completion_confirmed, "synthetic completion not confirmed")
    src_dir = os.open(SOURCE, os.O_DIRECTORY | os.O_NOFOLLOW | os.O_RDONLY)
    dst_dir = os.open(FINAL, os.O_DIRECTORY | os.O_NOFOLLOW | os.O_RDONLY)
    pending_dir = os.open(PRIVATE, os.O_DIRECTORY | os.O_NOFOLLOW | os.O_RDONLY)
    temp = ".pending"
    created = False
    try:
        private_stat = os.fstat(pending_dir)
        require(private_stat.st_uid == 0 and private_stat.st_gid == 0
                and stat.S_IMODE(private_stat.st_mode) == 0o700
                and private_stat.st_dev == os.fstat(dst_dir).st_dev,
                "private same-filesystem publisher directory")
        fd = os.open(name, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW, dir_fd=src_dir)
        try:
            before = os.fstat(fd)
            require(stat.S_ISREG(before.st_mode) and before.st_uid == SOURCE_UID
                    and before.st_nlink == 1 and 0 < before.st_size <= MAX_BYTES, "source type/ownership/size")
            require(identity(os.stat(name, dir_fd=src_dir, follow_symlinks=False))
                    == identity(before), "source identity")
            out = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                          0o600, dir_fd=pending_dir)
            created = True
            try:
                total = 0
                digest = hashlib.sha256()
                while True:
                    chunk = os.read(fd, 65536)
                    if not chunk:
                        break
                    total += len(chunk)
                    digest.update(chunk)
                    require(total <= MAX_BYTES and total <= before.st_size, "bounded copy")
                    view = memoryview(chunk)
                    while view:
                        written = os.write(out, view)
                        require(written > 0, "write progress")
                        view = view[written:]
                require(total == before.st_size and identity(os.fstat(fd)) == identity(before)
                        and identity(os.stat(name, dir_fd=src_dir, follow_symlinks=False))
                        == identity(before), "stable source")
                # Synthetic oracle only: verify known fixture bytes BEFORE
                # publication, never treat this as a real device manifest.
                if name == "backup.cfg":
                    if expected_digest is None:
                        expected = EXPECTED.read_bytes()
                        expected_digest = (len(expected), hashlib.sha256(expected).hexdigest())
                    require((total, digest.hexdigest()) == expected_digest,
                            "synthetic content incomplete or mismatched")
                os.fchown(out, 0, READER_GID)
                os.fchmod(out, 0o440)
                os.fsync(out)
            finally:
                os.close(out)
            # Persist the private directory entry before consuming a claim.
            os.fsync(pending_dir)
            try:
                os.stat(name, dir_fd=dst_dir, follow_symlinks=False)
            except FileNotFoundError:
                pass
            else:
                raise RuntimeError("F02 handoff failed: fresh final path")
            if checkpoint is not None:
                checkpoint("copied_before_claim")
            if claim is not None:
                claim()
            if checkpoint is not None:
                checkpoint("claimed_before_rename")
            # The final directory is exclusively writable by the trusted
            # publisher; this test has exactly one publisher and no reader yet.
            os.rename(temp, name, src_dir_fd=pending_dir, dst_dir_fd=dst_dir)
            created = False
            os.fsync(dst_dir)
            os.fsync(pending_dir)
            if checkpoint is not None:
                checkpoint("published_before_ack")
        finally:
            os.close(fd)
    finally:
        if created:
            os.unlink(temp, dir_fd=pending_dir)
            os.fsync(pending_dir)
        os.close(pending_dir)
        os.close(src_dir)
        os.close(dst_dir)



def u32(number):
    return struct.pack(">I", number)


def wire_string(value):
    return u32(len(value)) + value


def read_exact(fd, length):
    result = bytearray()
    deadline = time.monotonic() + 10
    while len(result) < length:
        remaining = deadline - time.monotonic()
        require(remaining > 0 and bool(select.select([fd], [], [], remaining)[0]),
                "SFTP response timeout")
        chunk = os.read(fd, length - len(result))
        require(bool(chunk), "SFTP response EOF")
        result.extend(chunk)
    return bytes(result)


def exchange(client, payload):
    client.stdin.write(u32(len(payload)) + payload)
    client.stdin.flush()
    fd = client.stdout.fileno()
    length = struct.unpack(">I", read_exact(fd, 4))[0]
    require(0 < length <= 65536, "bounded SFTP response")
    return read_exact(fd, length)


def interrupted_write(content):
    # Real SFTP v3 protocol over the authenticated SSH subsystem.
    # Write is acknowledged; deliberately never send FXP_CLOSE.
    args = ["ssh", "-T", "-s", "-p", "2222", "-i", "/sandbox/keys/client",
            "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes",
            "-o", "StrictHostKeyChecking=yes", "-o", "UserKnownHostsFile=/sandbox/keys/known_hosts",
            "-o", "GlobalKnownHostsFile=/dev/null", "-o", "ConnectTimeout=3",
            "f02upload@127.0.0.1", "sftp"]
    client = subprocess.Popen(args, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                              stderr=subprocess.DEVNULL)
    try:
        response = exchange(client, bytes([1]) + u32(3))
        require(response[:5] == bytes([2]) + u32(3), "SFTP v3 negotiation")
        response = exchange(client, bytes([3]) + u32(1) + wire_string(b"/incoming/backup.cfg")
                            + u32(2 | 8 | 16) + u32(0))
        require(len(response) >= 9 and response[:5] == bytes([102]) + u32(1), "SFTP open handle")
        handle_len = struct.unpack(">I", response[5:9])[0]
        require(0 < handle_len <= 256 and len(response) == 9 + handle_len, "SFTP handle bounds")
        response = exchange(client, bytes([6]) + u32(2) + wire_string(response[9:])
                            + struct.pack(">Q", 0) + wire_string(content))
        require(response[:9] == bytes([101]) + u32(2) + u32(0), "SFTP acknowledged write")
        require((SOURCE / "backup.cfg").read_bytes() == content, "actual quarantined bytes")
        with (JAIL / "session.lock").open("rb") as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                pass
            else:
                raise RuntimeError("F02 active interrupted session lock not held")
        require(not list(FINAL.iterdir()), "no output while session active")
        # Only the isolated synthetic SSH client is killed, not the listener.
        client.kill()
        require(client.wait(timeout=10) == -9, "synthetic client killed")
    finally:
        if client.poll() is None:
            client.kill()
            client.wait(timeout=10)
        client.stdin.close()
        client.stdout.close()


def expect_refusal(confirmed, label):
    try:
        publish("backup.cfg", completion_confirmed=confirmed)
    except RuntimeError as error:
        require(str(error) == "F02 handoff failed: " + label, "exact refusal reason")
    else:
        raise RuntimeError("F02 interrupted file accepted")
    require(not list(FINAL.iterdir()) and not list(PRIVATE.iterdir()), "refused candidate left no output")


def aborted_probe():
    require(os.environ.get("F02_SFTP_INTERRUPTED") == "1"
            and os.environ.get("F02_SFTP_LIVE_LOCK") == "1"
            and not SECOND and DEVICE == "123" and PUBLISHED == ROOT / "published",
            "interrupted fixture contract")
    # First write only a prefix, then a complete-looking file without CLOSE.
    # Neither client completed the explicit synthetic batch contract.
    expected = EXPECTED.read_bytes()
    for content in (expected[:8], expected):
        interrupted_write(content)
        with (JAIL / "session.lock").open("rb") as lock:
            deadline = time.monotonic() + 10
            while True:
                try:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    require(time.monotonic() < deadline, "aborted session lock release")
                    time.sleep(0.1)
            require((SOURCE / "backup.cfg").read_bytes() == content, "aborted candidate retained")
            expect_refusal(False, "synthetic completion not confirmed")
            if content != expected:
                expect_refusal(True, "synthetic content incomplete or mismatched")
                print("F02_SFTP_INTERRUPTED_PARTIAL_REFUSED_OK (SYNTHETIC_ONLY)")
            else:
                print("F02_SFTP_INTERRUPTED_COMPLETE_LOOKING_REFUSED_OK (SYNTHETIC_ONLY)")
    print("F02_SFTP_ABORT_LOCK_RELEASE_OK (SYNTHETIC_ONLY)")



def strict_object(pairs):
    result = {}
    for key, value in pairs:
        require(key not in result, "duplicate manifest key")
        result[key] = value
    return result


def read_manifest():
    directory = os.open(SOURCE, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        try:
            fd = os.open("backup.ready.json", os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW,
                         dir_fd=directory)
        except FileNotFoundError:
            raise RuntimeError("F02 handoff failed: missing completion manifest") from None
        try:
            before = os.fstat(fd)
            require(stat.S_ISREG(before.st_mode) and before.st_uid == SOURCE_UID
                    and before.st_nlink == 1 and 0 < before.st_size <= 4096,
                    "manifest type/ownership/size")
            data = bytearray()
            while True:
                part = os.read(fd, 4097 - len(data))
                if not part:
                    break
                data.extend(part)
                require(len(data) <= 4096, "manifest bound")
            require(len(data) == before.st_size and identity(os.fstat(fd)) == identity(before)
                    and identity(os.stat("backup.ready.json", dir_fd=directory, follow_symlinks=False))
                    == identity(before), "manifest identity")
        finally:
            os.close(fd)
    finally:
        os.close(directory)
    try:
        parsed = json.loads(data.decode("utf-8"), object_pairs_hook=strict_object)
    except (UnicodeError, ValueError, RecursionError):
        raise RuntimeError("F02 handoff failed: malformed manifest") from None
    require(type(parsed) is dict and set(parsed) == {
        "version", "device", "nonce", "filename", "size", "sha256"
    }, "manifest schema")
    require(type(parsed["version"]) is int and parsed["version"] == 1
            and type(parsed["device"]) is int and type(parsed["size"]) is int
            and 0 < parsed["size"] <= MAX_BYTES
            and type(parsed["nonce"]) is str and re.fullmatch(r"[0-9a-f]{32}", parsed["nonce"]) is not None
            and type(parsed["filename"]) is str and type(parsed["sha256"]) is str
            and re.fullmatch(r"[0-9a-f]{64}", parsed["sha256"]) is not None, "manifest fields")
    return parsed


def locked_manifest_accept(grant, used, checkpoint=None):
    # Fixed synthetic grant from the trusted controller, NOT from the
    # manifest. The SFTP sender is authenticated but is trusted to declare
    # completion only under an explicitly selected managed-sender contract.
    require(not used.exists(), "completion already claimed")
    with (JAIL / "session.lock").open("rb") as lock:
        deadline = time.monotonic() + 10
        while True:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                break
            except BlockingIOError:
                require(time.monotonic() < deadline, "manifest session lock")
                time.sleep(0.1)
        require(not used.exists(), "completion already claimed")
        now = int(time.time())
        require(grant["start"] <= now < grant["deadline"], "completion grant expired or not due")
        value = read_manifest()
        require(value["device"] == int(DEVICE) == grant["device"], "manifest device")
        require(value["nonce"] == grant["nonce"], "manifest nonce")
        require(value["filename"] == "backup.cfg", "manifest basename")

        def claim():
            # Consume before rename; a crash here fails closed and would
            # need operator reconciliation. NOT a transactional ledger.
            require(int(time.time()) < grant["deadline"], "completion grant expired or not due")
            fd = os.open(used, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            try:
                os.fsync(fd)
            finally:
                os.close(fd)
            parent = os.open(used.parent, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                os.fsync(parent)
            finally:
                os.close(parent)

        publish("backup.cfg", completion_confirmed=True,
                expected_digest=(value["size"], value["sha256"]), claim=claim, checkpoint=checkpoint)


def manifest_batch(commands):
    args = ["sftp", "-q", "-b", "-", "-P", "2222", "-i", "/sandbox/keys/client",
            "-o", "BatchMode=yes", "-o", "IdentitiesOnly=yes", "-o", "StrictHostKeyChecking=yes",
            "-o", "UserKnownHostsFile=/sandbox/keys/known_hosts", "-o", "GlobalKnownHostsFile=/dev/null",
            "-o", "ConnectTimeout=3", "f02upload@127.0.0.1"]
    result = subprocess.run(args, input=commands, text=True, capture_output=True, timeout=15)
    require(result.returncode == 0, "manifest synthetic SFTP batch")


def send_manifest(raw):
    (ROOT / "sender-manifest.json").write_bytes(raw)
    # OpenSSH POSIX rename extension replaces previous test sidecars atomically.
    manifest_batch("put /sandbox/sender-manifest.json /incoming/manifest.part\n"
                   "rename /incoming/manifest.part /incoming/backup.ready.json\n")


def manifest_probe():
    require(os.environ.get("F02_SFTP_MANIFEST") == "1"
            and os.environ.get("F02_SFTP_LIVE_LOCK") == "1"
            and not SECOND and DEVICE == "123" and PUBLISHED == ROOT / "published",
            "manifest fixture contract")
    grant = {"device": 123, "nonce": secrets.token_hex(16), "start": int(time.time()) - 1,
             "deadline": int(time.time()) + 90}
    used_dir = ROOT / "completion-claims"
    used_dir.mkdir(mode=0o700)
    used = used_dir / grant["nonce"]
    # Test sender knows the bytes and challenge, but the publisher reads only
    # the uploaded manifest and protected controller grant for admission.
    expected = EXPECTED.read_bytes()
    valid = {"version": 1, "device": 123, "nonce": grant["nonce"], "filename": "backup.cfg",
             "size": len(expected), "sha256": hashlib.sha256(expected).hexdigest()}

    def refusal(label, selected_grant=grant):
        try:
            locked_manifest_accept(selected_grant, used)
        except RuntimeError as error:
            require(str(error) == "F02 handoff failed: " + label, "exact manifest refusal")
        else:
            raise RuntimeError("F02 invalid manifest accepted")
        require(not used.exists() and not list(FINAL.iterdir())
                and not list(PRIVATE.iterdir()), "manifest refusal left output or claim")

    manifest_batch("put /sandbox/complete /incoming/upload.part\n"
                   "rename /incoming/upload.part /incoming/backup.cfg\n")
    refusal("missing completion manifest")
    send_manifest(b"{")
    refusal("malformed manifest")
    send_manifest(b'{"version":1,"version":1}')
    refusal("duplicate manifest key")
    for field, value, reason in (
        ("device", 124, "manifest device"),
        ("nonce", "0" * 32, "manifest nonce"),
        ("filename", "../backup.cfg", "manifest basename"),
        ("size", len(expected) + 1, "synthetic content incomplete or mismatched"),
        ("sha256", "0" * 64, "synthetic content incomplete or mismatched"),
    ):
        send_manifest(json.dumps(dict(valid, **{field: value})).encode())
        refusal(reason)
    send_manifest(json.dumps(valid).encode())
    refusal("completion grant expired or not due", dict(grant, start=0, deadline=1))
    print("F02_SFTP_MANIFEST_INVALID_REFUSED_OK (SYNTHETIC_ONLY)")
    locked_manifest_accept(grant, used)
    require(used.is_file() and used.stat().st_uid == 0
            and stat.S_IMODE(used.stat().st_mode) == 0o600, "protected completion claim")
    verify_published()
    before = identity((FINAL / "backup.cfg").lstat())
    # The same manifest delivered again cannot claim another publication.
    send_manifest(json.dumps(valid).encode())
    try:
        locked_manifest_accept(grant, used)
    except RuntimeError as error:
        require(str(error) == "F02 handoff failed: completion already claimed", "exact replay refusal")
    else:
        raise RuntimeError("F02 completion replay accepted")
    require(identity((FINAL / "backup.cfg").lstat()) == before
            and (FINAL / "backup.cfg").read_bytes() == expected
            and list(FINAL.iterdir()) == [FINAL / "backup.cfg"], "manifest replay changed publication")
    print("F02_SFTP_MANIFEST_REPLAY_REFUSED_OK (SYNTHETIC_ONLY)")
    print("F02_SFTP_MANIFEST_PUBLICATION_OK (SYNTHETIC_ONLY)")




def verify_reader_view(names):
    # Actual OS identity, not a root-side os.access check. Enumerate all
    # children (including dot names) and match permissive basename patterns.
    # The PHP scanner is independently audited, not executed by this probe.
    code = """import fnmatch, os, re, sys
from pathlib import Path
final, private = map(Path, sys.argv[1:3])
expected = sys.argv[3:]
assert os.geteuid() == 2002
entries = sorted(os.listdir(final))
assert entries == expected
assert sorted(n for n in entries if fnmatch.fnmatchcase(n, '*')) == expected
assert sorted(n for n in entries if re.fullmatch(r'.*', n)) == expected
for operation in (lambda: os.listdir(private), lambda: (private / '.pending').open('rb')):
    try:
        result = operation()
    except PermissionError:
        pass
    else:
        if hasattr(result, 'close'):
            result.close()
        raise RuntimeError('private staging visible to reader')
for name in expected:
    with (final / name).open('rb') as stream:
        assert stream.read(1)
"""
    result = subprocess.run(["runuser", "-u", "f02read", "--", "python3", "-c", code,
                             str(FINAL), str(PRIVATE), *names], timeout=10, capture_output=True)
    require(result.returncode == 0, "reader view or private directory access")


def manifest_crash_probe():
    # One disposable listener, two independently granted synthetic occurrences.
    # The parent models explicit operator reconciliation, never auto-retry.
    require(os.environ.get("F02_SFTP_MANIFEST_CRASH") == "1"
            and os.environ.get("F02_SFTP_MANIFEST") == "1"
            and os.environ.get("F02_SFTP_LIVE_LOCK") == "1"
            and not SECOND and DEVICE == "123" and PUBLISHED == ROOT / "published",
            "manifest crash fixture contract")
    used_dir = ROOT / "completion-claims"
    used_dir.mkdir(mode=0o700)
    expected = EXPECTED.read_bytes()
    spent = []
    pending = PRIVATE / ".pending"
    final = FINAL / "backup.cfg"

    def assert_replay_refused(grant, used):
        try:
            locked_manifest_accept(grant, used)
        except RuntimeError as error:
            require(str(error) == "F02 handoff failed: completion already claimed", "crash replay refusal")
        else:
            raise RuntimeError("F02 crashed completion replay accepted")

    for boundary in ("claimed_before_rename", "published_before_ack"):
        grant = {"device": 123, "nonce": secrets.token_hex(16), "start": int(time.time()) - 1,
                 "deadline": int(time.time()) + 90}
        used = used_dir / grant["nonce"]
        require(used not in spent and not list(FINAL.iterdir())
                and not list(PRIVATE.iterdir()), "fresh synthetic occurrence")
        manifest_batch("put /sandbox/complete /incoming/upload.part\n"
                       "rename /incoming/upload.part /incoming/backup.cfg\n")
        value = {"version": 1, "device": 123, "nonce": grant["nonce"], "filename": "backup.cfg",
                 "size": len(expected), "sha256": hashlib.sha256(expected).hexdigest()}
        send_manifest(json.dumps(value).encode())

        def checkpoint(stage):
            if stage == "copied_before_claim":
                require(pending.is_file() and not used.exists(), "private copy before claim")
                verify_reader_view([])
                print("F02_SFTP_PRIVATE_PENDING_BEFORE_CLAIM_OK (SYNTHETIC_ONLY)", flush=True)
            if stage == boundary:
                os.kill(os.getpid(), signal.SIGKILL)

        # No external processes or real files are targeted. Only this forked
        # synthetic publisher is terminated at the selected checkpoint.
        pid = os.fork()
        if pid == 0:
            try:
                locked_manifest_accept(grant, used, checkpoint=checkpoint)
            except BaseException:
                os._exit(2)
            os._exit(3)
        deadline = time.monotonic() + 20
        while True:
            waited, status = os.waitpid(pid, os.WNOHANG)
            if waited == pid:
                break
            if time.monotonic() >= deadline:
                os.kill(pid, signal.SIGKILL)
                os.waitpid(pid, 0)
                raise RuntimeError("F02 crash publisher timeout")
            time.sleep(0.05)
        require(os.WIFSIGNALED(status) and os.WTERMSIG(status) == signal.SIGKILL,
                "publisher actually killed")
        claim_stat = used.lstat()
        require(stat.S_ISREG(claim_stat.st_mode) and claim_stat.st_uid == 0
                and stat.S_IMODE(claim_stat.st_mode) == 0o600
                and claim_stat.st_nlink == 1, "protected spent claim after crash")
        spent.append(used)
        # The session lock is not permanently held by the dead publisher.
        with (JAIL / "session.lock").open("rb") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        assert_replay_refused(grant, used)

        if boundary == "claimed_before_rename":
            require(not final.exists() and not list(FINAL.iterdir())
                    and list(PRIVATE.iterdir()) == [pending],
                    "pre-rename crash left no final basename")
            st = pending.lstat()
            require(stat.S_ISREG(st.st_mode) and st.st_uid == 0 and st.st_gid == READER_GID
                    and stat.S_IMODE(st.st_mode) == 0o440 and st.st_nlink == 1
                    and pending.read_bytes() == expected, "protected pending evidence")
            verify_reader_view([])
            print("F02_SFTP_PRIVATE_PENDING_AFTER_CRASH_OK (SYNTHETIC_ONLY)")
            before = identity(st)
            assert_replay_refused(grant, used)
            require(identity(pending.lstat()) == before and not final.exists(),
                    "pre-rename replay unchanged")
            print("F02_SFTP_CLAIM_CRASH_BEFORE_RENAME_REFUSED_OK (SYNTHETIC_ONLY)")
            # Explicit fixture operator discard, never an automatic recovery.
            # Keep the spent nonce; do not promote the pending file.
            pending.unlink()
            directory = os.open(PRIVATE, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
            require(not list(FINAL.iterdir()) and not list(PRIVATE.iterdir())
                    and used.exists(), "operator discard retains spent claim")
            assert_replay_refused(grant, used)
            print("F02_SFTP_CLAIM_CRASH_OPERATOR_DISCARD_OK (SYNTHETIC_ONLY)")
        else:
            require(not list(PRIVATE.iterdir()) and list(FINAL.iterdir()) == [final],
                    "post-rename crash retained final")
            before = identity(final.lstat())
            verify_published()
            assert_replay_refused(grant, used)
            require(identity(final.lstat()) == before and final.read_bytes() == expected,
                    "post-rename replay unchanged")
            print("F02_SFTP_CLAIM_CRASH_AFTER_RENAME_REFUSED_OK (SYNTHETIC_ONLY)")
    require(len(spent) == 2 and all(p.is_file() for p in spent)
            and set(used_dir.iterdir()) == set(spent), "both spent claims retained")
    print("F02_SFTP_CLAIM_CRASH_RECONCILIATION_OK (SYNTHETIC_ONLY)")


def main():
    require(os.geteuid() == 0 and os.environ.get("F02_SFTP_DISPOSABLE") == "1"
            and os.environ.get("GITHUB_ACTIONS") == "true", "synthetic identity")
    require(PUBLISHED in {ROOT / "published", Path("/handoff-export")}
            and DEVICE.isascii() and DEVICE.isdecimal() and int(DEVICE) > 0
            and str(int(DEVICE)) == DEVICE and READER_GID >= 1000
            and READER_GID != 2001, "fixed synthetic export contract")
    require(not SECOND or (DEVICE == "124" and PUBLISHED == ROOT / "published"
                           and READER_GID == 2002 and os.environ.get("F02_SFTP_MULTI_DEVICE") == "1"),
            "second synthetic device contract")
    for parent in [Path("/"), ROOT, JAIL, PUBLISHED, FINAL, PRIVATE]:
        st = parent.lstat()
        require(stat.S_ISDIR(st.st_mode) and st.st_uid == 0 and st.st_mode & 0o022 == 0,
                "protected ancestor")
    private_stat = PRIVATE.lstat()
    require(private_stat.st_gid == 0 and stat.S_IMODE(private_stat.st_mode) == 0o700
            and private_stat.st_dev == FINAL.lstat().st_dev, "private publisher boundary")
    if os.environ.get("F02_SFTP_MANIFEST_PROBE") == "1":
        if os.environ.get("F02_SFTP_MANIFEST_CRASH") == "1":
            manifest_crash_probe()
        else:
            manifest_probe()
        return
    if os.environ.get("F02_SFTP_ABORT_PROBE") == "1":
        aborted_probe()
        return
    for name in ("symlink.cfg", "hardlink.cfg", "empty.cfg"):
        try:
            publish(name)
        except (OSError, RuntimeError):
            require(not list(FINAL.iterdir()) and not list(PRIVATE.iterdir()), "negative candidate left no output")
        else:
            raise RuntimeError("F02 handoff accepted unsafe candidate")
    publish("backup.cfg", completion_confirmed=os.environ.get("F02_SFTP_BATCH_CONFIRMED") == "1")
    verify_published()


def verify_published():
    require(not list(PRIVATE.iterdir()), "no pending artifact after publication")
    verify_reader_view(["backup.cfg"])
    require([p.name for p in FINAL.iterdir()] == ["backup.cfg"], "one published file")
    final = FINAL / "backup.cfg"
    st = final.lstat()
    require(st.st_uid == 0 and st.st_gid == READER_GID and stat.S_IMODE(st.st_mode) == 0o440
            and st.st_nlink == 1, "read-only final ownership")
    require(final.read_bytes() == EXPECTED.read_bytes(), "synthetic transfer content")
    cmd = ["runuser", "-u", "f02read", "--", "/usr/local/bin/f02-safe-digest",
           str(PUBLISHED), DEVICE, final.name, str(st.st_dev), str(st.st_ino),
           str(st.st_size), str(int(st.st_mtime)), str(int(st.st_ctime)), "1", str(MAX_BYTES)]
    proc = subprocess.run(cmd, check=True, timeout=12, capture_output=True, text=True)
    evidence = json.loads(proc.stdout)
    require(evidence["state"] == "descriptor_checked_only"
            and evidence["sha256"] == hashlib.sha256(EXPECTED.read_bytes()).hexdigest()
            and evidence["size"] == st.st_size
            and "verified_file_token" not in evidence and "success" not in evidence, "native evidence only")
    print("F02_SFTP_NATIVE_DESCRIPTOR_OK (NO_BACKUP_SUCCESS)")


if __name__ == "__main__":
    main()
