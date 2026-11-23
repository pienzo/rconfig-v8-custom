# SPDX-License-Identifier: GPL-3.0-or-later
"""Synthetic native F02 verifier tests; no production backups are opened."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


SOURCE = Path(__file__).resolve().parents[1] / "f02_safe_digest.c"


class SafeDigestTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.build = tempfile.TemporaryDirectory(prefix="f02-native-build-")
        cls.binary = Path(cls.build.name) / "f02_safe_digest"
        subprocess.run(
            ["cc", "-std=c11", "-O2", "-Wall", "-Wextra", "-Werror",
             str(SOURCE), "-lcrypto", "-o", str(cls.binary)],
            check=True,
            capture_output=True,
            text=True,
        )

    @classmethod
    def tearDownClass(cls):
        cls.build.cleanup()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="f02-native-fixture-")
        self.root = Path(self.temp.name) / "trusted"
        self.root.mkdir()
        self.device = self.root / "123"
        self.device.mkdir()
        self.file = self.device / "backup-20261123.cfg"
        self.content = b"SYNTHETIC-BACKUP-F02-NATIVE-VERIFIER"
        self.file.write_bytes(self.content)

    def tearDown(self):
        self.temp.cleanup()

    def command(self, path=None, filename=None, root=None, device="123", expected=None, minimum="1", maximum="104857600"):
        path = path or self.file
        snapshot = os.lstat(path) if expected is None else expected
        return [
            str(self.binary),
            str(root or self.root),
            device,
            filename or path.name,
            str(snapshot.st_dev),
            str(snapshot.st_ino),
            str(snapshot.st_size),
            str(int(snapshot.st_mtime)),
            str(int(snapshot.st_ctime)),
            minimum,
            maximum,
        ]

    def run_refused(self, *args):
        proc = subprocess.run(*args, timeout=12, capture_output=True, text=True)
        self.assertNotEqual(0, proc.returncode)
        self.assertNotIn("sha256", proc.stdout)

    def test_regular_file_produces_private_digest_evidence_only(self):
        result = subprocess.run(self.command(), timeout=12, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)
        data = json.loads(result.stdout)
        self.assertEqual("descriptor_checked_only", data["state"])
        self.assertEqual(1, data["version"])
        self.assertEqual(hashlib.sha256(self.content).hexdigest(), data["sha256"])
        self.assertEqual(len(self.content), data["size"])
        self.assertEqual(os.stat(self.file).st_ino, data["inode"])
        self.assertNotIn("success", data)
        self.assertNotIn("verified_file_token", data)
        self.assertNotIn("filename", data)

    def test_modified_or_wrong_metadata_is_rejected(self):
        original = self.command()
        self.file.write_bytes(self.content + b"-MODIFIED")
        self.run_refused(original)
        bad_inode = self.command()
        bad_inode[5] = str(int(bad_inode[5]) + 1)
        self.run_refused(bad_inode)

    def test_symlink_file_and_root_symlink_are_rejected(self):
        link = self.device / "link.cfg"
        link.symlink_to(self.file)
        self.run_refused(self.command(path=link))
        rootlink = Path(self.temp.name) / "rootlink"
        rootlink.symlink_to(self.root, target_is_directory=True)
        self.run_refused(self.command(root=rootlink))

    def test_hardlink_fifo_directory_and_missing_entry_are_rejected(self):
        hard = self.device / "hard.cfg"
        os.link(self.file, hard)
        self.run_refused(self.command(path=hard))
        hard.unlink()

        fifo = self.device / "pipe.cfg"
        os.mkfifo(fifo)
        self.run_refused(self.command(path=fifo))
        self.run_refused(self.command(path=self.device))
        self.run_refused(self.command(filename="missing.cfg"))

    def test_traversal_and_invalid_devices_are_rejected(self):
        self.run_refused(self.command(filename="../backup.cfg"))
        self.run_refused(self.command(filename="test/subdir.cfg"))
        for device in ["0", "-1", "00123", "99999999999999999999", "x"]:
            self.run_refused(self.command(device=device))

    def test_bounds_and_empty_files_are_rejected(self):
        self.run_refused(self.command(minimum="0"))
        self.run_refused(self.command(maximum="1"))
        self.run_refused(self.command(maximum="1073741825"))
        self.file.write_bytes(b"")
        self.run_refused(self.command())

    def test_other_device_directory_cannot_supply_expected_backup(self):
        other = self.root / "124"
        other.mkdir()
        other_file = other / self.file.name
        other_file.write_bytes(self.content)
        self.run_refused(self.command(device="124"))
