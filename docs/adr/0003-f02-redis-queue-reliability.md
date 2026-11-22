# ADR-0003 — F02 queue delivery, retry windows and worker recovery

**Status:** Proposed, no operational queue configured  
**Baseline:** rConfig V8 Core `core-8.3.0` at `121cff1b243794af260f6c5850553b5f1b0af881`  
**Related:** issue [#9](https://github.com/pienzo/rconfig-v8-custom/issues/9), ADR-0001, ADR-0002

## Verified source facts (not assumptions)

- [`config/queue.php`](../../config/queue.php) sets `redis.retry_after=65` **literally**, and `block_for=null`; its default connection is `env('QUEUE_CONNECTION', 'sync')`.
- [`.env.example`](../../.env.example) shows `QUEUE_CONNECTION=redis` and `REDIS_QUEUE_RETRY_AFTER=130`, but `config/queue.php` does **not** read the latter variable. This mismatch needs an upstream-compatibility review before modifying defaults.
- [`config/horizon.php`](../../config/horizon.php) gives `HorizonOne` the `PolicyCompliance`/`rConfigDefault` queues and configurable `HORIZON_PROD_TIMEOUT` (default 60). `HorizonTwo` handles `TaskDownloadQueue`, `TaskSnippetQueue` and `ManualDownloadQueue` and sets `timeout=86400`. These supervisors currently use `tries=1`.
- [`TaskDownloadRun`](../../app/Jobs/TaskDownloadRun.php) dispatches only whitelisted download commands. It is not an extensible arbitrary-command runner, and must stay unchanged for F02.

For a queued job, the Redis reservation expiration and worker execution timeout interact. If the reservation expires before a still-running worker finishes, another consumer may process the job again. Conversely, `tries=1` may prevent intended crash redelivery. Neither is an acceptable implicit F02 default.

## Required future F02 design

1. **Dedicated queue connection/queue:** separate F02 from download jobs and from Horizon's existing `tries=1` supervisors. Choose the actual name, Redis database/namespace and worker topology only after staging audit. Do not expose an arbitrary queue/Artisan command through the Task UI.
2. **Bounded per-job work:** a maximum filesystem scan/read duration must be enforced at the OS/process boundary, not only by a PHP elapsed-time check. Choose a worker `timeout` larger than the enforceable job upper bound.
3. **Reservation margin:** configure the F02 Redis `retry_after` **strictly greater than** the maximum worker timeout, including an adequate recovery margin. Do not assume `REDIS_QUEUE_RETRY_AFTER` affects the current Core config.
4. **At-least-once semantics:** safe retry with transaction-based occurrence idempotency; verified-file receipt acceptance remains **forbidden** until ADR-0002's trusted safe-open identity contract and atomic receipt claims are complete. Notifications will need their own delivery/claim ledger to prevent duplicates.
5. **No automatic release:** test Horizon supervisor restarts, graceful shutdown and abrupt termination, Redis availability loss, stuck-reservation recovery, MySQL reconnect, startup ordering, upgrade queue behavior and rollback on authorized staging. Record exact timeout/tries/retry_after and queue names at that gate.

## CI-only test slice

[`tools/f02/queue`](../../tools/f02/queue/README.md) contains a dev-only `ShouldQueue` job, a subprocess invoking the real `queue:work redis --once` command, and a driver exercising redelivery after a deliberate SIGKILL **following a committed pending state update**. Both attempts run against disposable hosted Redis/MySQL, on a randomized test queue. The job may only write synthetic `pending` metadata. It cannot insert receipts, open files or return backup SUCCESS.

The short **four-second** `retry_after` override exists **only in the test worker's in-memory configuration**, for a deliberately killed single consumer. It is **not** a safe production template and does not prove Horizon-managed operation. The test's CI status is recorded in `docs/PROGRESS.md` only after GitHub confirms completion.

## Alternatives deferred

- Changing the existing Core `redis.retry_after` globally: **rejected for this initial F02 slice** because it risks download jobs and upstream upgrade conflicts.
- Using `sync` queue or invoking F02 inside `TaskDownloadRun`: **rejected**; bypasses operational worker controls and can interfere with native device download tasks.
- Declaring exactly-once delivery from Redis: **rejected**; persist and validate idempotent business state instead.

**Current result:** *No F02 queue connection, Horizon supervisor, job dispatch, notification, receipt insertion or production configuration is enabled by this ADR.* The authorized Linux staging/security review remains required to accept F02 as usable.

## Proposed isolated F02 configuration contract (review-only; not active)

This proposal provides a **review target**, not accepted production values. It changes no application configuration, scheduler, worker, or Docker Compose file. Values must be revised against actual bounded scan/read workloads and an approved staging OS.

| Concern | Proposed isolated F02 behavior | Why/acceptance |
| --- | --- | --- |
| Connection | Add a named Redis queue connection such as `redis_external_backups`, separate from Core's existing `redis` queue connection | A dedicated `retry_after` can be tuned without changing download, notification or `rConfigDefault` reservation behavior. Distinguish a Laravel **queue connection** from a Redis database/namespace; the latter is not automatically isolated. |
| Queue name | `ExternalBackupChecks` on the named connection, dispatched only by a typed F02 job | Do not accept free-form Artisan commands or expose queue names in operator requests. |
| Reservation | **Illustrative** F02 `retry_after=180s` | Must exceed actual worker timeout including margin. Native Core `redis.retry_after=65` remains untouched. |
| Worker timeout | **Illustrative** F02 Horizon supervisor `timeout=90s` | Only valid after each F02 job is demonstrably bounded to less than 90s even under slow filesystem I/O; no PHP-only elapsed-time approximation qualifies. |
| Execution ceiling | **Illustrative** hard per-job limit `<=60s` | Requires OS/process-level bound, finite file count/size, and measured worst-case performance in staging. On unsupported/blocking storage fail closed or split the work. |
| Attempts | Candidate F02 supervisor `tries=3` plus explicit bounded backoff policy | A crash/redelivery must be idempotent; distinguish transport/worker failures from terminal `FAILED_MISSING` after the backup window deadline. Exact retry policy is subject to staging tests. |
| Dispatch | Persist occurrence under MySQL transaction; enqueue **after commit** and reconcile stranded pending work | Lost acknowledgements and DB/Redis outages require repeatable recovery. An occurrence is never a verified file receipt. |
| Observability | Isolated queue latency, retry count, fatal timeout, `failed_jobs` record and occurrence correlation ID | Never log backup contents, filenames containing secrets, tokens or full hashes. |
| Environment | Explicit mapping for both actual `production` and `local` Horizon environment names; no implicit fallback to a Core supervisor | Confirm `APP_ENV` in staging; avoid a queue that is dispatched but never consumed. |
| Activation | Off by default, with no operational F02 job dispatch until ADR-0002 security gate, migrations and staging acceptance | CI-only simulated Redis delivery in PR #20 is not a live backup-validation test. |

**Invariant:** `0 < hard_job_limit < worker_timeout < retry_after` with a measured reservation safety margin, and any configured backoff/attempt limit aligned to occurrence deadlines. The numeric candidates above are **not** approved defaults. Never set Core's existing `HorizonTwo` worker timeout to 90 seconds, nor globally increase its Redis connection reservation: Core downloads may run much longer.

### Implementation sequencing (future issues/PRs)

1. Confirm Horizon behavior for **named Redis queue connections** and effective config loading with Laravel config caching, worker startup, and both environment mappings. Test that Horizon's metadata connection (`horizon.use`) remains separate from the F02 job connection; do not repoint it blindly.
2. In a **separate code PR**, add the disabled F02 connection and isolated supervisor mapping with explicit opt-in. Add config-level tests proving native `redis` remains at 65 seconds, native supervisor queue lists/timeouts unchanged, and unenabled F02 cannot dispatch. Review handling of `after_commit` and any outbox/reconciler needed for lost enqueue.
3. Prove on disposable hosted CI: two consumers, duplicate redelivery, worker SIGKILL before/after DB commit, expired reservation, failed-job recording, Redis network outage, retry exhaustion, and no duplicate notifications. No `SUCCESS`/receipt is permitted yet.
4. On **authorized staging only**, validate OS-level job limits, one- and multiple-worker Horizon supervision, `horizon:terminate`/restart behavior, config-cache deployment, monitoring, degraded Redis recovery, shutdown upgrade sequence and rollback. Measure actual scan duration, then set real job/timeout/retry/backoff values and document results.
5. Review ADR-0002's secure opener/descriptor origin and atomic receipt gate before enabling final success semantics; keep scanner metadata and `pending` separate from accepted receipt state.

### Tests that would falsify this proposal

- A worker runs beyond its reservation before timing out, causing two consumers to work concurrently.
- The F02 queue silently shares the Core 65-second retry window or gets processed by a `tries=1` native supervisor.
- `APP_ENV` lacks the configured F02 supervisor, jobs accumulate without a consumer.
- A crash after DB commit and before queue acknowledgment creates a duplicate receipt/notification.
- Inaccessible, slow or adversarial filesystem IO defeats the claimed hard job bound.
- Restart/upgrade loses queued jobs, incorrectly marks backup success, or violates occurrence deadline semantics.
- Tests only pass by exposing a privileged filesystem opener, editing Core's TaskDownloadRun whitelist, or weakening the safe-open and per-device authorization boundary.

**Decision status remains `Proposed`.** Approval requires an independent queue/operations review and authorized staging validation; no production or test infrastructure has been configured by this document.
