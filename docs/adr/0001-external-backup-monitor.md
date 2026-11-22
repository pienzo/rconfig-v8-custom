# ADR-0001 — External backup verification in rConfig Core

**Status:** Proposed — F02 foundation in issue [#3](https://github.com/pienzo/rconfig-v8-custom/issues/3), not a deployed feature.  
**Baseline:** upstream `core-8.3.0` at commit `121cff1b243794af260f6c5850553b5f1b0af881`.  
**Scope:** Core only; public open-source project; no real backup files or company data.

## Problem
Some devices upload their own backup to a server via SFTP (or legacy FTP/FTPS), rather than allowing rConfig to fetch configuration using CLI commands. We need a scheduled, auditable **local file arrival check** that never opens a remote CLI connection to the device.

A successful check means **a matching, fresh, regular, sufficiently stable and readable file was observed**. It does *not* prove that the file can be restored to the device.

## Verified integration points

| Component | Verified upstream path | Implication |
| --- | --- | --- |
| Task persistence | [`app/Models/Task.php`](https://github.com/rconfig/rconfig/blob/121cff1b243794af260f6c5850553b5f1b0af881/app/Models/Task.php), [tasks migration](https://github.com/rconfig/rconfig/blob/121cff1b243794af260f6c5850553b5f1b0af881/database/migrations/2019_07_29_062256_create_tasks_table.php) | Reuse Task schedule/associations, use additive extension tables |
| Scheduler | [`app/Console/Kernel.php`](https://github.com/rconfig/rconfig/blob/121cff1b243794af260f6c5850553b5f1b0af881/app/Console/Kernel.php) | Downloads are routed through one fixed command |
| Queue job | [`app/Jobs/TaskDownloadRun.php`](https://github.com/rconfig/rconfig/blob/121cff1b243794af260f6c5850553b5f1b0af881/app/Jobs/TaskDownloadRun.php) | Strict whitelist; **do not** route file checks to the download job |
| API validation | [`StoreTaskRequest.php`](https://github.com/rconfig/rconfig/blob/121cff1b243794af260f6c5850553b5f1b0af881/app/Http/Requests/StoreTaskRequest.php), [`UpdateTaskRequest.php`](https://github.com/rconfig/rconfig/blob/121cff1b243794af260f6c5850553b5f1b0af881/app/Http/Requests/UpdateTaskRequest.php) | Both need explicit validation and permissions |
| Manual run | [`TaskManualRunController.php`](https://github.com/rconfig/rconfig/blob/121cff1b243794af260f6c5850553b5f1b0af881/app/Http/Controllers/Api/TaskManualRunController.php) | Dispatch file-check job by explicit type, not arbitrary Artisan arguments |
| Task Wizard | [`useTasksCommandsStep1.js`](https://github.com/rconfig/rconfig/blob/121cff1b243794af260f6c5850553b5f1b0af881/resources/js/pages/Tasks/WizardPanels/useTasksCommandsStep1.js), [`Step3.vue`](https://github.com/rconfig/rconfig/blob/121cff1b243794af260f6c5850553b5f1b0af881/resources/js/pages/Tasks/WizardPanels/Step3.vue) | Add file-check card and rule editor only after backend is usable |
| Safe paths | [`PathContainmentService.php`](https://github.com/rconfig/rconfig/blob/121cff1b243794af260f6c5850553b5f1b0af881/app/Services/Utilities/PathContainmentService.php) | Use containment as part of defense in depth, not a complete anti-race guarantee |
| FTP config | [`config/filesystems.php`](https://github.com/rconfig/rconfig/blob/121cff1b243794af260f6c5850553b5f1b0af881/config/filesystems.php) | Existing FTP disk config is **not** an inbound SFTP server. Keep intake provisioning separate |

## Decision for MVP
**Option A: extend native Tasks/scheduler** with a distinct, explicitly permitted `external-file` type (preferred), and add isolated check rules/runs. Do not mix remote device commands with upload validation. Keep the normal scheduler, UI and logged task associations.

**Option B:** a separate scheduling system, only if the existing Task model cannot safely handle lifecycle/cron semantics. This costs another scheduler/UI/reporting stack. Defer unless a failing test demonstrates the need.

A proposed external command identifier such as `rconfig:check-external-backup` is **not yet implemented**. Never add arbitrary CLI command execution or simply append it to `TaskDownloadRun::ALLOWED_TASK_COMMANDS`: that class normalizes every accepted task to a **download**.

### Intake and path trust boundary
- Application config defines an absolute intake root; UI users cannot set an arbitrary root path.
- Device subfolder derives from a stable database ID, not from a hostname or UI-provided filesystem path. A device may have multiple rules, but every rule stays inside its one allowed folder.
- Mount the intake area **read-only** into the rConfig application where possible. SFTP/FTPS services run separately with upload-only minimum permissions. Plain FTP is disabled by default and needs a separate risk decision.
- After checking directory containment, enumerate **only direct child entries** (no recursive scanning or path wildcards). Limit file count, elapsed scan time and max filenames. Reject symlinks, devices, pipes, mount surprises, directory replacements and file replacement races. On doubt: return an explicit failure/inconclusive result; never success.
- Read via a secure file-open strategy that detects replacement (e.g., handle-based identity checks before/after and no-follow support where available). `realpath()` by itself is vulnerable to time-of-check/time-of-use races. Exact mechanism depends on the OS adapter and will need staging validation.

### Matching contract (first implementation slice)
[`ExternalBackupFilenameMatcher`](../../app/Services/ExternalBackups/ExternalBackupFilenameMatcher.php) takes one **basename**, pattern and mode. Only `glob` (with `*`, `?`) and raw `regex` (no delimiters) are supported. Matching is always against the **entire** filename. The glob bracket syntax `[abc]` is intentionally treated as a literal sequence until specifically designed and tested.

Reject path separators/control characters, empty names, invalid UTF-8 and filenames/patterns over 255 **bytes**. Reject malformed patterns or unknown modes as configuration errors; bound PCRE match work/depth and do not interpret a regex engine failure as “file missing.” An incomplete upload with a matching filename will *not* be accepted until the later file-inspection stage implements size/freshness/stability rules. **The matcher itself does not inspect files or determine backup success.**

### UTC freshness and two-observation policy (issue #7)

[`ExternalBackupObservationPolicy`](../../app/Services/ExternalBackups/ExternalBackupObservationPolicy.php) evaluates **two metadata snapshots from the same scheduled run**, with explicitly passed integer UTC timestamps. It neither scans/open files nor queries a clock/database. The run window is `[scheduled_at_utc, deadline_utc)`, with at most 300 seconds of explicitly configured clock-skew tolerance at its lower boundary; timestamps after the deadline never qualify.

Rules for a *candidate*:
- The latest candidate in the second scan is selected by descending mtime (then ascending basename), without falling back to an older stable file if the latest upload is still changing.
- It must be at least the configured byte threshold (minimum 1), and mtime must not precede the scheduled run beyond allowed skew or lie in the future beyond allowed skew.
- The **same filename, filesystem device, inode, size, mtime and ctime** must appear in both observations, separated by the configured quiet interval; the second observation must also be at least that interval after its latest mtime/ctime.
- Before the deadline, unmet conditions return `pending` with a reason (`missing`, `stale`, `undersized`, `unstable`, `invalid_timestamp`). At or beyond the deadline they become `failed`; even otherwise valid candidates become `failed/deadline_elapsed`.
- The only ready state is `candidate_requires_safe_open`. **It is not SUCCESS.** A later component must validate an opened file handle safely and consult persistent file-use/run records before making any successful backup claim.

**Limits:** Filesystem mtimes may be copied/spoofed, directory metadata is non-atomic, short in-place writes might evade second-resolution timestamp comparisons, and file identities can be reused. Therefore *two matching snapshots are merely evidence of quietness*, not sufficient proof of transfer completion or file origin. Test DST and elapsed windows at the scheduler/persistence layer with UTC instants. API calls must never use this policy output alone to mark a completed backup.

### State machine and persistence (later slices)
**Proposed**, not present in Core: `external_backup_rules` and `external_backup_check_runs` linked to `tasks` and `devices`, with unique idempotency on `(rule_id, scheduled_for_utc)` and recorded matched-file identity/fingerprint. Decide exact DB schema only after an implementation-specific review.

A scheduled occurrence defines `scheduled_for_utc` and a deadline derived from a configured window/timezone. `PENDING` until deadline while the file is missing or unstable; `SUCCESS` only after a valid fresh and stable match; terminal failures `FAILED_MISSING`, `FAILED_STALE`, `FAILED_INVALID`, `FAILED_ACCESS` with reason codes. Distinguish a rule/configuration error from a missing file. A retry must not create duplicate runs/events or reuse a file consumed by an earlier occurrence. Persist timestamps in UTC, display them in the configured timezone; test DST transitions.

Use bounded polling by the existing Laravel scheduler/queue (not a second unmanaged cron). Verify stability from *two observations* of size and mtime separated by a configured quiet interval, not just “file exists.” File mtime alone is neither proof of completion nor proof of origin; hostname/file date may be supplementary.

The read-only checker must never delete/rename/import uploads. A later independently reviewed enhancement may optionally store external uploads in rConfig configuration history for diff/versioning.

### Security and correctness gates
1. Confirm appropriate rConfig Core API permissions/RBAC for creating and running file-check rules; avoid exposing backup data through broad endpoints.
2. Scanner with symlink/TOCTOU tests; fail-closed access and directory errors; bounded memory/CPU.
3. State machine and DB migrations with unique constraints, concurrent rules/devices, late arrivals, retries, DST and timezone cases.
4. Task Wizard, API validation, scheduler, manual trigger and user-visible outcome reporting.
5. GitHub Actions PHPUnit/Pest, Pint, PHPStan, ESLint, Vitest and production frontend build checks. Hosted CI fixtures must be **synthetic only**.
6. Separate isolated E2E staging with real queue/worker, database and upload delivery **before declaring F02 complete**. No staging VM has been authorized or created.

## VM/CI decision
The **source audit, isolated matcher, DB schema code, UI and most automated tests** can be developed using the GitHub connector and GitHub-hosted Actions. **A VM/Compose staging environment is still recommended before functional acceptance**: CI does not reproduce real FTP/FTPS/SFTP ingestion, OS file permissions, remote mount races, scheduler workers or browser flows. No VM is required for this first PR; later provisioning requires explicit platform/scope approval.

## Rollback
This first slice adds an unused service and unit tests, and is reversible via a reviewed revert PR. Later schema migrations, indexes and background workers will require a separate documented rollback plan. No production deployment is permitted as part of this ADR.
