"""Synthetic Linux-only adversarial tests for the disabled F02 opener prototype."""

import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[1] / "f02_openat2_probe.c"


class LinuxOpenat2PrototypeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.build_dir = tempfile.TemporaryDirectory(prefix="f02_openat2_build_")
        cls.executable = Path(cls.build_dir.name) / "probe"
        subprocess.run(
            ["gcc", "-std=c11", "-O2", "-Wall", "-Wextra", "-Werror",
             "-o", str(cls.executable), str(SOURCE)],
            check=True,
            capture_output=True,
            text=True,
            timeout=30,
        )

    @classmethod
    def tearDownClass(cls):
        cls.build_dir.cleanup()

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="f02_openat2_test_")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / "inbox"
        self.root.mkdir()
        self.device = self.root / "42"
        self.device.mkdir()
        (self.device / "sample.cfg").write_bytes(b"synthetic contents")
        self.outside = Path(self.temp.name) / "outside.cfg"
        self.outside.write_bytes(b"private fixture")

    def probe(self, root=None, device="42", name="sample.cfg"):
        return subprocess.run(
            [str(self.executable), str(root or self.root), device, name],
            capture_output=True, text=True, timeout=3, check=False,
        )

    def test_allows_only_a_descriptor_observation_not_backup_success(self):
        result = self.probe()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stdout.strip(), "PROBE_OPENED_ONLY_NOT_BACKUP_SUCCESS")
        self.assertNotIn("synthetic contents", result.stdout)

    def test_refuses_symlinked_file(self):
        (self.device / "link.cfg").symlink_to(self.outside)
        self.assertNotEqual(self.probe(name="link.cfg").returncode, 0)

    def test_refuses_symlinked_device_directory(self):
        (self.root / "43").symlink_to(self.device, target_is_directory=True)
        self.assertNotEqual(self.probe(device="43").returncode, 0)

    def test_refuses_symlinked_root(self):
        link = Path(self.temp.name) / "root-link"
        link.symlink_to(self.root, target_is_directory=True)
        self.assertNotEqual(self.probe(root=link).returncode, 0)

    def test_refuses_path_escape_and_nested_path(self):
        for candidate in ("../outside.cfg", "./sample.cfg", "nested/sample.cfg", "../42/sample.cfg", "..", "\\outside.cfg"):
            with self.subTest(candidate=candidate):
                self.assertNotEqual(self.probe(name=candidate).returncode, 0)

    def test_refuses_invalid_device_identifiers(self):
        for value in ("0", "-1", "../42", "4294967296", "abc", "42/..", "", "+42"):
            with self.subTest(value=value):
                self.assertNotEqual(self.probe(device=value).returncode, 0)

    def test_refuses_root_filesystem_as_inbox(self):
        self.assertNotEqual(self.probe(root="/").returncode, 0)

    def test_refuses_fifo_without_blocking(self):
        fifo = self.device / "pipe.cfg"
        os.mkfifo(fifo)
        self.assertNotEqual(self.probe(name="pipe.cfg").returncode, 0)

    def test_refuses_directory_as_file(self):
        (self.device / "directory.cfg").mkdir()
        self.assertNotEqual(self.probe(name="directory.cfg").returncode, 0)

    def test_refuses_hardlinked_file(self):
        os.link(self.device / "sample.cfg", self.device / "other.cfg")
        self.assertNotEqual(self.probe().returncode, 0)
        self.assertNotEqual(self.probe(name="other.cfg").returncode, 0)

    def test_refuses_empty_file(self):
        (self.device / "empty.cfg").touch()
        self.assertNotEqual(self.probe(name="empty.cfg").returncode, 0)

    def test_refuses_oversized_sparse_file(self):
        oversized = self.device / "huge.cfg"
        with oversized.open("wb") as file:
            file.truncate(100 * 1024 * 1024 + 1)
        self.assertNotEqual(self.probe(name="huge.cfg").returncode, 0)

    def test_refuses_a_file_from_another_device(self):
        (self.root / "43").mkdir()
        (self.root / "43" / "other.cfg").write_bytes(b"synthetic")
        self.assertNotEqual(self.probe(name="other.cfg").returncode, 0)

    def test_refuses_missing_path_without_fallback(self):
        self.assertNotEqual(self.probe(name="missing.cfg").returncode, 0)


if __name__ == "__main__":
    unittest.main()
