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
