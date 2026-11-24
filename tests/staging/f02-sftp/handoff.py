"""Synthetic handoff with excluded SFTP writers only. Not a production publisher."""
import hashlib
import json
import os
from pathlib import Path
import stat
import subprocess

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


def publish(name):
    # Fixed synthetic directory and names; no external path or shell input.
    require(name in {"backup.cfg", "symlink.cfg", "hardlink.cfg", "empty.cfg"}, "name")
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
                while True:
                    chunk = os.read(fd, 65536)
                    if not chunk:
                        break
                    total += len(chunk)
                    require(total <= MAX_BYTES and total <= before.st_size, "bounded copy")
                    view = memoryview(chunk)
                    while view:
                        written = os.write(out, view)
                        require(written > 0, "write progress")
                        view = view[written:]
                require(total == before.st_size and identity(os.fstat(fd)) == identity(before)
                        and identity(os.stat(name, dir_fd=src_dir, follow_symlinks=False))
                        == identity(before), "stable source")
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
    for name in ("symlink.cfg", "hardlink.cfg", "empty.cfg"):
        try:
            publish(name)
        except (OSError, RuntimeError):
            require(not list(FINAL.iterdir()), "negative candidate left no output")
        else:
            raise RuntimeError("F02 handoff accepted unsafe candidate")
    publish("backup.cfg")
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
