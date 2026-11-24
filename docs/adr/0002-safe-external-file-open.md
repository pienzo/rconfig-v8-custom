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

## 2026-11-24 — SFTP evidence and live-session exclusion candidate (#165)

PR #164 proves the complete synthetic SFTP-to-Horizon receipt path after a dedicated uploader is quiesced; it does not authorize operational activation. The next hosted-only experiment uses a forced external OpenSSH SFTP subsystem within a root-controlled chroot. The wrapper holds an exclusive advisory flock on a protected inode throughout the SFTP session; the test publisher uses that same lock. A renamed file in an active session must not be published; a second session waits during publication and can only modify quarantine afterwards. The trusted test controller knows its batch exited successfully. The listener stays up. No changes to the application, staging deployment or default flags.

**Candidate only:** flock is advisory and local-filesystem-specific; all possible writer paths must participate and wrapper termination/child lifetime, process signals, mount and lock identity must be validated. A completed session or lock acquisition does not establish that a device finished a valid backup; a separately trusted completion contract is still needed, including failed transfers, reconnects and concurrent device isolation. This experiment serializes one uploader's sessions and does not establish production scalability, FTP/FTPS semantics or live multi-device operation. Do not install the test chroot or enable F02 on a real host based on this experiment.

## Multi-device exclusion experiment (#167)

PR #166 passed the live-session single-uploader experiment on hosted CI, including the jailed subsystem preflight. The next candidate keeps one listener with two independent principals, keys, jails and lock inodes. A blocked reconnect for A must not prevent B completing a transfer and protected publication under B's own lock. Both devices use the same basename but distinct synthetic bytes and numeric final directories. Cross-key authentication and cross-jail access must fail; subsequent A activity must leave B's published inode and bytes unchanged. This is synthetic transport/descriptor evidence only, not a production publisher or evidence of trusted completion, interrupted transfer handling, scalability or multi-device Laravel receipt processing.

## Interrupted SFTP write experiment (#169)

PR #168 confirmed two independent uploader identities and locks with a single live listener, including two native descriptors and isolated publication. Candidate #169 uses a real authenticated SSH subsystem and bounded SFTP v3 packets: OPEN, acknowledged WRITE, then terminate only the synthetic client before CLOSE. Test both an incomplete prefix and complete-looking bytes. Require session lock held during upload, released after disconnect, zero published output, explicit controller-known successful batch before publication, and exact fixture digest checked before atomic rename. Retry through the existing real SFTP path while the listener stays alive. The completion flag and expected digest are test-controller oracles only, not an operational device completion protocol; disconnect detection does not prove what happened on an arbitrary real device, and no runtime admission is granted.

## Optional completion contract experiment (#171)

PR #170 proved interrupted SFTP refusal and recovery using a synthetic controller oracle, not a deployable completion mechanism. MASTER F02 is passive and supports autonomous devices without requiring rConfig to connect to them. **Do not mandate a sidecar or nonce-capable client across the inventory.** Candidate managed-sender/authorized adapter mode uses a bounded manifest uploaded last by atomic rename, scoped by authenticated device identity, server-controlled occurrence challenge and time window, fixed filename, byte count and SHA-256. Validate under the per-device lock and compare descriptor-copied bytes before protected publication. A root-only exclusive one-use claim before rename fails closed on crash but is NOT a transactional durable receipt ledger. No actual device keys, data or deployment.

For ordinary devices, a documented and staging-tested native finalization convention (e.g. successful transfer into temporary name followed by server-observable commit rename by a trusted sender) or an independently authorized ingestion service may be suitable; verify exact protocol behavior including abort/reconnect and no post-commit mutation. FTP/FTPS require their own adapter/transfer lifecycle evidence, FTP cleartext is off by default. Stable size, mtime, filename, disconnect or CLOSE alone do not prove complete delivery; unsupported sources cannot be automatically admitted as SUCCESS. A manifest proves only consistency with the authenticated sender's declaration, not vendor-format validity or restorability, nor honesty of a compromised sender. No selection of production completion mode until actual inventory/capabilities, trust policy, atomic claim recovery and authorized staging evidence are available.

## Completion claim process-crash experiment (#173)

