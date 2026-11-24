"""Synthetic handoff with excluded SFTP writers only. Not a production publisher."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import select
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
MAX_BYTES = 1024 * 1024


def require(ok, stage):
    if not ok:
        raise RuntimeError("F02 handoff failed: " + stage)


def identity(st):
    return (st.st_dev, st.st_ino, st.st_mode, st.st_nlink, st.st_size,
            st.st_mtime_ns, st.st_ctime_ns)


def publish(name, completion_confirmed=False):
    # Fixed synthetic directory and names; no external path or shell input.
    require(name in {"backup.cfg", "symlink.cfg", "hardlink.cfg", "empty.cfg"}, "name")
    if name == "backup.cfg":
        require(completion_confirmed, "synthetic completion not confirmed")
    src_dir = os.open(SOURCE, os.O_DIRECTORY | os.O_NOFOLLOW | os.O_RDONLY)
    dst_dir = os.open(FINAL, os.O_DIRECTORY | os.O_NOFOLLOW | os.O_RDONLY)
    temp = ".pending"
    created = False
    try:
        fd = os.open(name, os.O_RDONLY | os.O_NONBLOCK | os.O_NOFOLLOW, dir_fd=src_dir)
        try:
            before = os.fstat(fd)
            require(stat.S_ISREG(before.st_mode) and before.st_uid == SOURCE_UID
                    and before.st_nlink == 1 and 0 < before.st_size <= MAX_BYTES, "source type/ownership/size")
            require(identity(os.stat(name, dir_fd=src_dir, follow_symlinks=False))
                    == identity(before), "source identity")
            out = os.open(temp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW,
                          0o600, dir_fd=dst_dir)
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
                    expected = EXPECTED.read_bytes()
                    require(total == len(expected)
                            and digest.digest() == hashlib.sha256(expected).digest(),
                            "synthetic content incomplete or mismatched")
                os.fchown(out, 0, READER_GID)
                os.fchmod(out, 0o440)
                os.fsync(out)
            finally:
                os.close(out)
            require(not (FINAL / name).exists(), "fresh final path")
            # The final directory is exclusively writable by the trusted
            # publisher; this test has exactly one publisher and no reader yet.
            os.rename(temp, name, src_dir_fd=dst_dir, dst_dir_fd=dst_dir)
            created = False
            os.fsync(dst_dir)
        finally:
            os.close(fd)
    finally:
        if created:
            os.unlink(temp, dir_fd=dst_dir)
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
    require(not list(FINAL.iterdir()), "refused candidate left no output")


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
    for parent in [Path("/"), ROOT, JAIL, PUBLISHED, FINAL]:
        st = parent.lstat()
        require(stat.S_ISDIR(st.st_mode) and st.st_uid == 0 and st.st_mode & 0o022 == 0,
                "protected ancestor")
    if os.environ.get("F02_SFTP_ABORT_PROBE") == "1":
        aborted_probe()
        return
    for name in ("symlink.cfg", "hardlink.cfg", "empty.cfg"):
        try:
            publish(name)
        except (OSError, RuntimeError):
            require(not list(FINAL.iterdir()), "negative candidate left no output")
        else:
            raise RuntimeError("F02 handoff accepted unsafe candidate")
    publish("backup.cfg", completion_confirmed=os.environ.get("F02_SFTP_BATCH_CONFIRMED") == "1")
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
