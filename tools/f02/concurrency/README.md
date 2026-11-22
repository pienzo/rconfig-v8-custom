# F02 — two-process MySQL concurrency test (synthetic only)

**Purpose:** Verify that the existing occurrence-claim and run-state services remain idempotent when **two separate PHP processes** use independent MySQL connections. This is a test harness for [issue #9](https://github.com/pienzo/rconfig-v8-custom/issues/9), not an application entry point.

Files: `run.php` creates disposable Laravel model fixtures in the **CI test database**, starts two independent `worker.php` processes per phase and synchronizes them at a temporary-file barrier. The phases are:

1. Concurrent claim of the **same rule and UTC scheduled instant** → one DB occurrence, same ID for both workers.
2. Concurrent identical `pending` observations → no conflicting metadata or duplicate rows.
3. Concurrent expiration at the deadline → one immutable terminal `failed` result.
4. Concurrent re-claim of the original occurrence → same ID, still failed; no receipt.

The test checks `first_observed_at_utc`, `last_observed_at_utc` and the absence of file receipts. It cleans up its synthetic task/device/rule/run rows and temporary barriers.

### Safety boundaries

- The harness **refuses to execute** unless `APP_ENV=testing`, `F02_SYNTHETIC_CONCURRENCY=1`, and the Laravel DB connection is **MySQL `v8core_test`**.
- It runs **after** the existing Core/Pest tests on the GitHub-hosted MySQL service. No private company database, backup contents, device, network endpoint, queue or external file intake is involved.
- The worker script is not an HTTP route or Artisan command and uses a bounded barrier and 25-second process timeout.
- Its success marker means `TWO_WORKER_CLAIM_PENDING_EXPIRE_RETRY_OK`, **not backup SUCCESS**. No verified-file receipt is inserted.
- CI confirms concurrency against **two OS processes on one hosted MySQL instance**. It does **not** prove queue-worker crash recovery, distributed lock safety across arbitrary deployments, MariaDB compatibility, correct verified-file identity or secure SFTP/FTPS ingestion. Those require the separately authorized staging environment.

The required `Tests (Unit + Fasttests)` GitHub Actions job runs `F02_SYNTHETIC_CONCURRENCY=1 php tools/f02/concurrency/run.php` after Core tests and the Linux `openat2()` prototype suite. Do **not** run the harness on any production database.

**Rollback:** deleting this test-only directory and its CI step via reviewed PR has no database migration or runtime impact.
