# F02 — real Redis `queue:work` redelivery, CI-only

**Status:** isolated, unpublished-runtime test for [issue #9](https://github.com/pienzo/rconfig-v8-custom/issues/9). It deliberately does **not** add an operational F02 job or a new Horizon supervisor.

## Verified Core baseline and risk

The selected Core 8.3.0 source has `config/queue.php` → `connections.redis.retry_after = 65` seconds, hard-coded, even though `.env.example` documents `REDIS_QUEUE_RETRY_AFTER=130`. Thus that environment variable **does not currently control** Laravel Redis reservation expiry in this source. `config/horizon.php` has Core supervisor `HorizonTwo` with `timeout = 86400`, processing download/snippet/manual queues, while `HorizonOne` uses `HORIZON_PROD_TIMEOUT` (default 60s). These settings are **not changed by the test**. They require a separate policy/audit before new F02 queue workers are introduced, because a reservation shorter than a worker's execution limit can enable duplicate processing. Current Core supervisors also use `tries = 1` and must not be reused without evaluating the desired retry semantics.

**Never append F02 commands to `TaskDownloadRun::ALLOWED_TASK_COMMANDS`.** F02 will require a dedicated, strictly routed queue/worker policy and a well-tested `retry_after > timeout` margin.

## What this test actually exercises

The hosted CI provides a temporary MySQL `v8core_test` database and a temporary Redis service. The test driver [`redelivery.php`](redelivery.php) creates synthetic `Task`, `Device`, `ExternalBackupRule`, and `ExternalBackupCheckRun` rows. It then enqueues [`F02RedisCrashProbeJob`](../../../tests/Support/ExternalBackups/F02RedisCrashProbeJob.php) on a **random `f02_ci_<hex>` queue**.

The first actual PHP `queue:work redis --once` child starts, executes the existing `ExternalBackupRunStateService::recordPending()` transaction and signals that it has committed. The parent deliberately **SIGKILLs only that child** before Laravel has acknowledged/deleted the Redis message. A second PHP `queue:work` process starts after the short test reservation expires and must receive the same job as **attempt 2**. Its identical `recordPending()` call must leave the committed database result unchanged. The driver verifies one run, stable observation timestamps and `updated_at`, an empty synthetic Redis queue and **zero receipts**.

Success marker: `F02 REAL_REDIS_QUEUE_WORKER_REDELIVERY_OK (NO_BACKUP_SUCCESS)`.

### Strict isolation

- Requires `GITHUB_ACTIONS=true`, `APP_ENV=testing`, `F02_SYNTHETIC_REDIS_QUEUE=1`, MySQL database exactly `v8core_test`, and Redis host exactly `127.0.0.1`. The worker, job and driver all check relevant gates.
- The real Core Redis connection config is **not rewritten**. The test worker overrides only its own in-memory `retry_after=4` seconds; the intentionally stopped first process is the **only** consumer during that short reservation. The shortened reservation is a test expedient and **intentionally violates the required production timeout margin**; never use it in a normal deployment.
- No Horizon master, scheduler, Task Wizard, customer device, external SFTP/FTPS server, backup file, token, public API or production queue is touched. The test class lives under Composer's **development-only `Tests\\` namespace**.
- All subprocesses have finite limits; the driver only signals the child it created. The driver deletes only its own randomly named queue keys and synthetic rows.
- Hosted Redis and MySQL testing does not validate a multi-host deployment, long-running F02 scan workloads, Horizon supervisor restarts, failed-job handling, exactly-once notification dispatch, restorable contents or secure file receipts.

### Approval and next gates

Use this result to narrow the *queue delivery* question, not to declare F02 operational. Separately review production Core queue defaults against `.env.example`, enforce isolated queue timeouts/tries after identifying actual runtime requirements, and test Horizon-managed process crashes and deploy/upgrade behavior in an authorized staging VM. Secure descriptor opening and trustworthy receipt token/atomic claims (ADR-0002) remain blocking.

This directory is CI-only; removal via a reviewed PR has no production migration or functional effect.
