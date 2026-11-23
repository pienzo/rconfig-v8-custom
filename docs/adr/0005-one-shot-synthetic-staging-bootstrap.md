# ADR-0005 — Fail-closed one-shot synthetic staging bootstrap

**Status:** Proposed — implementation and live migration are NOT approved by this ADR.  
**Issue:** [#40](https://github.com/pienzo/rconfig-v8-custom/issues/40), parent [#32](https://github.com/pienzo/rconfig-v8-custom/issues/32).  
**Baseline:** `custom` at `977c3152cb20b4c66ec2e98c68a352689c2dbbd3`; upstream Core `core-8.3.0`, commit `121cff1b243794af260f6c5850553b5f1b0af881`.  
**Boundary:** personal Debian 13 **synthetic** staging VM, not corporate or production infrastructure.

## Evidence and current checkpoint (operator-reported, 2026-11-23)

Steps 56–63 completed on the operator's VM. The clean-SHA source image `rconfig-v8-custom:staging-977c3152cb20` reports OCI source revision `977c3152cb20b4c66ec2e98c68a352689c2dbbd3`, image ID `sha256:215bddb1eb38e541964207aae69b0a0a03c0d6c3615d7a237458db0248379eb7`. Static bootstrap Compose contract passed **16/16** checks, as did secrets/mount metadata and the host fresh-state preflight. The operator ran only `bootstrap --check`; it returned `staging bootstrap container preflight passed (no files or database changed)`. Docker created named volume `rconfig-v8-custom-staging_staging_storage` and removed the disposable bootstrap container. The operator independently verified the volume's Core templates, Laravel directories, private config directory mode `0750`, absent bootstrap marker, and a subsequent host preflight passing with MariaDB and Redis healthy and initially empty. No Laravel migrations, administrator rotation, app server, Horizon, scheduler or F02 runtime took place. These observations do not establish an image-byte reproducibility guarantee, network egress isolation, backup/restore recovery or a completed application install.

## Why a separate mutating gate is required

The upstream-integrated `docker/entrypoint.sh` and `v8core:install` can change `APP_KEY`, Redis configuration, permissions, schema or process topology, and must NOT be used for this split-role bootstrap. The initial users migration seeds a **known** administrator and the settings migration seeds an outbound SMTP default; `staging:seal-default-admin` exists only as a dormant follow-up command. A Core migration can clear the Redis `default` queue. MariaDB DDL can commit implicitly: a half-complete `artisan migrate` must not be treated as an atomic transaction or safely replayed. At this checkpoint there are 78 migration files in `database/migrations`, not all side effects have been independently classified. No HTTP listener may start while the default administrator exists.

## Targeted migration source spot-check (not a full audit)

The following selected migrations were directly inspected in `custom` alongside the already known user/settings and Redis queue migrations:

- `2026_11_06_161252_pause_tasks_with_unsupported_task_command.php` can **update task rows** by pausing unsupported task commands and logs warnings. Fresh empty tasks should not have entries, but an existing database is not an acceptable first-install target.
- `2026_10_26_121325_seed_health_check_notification_defaults.php` and `2026_11_19_232254_seed_security_check_notification_defaults.php` insert notification defaults. Their `down()` paths delete those defaults; this is additional evidence against casual rollback on a populated database.
- `2026_05_29_100000_add_mail_transport_security_flags_to_settings_table.php` alters settings columns, demonstrating dependency on the initial settings table.
- `2026_11_22_160000_create_external_backup_check_schema.php` creates F02 rule/run/receipt tables; **this does not enable F02 background runtime**.
- `2026_11_20_072349_clear_unworked_default_queue.php` calls Redis queue `clear('default')` if configured, and cannot reverse that action. Require *empty authenticated Redis* before any migration.

This is a **targeted** review, not verification of all 78 migration files. The implementation gate must classify the remainder before first VM `--apply`.

## Design decision for the NEXT implementation PR (still inactive)

Keep `bootstrap --check` exactly read-only. Introduce a **separate**, opt-in, operator-invoked mutating bootstrap service/entrypoint, with its own explicit Compose profile (proposed name `bootstrap-write`). It must not be selected by the regular app, Horizon or scheduler roles or the default Core entrypoint. No published ports or implicit `depends_on` starts; use only existing internal staging network; source-built image pinned by reviewed SHA and validated image label. Work as UID 1000:GID 33 with read-only image filesystem and individually mounted protected `.env` and bootstrap-only admin credential; writable application storage only in the dedicated named volume and short-lived cache/tmpfs. Never put a secret value in CLI args, Docker environment, debug output or repository.

### Ordered, fail-closed protocol

1. **Before any mutating command:** independently audit the selected Core migrations and their runtime side effects, particularly seeded users/settings, queue clear, network calls and schema dependencies. Confirm the host VM network boundary and absence of routes/egress to real infrastructure, external SMTP and messaging providers. Take and test a **consistent synthetic-only recovery point** covering MariaDB, Redis, storage and the existing external `APP_KEY`/secrets. Record source SHA/image identity. If any proof is missing, refuse apply.
2. Re-run the existing **host** `docker/staging/bootstrap-preflight.sh --check` immediately before the one-shot operation. Recheck *inside* the container that the exact synthetic DB/schema is empty, Redis DB0/DB1 are empty, F02 is off, the file mounts/ownership are valid, required templates exist, storage is writable, the initial bootstrap marker is absent and no app/worker/scheduler container exists. A host preflight alone is a point-in-time check and is **not** a concurrency lock.
3. Before the first migration, create a restrictive **persistent, atomic attempt/lock record** in staging storage. Reject concurrent invocations and all reruns, including after a crash; a leftover attempt without a completion marker means **manual recovery required**, not automatic retry. The implementation must prove failure handling of a crash between lock creation, SQL schema steps, admin sealing, and marker creation.
4. Run the reviewed initial schema migrations explicitly using `php artisan migrate --force --no-interaction`, under the unprivileged bootstrap identity. Capture and propagate a nonzero exit on ANY failure; do not swallow exceptions or call `v8core:install`, `key:generate`, `rconfig:clear-all`, `rconfig:redis-secure` or all-in-one Supervisor. Do not start a web listener, queue workers or scheduler.
5. Verify that the migration repository and expected schema are complete **before** rotating credentials. Run `php artisan staging:seal-default-admin --check` and then `--apply` only when the exact seeded rows match. The already-implemented transactional sealing service updates the admin password/email and disables SMTP defaults. Ensure known default authentication is rejected and the protected replacement credential is valid, without displaying secrets or hashes.
6. Perform read-only postflight: required migrations recorded, private storage/config directories and ownership correct, APP_KEY unchanged, initial administrator no longer present, SMTP forced to local/log mode, F02 still disabled and no external side effects. Persist `storage/.staging-bootstrap-complete` containing exactly `staging-bootstrap-v1` **atomically only after every check succeeds**. It is an operational gate, **not** proof of a secure or recoverable release.
7. Exit and leave DB, Redis and storage in place, with app/Horizon/scheduler **still stopped**. Preserve the attempt record as durable audit evidence or define a separately testable cleanup that cannot erase evidence of an incomplete run. A second invocation must refuse, not repair or overwrite.

### Failures and recovery

- **Before attempt record:** abort without touching application schema or storage contents except known-safe preflight probes.
- **After attempt record, before completion:** never auto-repeat migrations, rotate APP_KEY, roll back populated schema or forge a marker. Keep HTTP/queue/scheduler off. Restore the complete, verified synthetic checkpoint to **new** isolated state or perform a separately reviewed recovery; treat MySQL DDL as potentially nontransactional.
- **After completion:** a second apply must refuse. Upgrade migrations, schema drift, restore testing, and release are separate explicit gates. Preserve stable APP_KEY, external admin secret and consistent DB/Redis/storage across recreation.
- No `docker compose down -v`, recursive chmod/chown over device configs, public SSH/MCP, real device data or production/corporate operations.

## Required test matrix before any VM apply

- Hosted disposable CI: shell syntax/static Compose checks; wrong SHA/identity, root user, invalid mounts, unsafe modes, missing template, F02 enabled, nonempty DB/Redis, unexpected service, preexisting/partial marker and parallel attempt all rejected.
- Disposable integration: fresh migrations + sealed admin + neutralized SMTP + correct marker; fail injected during migration, before/after sealing and before marker; repeated runs and process termination; prove no auto-retry and no duplicate bootstrap.
- Recovery rehearsal: restore consistent MariaDB/Redis/storage/APP_KEY on a **separate** synthetic volume set; prove original state remains undisturbed; verify immutable source image and no HTTP before sealing.
- Only after review and green CI: operator performs an explicit **single** VM apply, one command at a time, with sanitized output and a following independent postflight. App-loopback smoke test, Horizon, scheduler and F02 are **later PRs**.

## Open gates

1. Full source-level classification of selected Core migrations and any hidden startup/service-provider side effects.
2. Exact read-only DB/Redis freshness probe and authenticated postflight implementation.
3. Atomic attempt/marker algorithm, crash injection and recovery test design.
4. VM egress assessment and verified consistent backup/restore procedure.
5. Separate mutating Compose service, code, hosted CI and review. **None is implemented or authorized by this design-only ADR.**