PR #172 passed the optional managed-sender completion manifest and one-use synthetic claim. Candidate #173 kills a forked synthetic publisher immediately after durable claim creation and before atomic rename, then in a separate authorized occurrence after rename and directory fsync but before acknowledgement. Replays must be refused in both cases; pre-rename leaves only protected pending evidence, which the fixture operator explicitly discards while retaining the spent nonce; post-rename preserves the verified final inode and bytes. A new nonce is required for the separate occurrence. No automatic promotion or retry, no actual backup SUCCESS, no power-loss guarantee, no persistent recovery ledger. Pending artifacts must be made invisible to the production reader (including broad glob/regex rules), not merely hidden by a dot name; the test-only .pending in the protected final directory is not a deployable layout. An operational recovery design must bind occurrence, claim, file identity and durable journal, classify ambiguous states and verify crash/power-loss behavior on the authorized filesystem.

## Private pending publication layout experiment (#175)

PR #174 passed process-crash boundaries with explicit discard; its pending artifact was in the numeric reader directory. Source audit at that merge: `app/Services/ExternalBackups/ExternalBackupDirectoryScanner.php` enumerates direct numeric device directory children via FilesystemIterator::SKIP_DOTS; `ExternalBackupFilenameMatcher.php` supports glob * and regex .* including dot-prefixed ordinary basenames. A dot prefix alone is insufficient. Candidate #175 uses a root:root 0700 nonnumeric sibling directory on the same publication mount, one per device, including synthetic exported mount. Rename from private directory to numeric reader directory, no copy fallback, fsync both directories. Reader UID actually enumerates numeric folder with broad patterns and is denied traversal/direct open of private pending file before claim and after publisher SIGKILL. Positive descriptor validation remains. The private directory name itself may be listed at publication root; its contents cannot be traversed by reader and numeric-device scanner never selects it. This is an OS-level synthetic test, not invocation of the PHP scanner against a live crashed publisher. No production mount or activation; persistent journal/recovery, mount topology/ACL validation and actual device trust remain open.

## Persisted claim evidence and restart classification (#177)

PR #176 validated private pending isolation before claim and after publisher SIGKILL. Candidate #177 replaces empty synthetic claim markers with bounded root-owned records binding device, occurrence nonce, basename, byte count, digest and copied descriptor device/inode/mtime. A fresh interpreter receives only the nonce, uses protected fixed directories and reads the persisted record under the session lock. It classifies a matching private pending inode as pending reconciliation, a matching final inode as published evidence only, and missing, conflicting or mismatched evidence as ambiguous. No automatic retry, promotion, deletion or SUCCESS by the inspector. The controller deliberately exercises missing, conflicting and substituted inode cases using synthetic files. Rename changes ctime; mtime and inode are compared with digest, not ctime. This is a local process-restart experiment, not a database transaction or proof of power-loss durability; actual grants, durable occurrence state, retention, filesystem/mount topology, and operator recovery remain to be designed/approved. No new production service or runtime configuration.

## Explicit pending recovery experiment (#179)

PR #178 passed read-only restart classification. Candidate #179 adds the original grant window to the synthetic persisted record and a separate explicit test-controller recovery invocation in a fresh interpreter. Under the same per-device lock, it checks the protected record and private copied inode, refuses unknown/conflicting/expired evidence, and promotes the verified inode by atomic rename with both directory fsyncs. Reconnection deliberately changes quarantine before recovery; recovery uses only protected bytes. The same claim remains spent and unchanged, a repeated recovery returns already published evidence without another rename. Default inspection is read-only. This is not an operational authorization mechanism: the fixture environment flag represents explicit controller approval, not operator authentication or RBAC. No real device contract, automatic retry, transactional DB/filesystem commit, power-loss guarantee, or backup SUCCESS. Preserve existing discard and read-only probes.

## Multi-device scheduled receipt experiment (#181)

PR #180 validated explicit synthetic pending recovery. Candidate #181 connects the accepted live two-device transport to actual Laravel scheduler and Horizon using real synthetic DB device IDs, distinct content, two protected inodes in one exported root, and a dedicated test Redis DB pair. Queue both actual scheduler events before starting the existing single-worker F02 supervisor, verify exact device/run receipt identities and tokens derived independently from native evidence and expected fixture hashes, replay both events and confirm no duplicate receipts or notifications. This proves concurrent pending occurrences if green, not parallel worker execution or a production completion contract. Existing single-device and transport tests retained; no runtime flags changed outside disposable CI.
