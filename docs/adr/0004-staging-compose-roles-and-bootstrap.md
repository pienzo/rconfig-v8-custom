# ADR-0004 — Isolated source-built Docker Compose staging roles and bootstrap

**Status:** Proposed — requires Docker runtime, security and operator review before activation.  
**Issue:** [#32](https://github.com/pienzo/rconfig-v8-custom/issues/32), under [staging gate #28](https://github.com/pienzo/rconfig-v8-custom/issues/28).  
**Source baseline:** rConfig V8 **Core** `core-8.3.0`, `main` SHA `121cff1b243794af260f6c5850553b5f1b0af881`; custom source-build [PR #30](https://github.com/pienzo/rconfig-v8-custom/pull/30), docs [PR #31](https://github.com/pienzo/rconfig-v8-custom/pull/31).  
**Scope:** personal isolated Debian 13 test VM, synthetic fixtures only. No corporate deployment, OS/SSH connector or external backup ingestion is implied.

## Decision being considered

Introduce an **additional** Compose staging profile built from the locally checked-out `custom` source. Separate `app` (Apache), `db` (MariaDB), `redis`, `horizon`, and **exactly one** `scheduler` process. Keep the current upstream-derived `docker/entrypoint.sh` and `docker/supervisord.conf` intact for their existing integrated deployment and storage-seeding CI workflow.

Do **not** adapt the integrated entrypoint by changing only `command:` in Compose. Docker executes the configured entrypoint first. A staging-specific entrypoint must dispatch only the requested role and never install, migrate or rotate keys during normal service startup.

The first implementation PR should provide the staging Compose and role handling **without starting containers or creating secrets**. The operator reviews the rendered configuration before allowing first runtime activation.

## Evidence in this selected Core commit

- [`docker/entrypoint.sh`](../../docker/entrypoint.sh) waits for a TCP DB port, expects an application `.env`, may generate `APP_KEY`, may set Redis password and edit the integrated Redis config, seeds storage, rewrites ownership, constructs an install marker and invokes `php artisan migrate --force` when it believes installation exists. Its migration failure may be hidden by a `|| echo` branch. These side effects are inappropriate for repeated app/Horizon/scheduler startup.
- [`docker/supervisord.conf`](../../docker/supervisord.conf) starts integrated Redis, Apache, Horizon and scheduler together. Its Redis connectivity probe accepts `NOAUTH`; that is not evidence that Laravel has authenticated.
- [`Dockerfile`](../../Dockerfile) builds Node/Vite assets and PHP/Composer dependencies from source and includes the storage skeleton and bundled templates. It excludes `.env.example` from the build context; an operator-supplied config is therefore mandatory.
- [`config/database.php`](../../config/database.php) reads DB/Redis host, port and Redis password from environment; [`config/queue.php`](../../config/queue.php) leaves native Redis `retry_after=65` unchanged.
- [`config/horizon.php`](../../config/horizon.php) has long-running Core download supervisor settings; the disabled F02 opt-in is not a substitute for validating real Horizon crash/retry behavior.
- [`.env.example`](../../.env.example) is a source **reference**, not a deployable secret. `RCONFIG_F02_QUEUE_ENABLED=false` remains required.

## Boundaries and credentials

1. Use a dedicated Compose project name and **new, disposable volumes**. A private Docker network may connect services; DB/Redis must never publish host ports. Application HTTP may bind only on host loopback (e.g. `127.0.0.1`) for temporary smoke tests. Do not publish Horizon on a separate unguarded port.
2. Runtime isolation must also be enforced **outside** Compose: operator verifies network reachability and egress toward real devices, enterprise infrastructure, SMTP/SFTP/FTP/FTPS and Telegram/Meta endpoints. `internal: true` is an option to test, not a claim of complete isolation when other networks are attached.
3. Generate **synthetic-only credentials** and a stable `APP_KEY` outside Git and image layers; supply application configuration and DB/Redis secrets using a verified mechanism (read-only mounted files/Compose secrets where supported). The implementation PR must specify exactly how MariaDB, Redis and Laravel receive matching credentials, without writing them into logs, arguments, `docker inspect` output or committed examples. A plain `REDIS_PASSWORD=null` is not acceptable for an authenticated standalone Redis baseline.
4. No secrets, tokens, real inventories, file contents or host IPs in GitHub issues, Actions logs or ChatGPT. Do not use a GitHub Actions self-hosted runner with untrusted PR access to the Docker socket.
5. For release purposes, record image digest, source commit and base-image versions. The successful first image was built from an **uncommitted** feature tree, labeled `-dirty`; it does not itself prove clean-commit reproducibility.

## Lifecycle and filesystem contract

| Role | Expected process | Allowed boot-time effects | Not allowed |
| --- | --- | --- | --- |
| `db` | MariaDB 10.11 candidate, persistent data | Official image initializes **fresh** volume using externally supplied secrets | DB exposed on host or schema altered by web/Horizon startup |
| `redis` | Redis 7 candidate, persistent `/data`, AOF, `noeviction` | Load reviewed authenticated config, verify actual auth | Anonymous Redis on routable network or plaintext password in arguments |
| `bootstrap` | Explicit one-shot install/upgrade operator task | Seed missing storage skeleton safely, verify privileges, deliberate schema/key tasks with visible failures | Implicit rerun on app/Horizon/scheduler starts; silently regenerate `APP_KEY` |
| `app` | Apache foreground (native web-serving user model) | Verify preinitialized storage, configured key and database connectivity | Laravel migrations, `v8core:install`, Redis password writes, recursive security-widening chmod |
| `horizon` | `php artisan horizon` under an unprivileged worker identity | Connection check, run job consumer after bootstrap gate | Extra Supervisor/Redis/Apache or implicit F02 activation |
| `scheduler` | `php artisan schedule:work`, one process, unprivileged | Run only approved scheduled jobs after bootstrap | Duplicate scheduler, changing schema/keys at startup |

Share only essential storage between roles. Check UID/GID and deny unintended world traversal of `storage/app/rconfig/data` (directory mode `0750`, config files `0440` according to Core policy). Keep framework/bootstrap compiled caches **local to each container** unless a deliberately tested shared cache is necessary. Verify that persistent installation markers and OAuth keys survive container recreation and that `public/storage` is recreated safely. Never assume the directory tree is seeded merely because an image built successfully.

A first install must explicitly review [`V8CoreInstallCommand`](../../app/Console/Commands/V8CoreInstallCommand.php) and every side effect it invokes, including `APP_KEY`, migrations, Redis security and cron setup. Do not automatically run it as a healthcheck, Compose `post_start` hook or recurring entrypoint. The operator should run it only once after a fresh-volume gate and capture redacted results. On upgrades, schema migrations must be a **separate, fail-loud operation** with a tested full-state recovery path.

## Ordered acceptance tests — none completed by this ADR

1. Static: check Compose schema and interpolated variables, absence of inline real secrets and non-loopback published ports, role entrypoint dispatch, one scheduler, explicit bootstrap-only install/migrate, F02 OFF, no changes to integrated upstream Core startup.
2. Hosted CI: build from pinned source/lockfiles and run the existing Docker storage-seeding regression; add safe staging-config tests that do **not** require enterprise endpoints or privileged self-hosted runners.
3. Authorized VM operator: check Docker daemon storage and VM isolation; validate Compose config using synthetic placeholders **before any `up`**. Confirm the volume identifiers and project name don't collide with other test stacks.
4. Start **only** DB and Redis on new disposable volumes. Verify authenticated access, healthcheck responses, absence of exposed ports and persistence across a controlled restart. Never use `down -v` against non-disposable volumes.
5. Perform deliberate one-time installation on fresh synthetic DB/storage, verify preserved application key and marker, schema and permissions. Start the app only, inspect HTTP/asset response via host loopback and authentication/authorization.
6. Start a **single** Horizon and scheduler. Check queue names, identity, log redaction, shutdown/restarts, Redis outage/recovery, native queue reservation/timeouts and that F02 remains disabled. Do not interpret a Horizon success as F02 admission/receipt approval.
7. Test backup/restore of **consistent** MariaDB + Redis + persistent storage + app key in an isolated Compose project. On failure, keep the old volume set and image digest; do not apply blind schema rollback. Record exact results in `docs/PROGRESS.md`.

## Alternatives and risks

- **Reuse integrated Supervisor for each service:** rejected; duplicates Redis/Horizon/scheduler and does not remove entrypoint side effects.
- **One all-in-one Core container as staging:** preserves existing behavior, but cannot independently validate service faults, migrations, and worker roles.
- **Put passwords directly in committed Compose or shell arguments:** rejected. Keep credential handling reviewable and non-leaking.
- **Global Core queue timeout/retry tuning to suit F02:** rejected for this phase; Core download jobs have materially different lifetimes.
- **Enable F02 scanner or accept backup SUCCESS:** out of scope. See ADR-0002 and ADR-0003.

**Current state:** proposed design only; no Compose implementation, Docker daemon action, container installation, migrations, network changes, credentials or backups performed by this ADR. Next: focused code PR under issue #32, followed by CI and one-command-at-a-time operator staging tests. The GitHub connector still does not provide OS access.
