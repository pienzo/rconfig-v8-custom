# F02 Linux `openat2()` confinement prototype — NOT production code

**Status:** isolated security prototype for [issue #5](https://github.com/pienzo/rconfig-v8-custom/issues/5), complementing [ADR-0002](../../../docs/adr/0002-safe-external-file-open.md). Not packaged, installed, called by Laravel or available through HTTP/queue/scheduler.

## What it tests

The source `f02_openat2_probe.c` builds a small, **unprivileged**, read-only executable that accepts only three *synthetic test* arguments: a trusted absolute inbox root, an integer device ID and a basename. It opens the root directory without following its final symlink, resolves a numeric per-device directory and then opens a file through Linux `openat2(2)` with:

- `RESOLVE_BENEATH`: prevent escape above the directory FD.
- `RESOLVE_NO_SYMLINKS` / `RESOLVE_NO_MAGICLINKS`: refuse symlink and magic-link traversal.
- `RESOLVE_NO_XDEV`: refuse mount/bind-mount crossings **below the anchor**.
- `O_NOFOLLOW`, `O_CLOEXEC`, `O_NONBLOCK`, and read-only file access. Reject non-regular files, hardlinks, zero length and size above 100 MiB.

The command never reads file contents or produces receipts/hashes; its successful marker `PROBE_OPENED_ONLY_NOT_BACKUP_SUCCESS` means **only** that a descriptor was opened and `fstat` checks passed. On unknown kernel/syscall/seccomp failures it **fails closed without `openat()` fallback**.

The Python unittest suite creates disposable synthetic files. It checks legitimate opens and refusal of symlinks, traversal, bad device IDs, FIFO, directories, hardlinks, missing/empty/oversized files and cross-device directory lookups. CI compiles with GCC and strict warnings, then runs the tests in the existing required `Tests (Unit + Fasttests)` check; it needs no network, secrets or privileged mount operations.

## Explicit trust limitations

1. **Not a full secure opener.** The command-line root path is accepted for **testing only**. Production must obtain the root exclusively from protected admin config and open its ancestor chain under a reviewed policy. `O_NOFOLLOW` on the root protects only its *last* component; a symlink among ancestors can still be traversed. The parent chain must remain trusted and protected.
2. An attacker who can move the already-opened directory inode may change namespace relationships. The staging design must define writer/server permissions, immutable/private handoff or another verifiable root trust boundary.
3. `openat2` anchors path resolution **at opening time**, but neither `fstat()` nor a successful open proves the uploader has completed writing, the file will remain immutable, or the backup can be restored. Atomic rename, read-vs-write races, inode recycling and stable snapshot tests remain outstanding.
4. The proof of concept does **not pass a file descriptor to PHP** and is **not integrated with `ExternalBackupOpenedHandleInspector`**. Choosing the IPC/descriptor-handoff mechanism and implementation language for the release requires staging platform review, packaging strategy and threat-model approval. Never parse the probe's stdout as a receipt.
5. `RESOLVE_NO_XDEV` does not check the mount ancestry leading to the trusted root. Mount crossing inside the root cannot be tested without privileged operations, which the hosted CI deliberately does not perform.
6. The prototype does not implement a hard I/O deadline, sandbox, process isolation or concurrency acceptance, and never invokes any production or enterprise path. It uses a hosted Linux kernel that is **not** proof of compatibility with the future staging host.

## Reproduce locally (isolated Linux only)

From the repository root, run:

```bash
python3 -m unittest discover -s tools/f02/linux/tests -p 'test_*.py' -v
```

Requirements: Linux kernel with `openat2()` support (5.6+), `gcc`, and Python 3. No runtime installation is performed. Tests compile into a disposable OS temporary directory and clean up.

**Licensing:** this self-contained prototype uses GPL-3.0-or-later SPDX metadata consistent with the Core `LICENSE.txt`; the repository's conflicting MIT metadata remains a separately tracked release issue.

**Release gate:** review ADR-0002, confirm Linux staging OS/kernel/filesystem, test root-parent permissions and concurrent replacement/mount races, design a secure descriptor handoff, and verify integration with read-only PHP inspection and idempotent receipt claims. **No feature activation or staging provisioning authorized by this document.**
