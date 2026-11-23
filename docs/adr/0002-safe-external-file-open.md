# ADR-0002 — Safe opening of externally uploaded backup files

**Status:** Proposed, security review required.  
**Work item:** [F02 issue #5](https://github.com/pienzo/rconfig-v8-custom/issues/5).  
**Source baseline:** rConfig V8 Core `core-8.3.0`, custom branch after [PR #11](https://github.com/pienzo/rconfig-v8-custom/pull/11).  
**Implementation (updated 2026-11-23):** Native unprivileged Linux `openat2`/SHA-256 verifier is packaged in the custom Dockerfile; a guarded receipt+SUCCESS code path is merged but **all F02 activation flags default to OFF**. This ADR still requires staging trust and upload-completion validation before any operational acceptance.

## Context

The merged [`ExternalBackupDirectoryScanner`](../../app/Services/ExternalBackups/ExternalBackupDirectoryScanner.php) enumerates direct-child filenames under an administrator-provided intake root and returns `lstat()` metadata. The merged [`ExternalBackupObservationPolicy`](../../app/Services/ExternalBackups/ExternalBackupObservationPolicy.php) compares two observations and returns `candidate_requires_safe_open`, never backup SUCCESS.

This does **not** bind those observations to a securely opened file descriptor. Between `lstat()`, `realpath()` and a later `fopen()`, a writer with sufficient directory privileges could replace an entry. Checking a name before opening it is a classic time-of-check/time-of-use (TOCTOU) problem. A quiet timestamp is not proof of a completed upload or of the device that produced it.

The built-in [PHP `fopen()` API](https://www.php.net/manual/en/function.fopen.php) exposes stream opening modes but does not document a parameter equivalent to Linux pathname-resolution restrictions. Do not assume that `fopen()` plus an earlier `lstat()` guarantees race-free no-follow access.

## Nonnegotiable requirements

1. The inbox root is configured by the server operator, never supplied as an arbitrary UI/API path. A device subdirectory is derived only from its stable numeric database ID, never from a hostname, URL or backup-supplied name.
2. The scanner and file opener are **read-only**, have no general write/delete/rename privileges, and do not recursively follow directory trees. The transport daemon/sidecar is isolated from the Laravel worker identity and receives only minimum rights needed for the intended upload flow.
3. The **opened descriptor**, rather than a name observed earlier, is the authority for checking regular-file type, device/inode, size, timestamps and content. Re-stat the descriptor after bounded reading; reject changes, mismatches, links and other uncertainties. Reading the entire file does not prove the vendor payload is restorable.
4. Paths may contain no traversal, special-file, symlink or mount escape. Fail closed if secure kernel-level resolution is not available or cannot be verified in the deployed OS. No fallback to unguarded `fopen()` for hostile-writable directories.
5. Bound filename, file size, file count, descriptor lifetime, read duration and hashing work; refuse FIFO/socket/device files. Ensure the writer cannot trick the reader into opening a blocking special file.
6. A transfer must be stable across a quiet interval and **again** at the open/descriptor boundary. Coordinate with the uploader where possible using a temporary filename and an atomic final rename, but never treat that convention alone as sufficient for security.
7. A final receipt must be claimed with a transactionally unique token derived from a reviewed secure-file identity contract. Reuse, hard-link aliasing and inode recycling need explicit safeguards; inode/mtime/ctime alone do not prove unique arrival.
8. Error results are distinguishable: missing, stale, in-progress, unreadable, malicious/unsafe, duplicate and infrastructure failure. None may be silently converted to `SUCCESS`.

## Preferred Linux adapter — design candidate only

Investigate a **small, least-privilege file-opening boundary** based on Linux `openat2(2)` (or an equivalent OS-specific secure descriptor-based API). The official [Linux manual](https://linuxman7.org/linux/man-pages/man2/openat2.2.html) documents `RESOLVE_BENEATH`, `RESOLVE_NO_SYMLINKS` and `RESOLVE_NO_MAGICLINKS`; `O_NOFOLLOW` alone only controls the final path component. The implementation should anchor resolution to an already securely opened inbox directory descriptor, reject unsafe traversal, and pass only a **bounded, read-only verified descriptor or digest** to the application.

**Not decided:** implementation language, packaging, whether a native PHP extension, a restricted helper or a different fully auditable mechanism best fits the production OS. Avoid enabling PHP FFI or spawning shell scripts purely to bypass this review. A helper would require versioned source, tests, a documented minimal privilege boundary, resource limits and a reproducible installation method; do not introduce a separate privileged always-on service as a default.

A filesystem with different semantics (e.g., network mount, FUSE or non-POSIX storage) may require a different strategy and **must not silently inherit Linux assumptions**. Limit the first supported staging profile to a documented, supported local Linux filesystem unless validation proves more.

## Alternatives

**A. Descriptor-based secure opener (preferred for trusted Linux staging):** verify kernel/API compatibility, open under constrained dirfd, then validate descriptor identity and bounded read/hash. Stronger protection against pathname substitution, but requires an audited adapter and platform gate.

**B. Pure PHP `lstat()` / `realpath()` / `fopen()` / `fstat()` with transport-permission hardening:** may detect common races but is **insufficient by itself** where an untrusted uploader controls directory entries. Do not approve for the initial security boundary without a demonstrated threat-model exception and formal review.

**C. Trusted uploader ingests to an isolated private quarantine and hands rConfig immutable receipts:** potentially appropriate when operational infrastructure already provides a verifiable trust boundary; must be explicitly documented and tested. No such service is assumed to exist.

## First isolated implementation slice — already-opened descriptor inspection

[`ExternalBackupOpenedHandleInspector`](../../app/Services/ExternalBackups/ExternalBackupOpenedHandleInspector.php) accepts a **preopened read-only, seekable regular-file stream** and a metadata observation obtained from the scanner. It deliberately **does not receive or open a pathname**. The code:

- Requires an STDIO stream in read-only mode; validates basename and numeric expected metadata from the prior scanner step.
- Uses `fstat()` to check regular-file type, single hardlink, file size bounds and device/inode/size/mtime/ctime identity against the expected observation **before and after** a bounded read.
- Reads in 64 KiB chunks, calculates SHA-256 without retaining contents, checks expected end-of-file and applies a best-effort elapsed-time limit. A blocking filesystem read cannot be interrupted reliably by this PHP time check.
- Returns only `descriptor_observed_only` evidence, **not** a verified receipt token, a `SUCCESS` flag, or a trustworthy proof of origin. The hash is sensitive-derived metadata and must not be exposed in API or logs.

**Non-goals and unresolved risks:** A PHP stream's `mode`, `stream_type`, and `fstat()` fields **do not certify the secure origin of the descriptor**. A descriptor opened by ordinary `fopen()` on an attacker-controlled path can already have followed a symlink; only the not-yet-implemented trusted opener/OS boundary can prevent that. A file can also be overwritten between observations or during reading while restoring size and coarse-grained timestamps, so this inspection cannot prove an immutable snapshot or a completed transfer. Timing is best effort, not a hard I/O deadline. The service is currently unused by Tasks, jobs and HTTP endpoints.

The included synthetic unit tests confirm ordinary reads, hashing, changing metadata, path replacement (when a replacement descriptor is passed), zero size, hardlinks, invalid handles and unsafe stream modes. **None of those tests substitutes for adversarial staging validation of the opener.** Before deriving a persistent verified-file receipt token or marking a backup successful, implement the audited Linux secure-opening boundary and review ingestion provenance, race resistance and concurrent receipt claims.

## Linux kernel proof of concept (issue #5; NOT selected for deployment)

A small, entirely unused [`openat2()` proof of concept](../../tools/f02/linux/README.md) plus synthetic adversarial tests now explore the preferred kernel confinement flags. The source is [`f02_openat2_probe.c`](../../tools/f02/linux/f02_openat2_probe.c); a Python test suite compiles it with strict GCC warnings and exercises it in a disposable directory. The required GitHub `Tests (Unit + Fasttests)` job includes the prototype tests.

This probe uses `RESOLVE_BENEATH | RESOLVE_NO_SYMLINKS | RESOLVE_NO_MAGICLINKS | RESOLVE_NO_XDEV` for **device subdirectory and candidate basename resolution**, anchored at a root descriptor. Its root directory is opened with `O_PATH | O_DIRECTORY | O_NOFOLLOW`; only the **final component** of that root argument is protected against symlinks. Trust, permission and mount controls for *all ancestor directories* must be designed and verified in the eventual staging environment. It opens read-only with `O_NONBLOCK`, rejects non-regular/hard-linked/empty/oversized files, and never returns file data or verified backup acceptance.

**Key distinction:** the prototype proves a subset of secure pathname resolution, not file immutability, transfer completeness, safe descriptor passing to PHP, or a completed F02 pipeline. There is no Laravel integration or production binary. A successful test prints `PROBE_OPENED_ONLY_NOT_BACKUP_SUCCESS` and cannot count as a backup receipt. The tested hosted Linux environment is not the future staging host.

**Outstanding work before selection:** trusted root-ancestor chain and mount boundary; OS/kernel/permissions verification; descriptor-based hashing with stable snapshot against active writers; hard timeouts; protection against namespace/inode replacement and cross-worker races; secure IPC or safer alternative that integrates `ExternalBackupOpenedHandleInspector` without exposing shell injection, secrets or a privileged ambient capability. The language/packaging and actual installer remain **undecided**.

## Test and approval gates

- Synthetic adversarial uploads: files swapped between metadata check and opening, between opening and reading, and during hashing; symlinks (including races), special files, hard links, nested dirs and mount boundaries.
- Ordinary uploads: empty, oversized, stale, wrong name, partial/slow SFTP/FTPS transfers, atomic rename flow, same payload delivered twice at separate instants, file reused under another name and two different devices.
- True two-worker concurrency with database rule/occurrence locks and verified-receipt uniqueness. Simulate crash/retry between secure open, checksum, DB claim and notification scheduling.
- Verify permission matrix for uploader, Laravel worker and optional adapter. Never expose worker privileges to uploaders or CI jobs from untrusted forks.
- Record exact OS/kernel/PHP/filesystem, adapter SHA, run results and rollback in staging. A GitHub-hosted CI run alone is **not** F02 end-to-end acceptance.

**Current decision (2026-11-23):** keep F02 operationally disabled. The guarded receipt+SUCCESS service and worker wiring (PR #110) and compiled-helper-to-worker synthetic integration (PR #112) are in `custom` with 5/5 CI, but they are **not authorized for operational activation**. Before setting receipt/runtime/queue flags true on any staging or production system, verify the actual trusted upload-finalization boundary, ancestor/mount/permissions contract and adversarial staging behavior. A synthetic descriptor hash alone remains insufficient; no production-ready receipt is claimed.

## Rollback and confidentiality

This ADR changes documentation only; reverting it is a normal reviewed Git PR. No VM, server, credentials, real backups or filesystem mounts are created or modified here. All fixtures must be anonymous, synthetic and safe to publish.

## Native SHA-256 verifier candidate added (#87; not activated)

A follow-up Linux helper `tools/f02/linux/f02_safe_digest.c` is available for review. Unlike the initial open-only prototype, it performs bounded streaming SHA-256 on an `openat2`-confined, regular, single-link file after checking the expected `lstat()` device/inode/size/mtime/ctime. It confirms descriptor and visible basename metadata again after reading and records nanosecond-resolution timestamps in its private JSON output. Laravel's `ExternalBackupSafeDigestAdapter` invokes the compiled binary with array arguments through Symfony Process (no shell) and rejects nonconforming output. The custom Dockerfile can package the binary in an unprivileged container.

This remains an internal **descriptor/hash observation**, not verified upload provenance or a SUCCESS receipt. The root's ancestor chain is outside `openat2` anchoring, hard I/O bounds need staging proof, and a writer retaining file mutation rights can change the file between two observations or after verification. The future acceptance contract must address verified transfer finalization, immutable handoff or equivalent, per-device receipt uniqueness and trusted staging OS. Never send the private SHA-256 to the browser or log. No scheduled F02 runtime enables this code today.
