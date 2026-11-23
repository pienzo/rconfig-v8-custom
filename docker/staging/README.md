# Staging infrastructure — MariaDB and Redis only

**Status:** initial code candidate for [issue #32](https://github.com/pienzo/rconfig-v8-custom/issues/32) / [ADR-0004](../../docs/adr/0004-staging-compose-roles-and-bootstrap.md) (proposed). No application, Horizon, scheduler or bootstrap is started by this Compose file.

`compose.staging.yaml` is intentionally **not** named `compose.yaml`, and both services are under the explicit `infra` profile. Neither database nor Redis publishes a host port. The only network is an isolated Docker internal bridge. This is a **personal synthetic test** setup, not a company deployment definition.

### Configuration contract (operator-only; never commit or paste secret values)

Before using the profile, an authorized operator creates an **external** directory and points `STAGING_SECRETS_DIR` to its absolute path. Do not put this directory inside the Git checkout. It must contain:

- `mariadb_root_password`: non-empty strong, random single-line MariaDB root password.
- `mariadb_app_password`: independent strong, random single-line MariaDB application password.
- `redis.conf`: Redis configuration with `bind 0.0.0.0`, `protected-mode yes`, `port 6379`, `dir /data`, `appendonly yes`, `maxmemory-policy noeviction`, and `requirepass <synthetic random hexadecimal password>`. Do not use whitespace in the password for this first probe. All these values are **configuration instructions**, not actual secrets.

Keep external files owner-accessible and appropriately restricted. MariaDB uses Docker Compose's mounted file-backed secrets. Redis reads its secret file at startup, copies it into an isolated 0700 tmpfs directory with a 0400 redis-owned config file, then invokes the official image entrypoint to drop to the unprivileged Redis user. The original secret file remains outside Git. This avoids requiring the operator to make a Redis config file world-readable to accommodate Docker Compose's bind-mount secret mode. This standalone configuration must be considered **synthetic only**; later Laravel roles need separately provisioned, matching application credentials and a stable `APP_KEY`.

### Verification sequence

1. Operator first inspects the actual network boundary, available Docker daemon storage and Compose interpolation. Validate the configuration with **no services running**:
   `STAGING_SECRETS_DIR=/absolute/external/path docker compose -f compose.staging.yaml --profile infra config --quiet`.
2. Review `docker compose ... config --format json` for `internal: true`, no published ports, correct profile and new volume names. Do not print resolved credentials or the contents of secret files.
3. **Only after authorized runtime review**, start `db` and `redis` with `--profile infra`; confirm both healthchecks, actual authenticated access and persistent volumes across a controlled restart. Docker Compose CI uses disposable fake secrets and a hosted ephemeral runner, not this VM.
4. Stop services without deleting volumes. Never use `docker compose down -v` against persistent or non-disposable data. Do not add application containers until the separate-role bootstrap implementation has been reviewed.

### Scope limits

The application source image from merged [PR #30](https://github.com/pienzo/rconfig-v8-custom/pull/30) is **not** used by this infra-only candidate. The existing integrated Core `docker/entrypoint.sh` and `docker/supervisord.conf` are untouched; they are not safe to reuse for multiple roles by changing `command:` alone. A subsequent PR must add safe role-specific startup and explicit one-time Core bootstrap. F02 remains OFF.

MariaDB `10.11` and Redis `7-alpine` are reviewable **tag candidates**, not image digest pins. Record the exact pulled image digests during staging and perform a security/license review before any distribution.

### Future application role preflight (code only; not enabled)

The image also carries `docker/staging/role-entrypoint.sh`, but **neither this infra-only Compose file nor the default Core image entrypoint invokes it**. It accepts only `app`, `horizon` and `scheduler` with an optional `--check` preflight; the latter never starts a service. Before a real role can start it requires an explicitly provisioned, properly protected `.env`, matching synthetic-only staging endpoints, `RCONFIG_F02_QUEUE_ENABLED=false` and a separately produced `storage/.staging-bootstrap-complete` marker containing `staging-bootstrap-v1`. **No code in this increment produces that marker or provisions the `.env`.** It never installs, runs migrations, seeds files, generates/rekeys APP_KEY, or changes Redis credentials. A later reviewed PR must supply matching app/DB/Redis secrets, safe one-time bootstrap, shared storage and dedicated Compose role definitions. Do not bypass its checks or manually create the marker to force the services to start.

### Future synthetic application `.env` (operator-invoked; not enabled by Compose)

`docker/staging/provision-env.sh` is a **one-shot, root-only** config generator, not a container entrypoint. **Nothing invokes it automatically.** It accepts exactly `--check` (read-only preflight) or `--create` (create once, refusing any existing `.env`). It reads **only** the externally provided synthetic `mariadb_app_password` and Redis `requirepass` from the already-approved root-owned secrets directory. It creates one new random 256-bit Laravel `APP_KEY` once and uses the existing matching DB/Redis credentials; it never rotates passwords or keys, runs an installer/migration, starts Docker, or connects to devices. It does not print secret contents.

Before using it on an authorized personal staging VM, the operator must create another **root-owned mode 0700** directory outside Git, e.g. `/etc/rconfig-v8-custom-staging/config`, and set `STAGING_CONFIG_DIR` to it. `STAGING_SECRETS_DIR` must still point to the approved external `secrets` directory. Both directories must be canonical paths beneath `/etc`, mode 0700, root-owned, and distinct. Input secret files must be non-symlink root-owned mode 0600, with exactly 64 hexadecimal characters for each password. With the two path variables provided, the operator explicitly runs `sh docker/staging/provision-env.sh --check`, reviews the result, then separately runs `sh docker/staging/provision-env.sh --create` **only if authorized**. Do not run these actions from CI against a real server.

The resulting `config/.env` is intentionally **root-owned, group 33 (the image's `www-data` group), mode 0640**, under a mode-0700 root-owned host directory. A later reviewed Compose change must mount **this file only** (not its parent directory), read-only at `/var/www/html/rconfig/.env`, and verify that the container worker can read it. Keep the host parent inaccessible to other users. The generated `.env` forces synthetic DB/Redis endpoints, `APP_DEBUG=false`, offline health checks, a loopback-only intended HTTP URL, and `RCONFIG_F02_QUEUE_ENABLED=false`. Do not edit or overwrite it; its `APP_KEY` must survive restarts and container/image replacement. Back it up together with consistent future DB/Redis/storage state, using approved encrypted and access-controlled storage.

**Still missing:** real one-shot Core schema/storage bootstrap, matching read-only Compose app mounts, app/Horizon/scheduler service definitions, web auth hardening, egress isolation tests and a reviewed restoration plan. The `role-entrypoint.sh` still requires a bootstrap marker which this provisioner deliberately **does not** create. No rConfig app should be launched merely because a synthetic `.env` exists.

### Fresh database/Redis preflight for one-time bootstrap (READ-ONLY)

`docker/staging/bootstrap-preflight.sh --check` is an **operator-explicit, root-only read-only check** for the **future** controlled Core bootstrap (issue #40). It is not an installer and **does not** run `artisan`, migrations, storage seeding, Docker start, admin rotation, or any destructive command. It requires `STAGING_CONFIG_DIR` and `STAGING_SECRETS_DIR` to identify existing distinct, canonical root-owned mode-0700 directories under `/etc`, the new generated `.env` (root:gid33 mode-0640), and matching external DB/Redis secrets. The app key format, synthetic DB/Redis names, F02=false and offline defaults must match.

The preflight examines only the approved existing staging containers `rconfig-v8-custom-staging-db-1` and `rconfig-v8-custom-staging-redis-1`; it rejects unrecognized Compose ownership, unhealthy containers, published host ports, a non-internal staging bridge, or an unexpected third staging container. Inside MariaDB it performs **only** a `SELECT COUNT(*)` on `information_schema.tables` for `rconfig_staging`; it demands **zero** existing tables. Inside authenticated Redis it uses **only** `DBSIZE` for DB0 and DB1 and demands **zero** keys in both. No credential values or `.env` contents are output.

This is a **snapshot**, not a database lock; it must be rechecked while other writers are stopped **immediately before** any separately reviewed bootstrap command. Existing Core migrations can insert a default admin, initialize email settings and clear the Redis `default` queue. The future bootstrap implementation must explicitly handle those effects, rotate the admin before HTTP, seed storage safely and refuse partial reruns. A passing preflight **does not** authorize migrations or serving HTTP. Never treat an empty database as permission to run the integrated `v8core:install` (it regenerates keys/changes Redis) or the Core all-in-one entrypoint. On the personal VM execute at most `--check` for this preflight. The CI tests run only against disposable GitHub-hosted container volumes.

### Administrator credential preparation for future one-time bootstrap (dormant)

`docker/staging/provision-admin-secret.sh` is a separate **operator-invoked** one-time secret generator for issue #40. It accepts `--check` (does not write) or `--create` (creates exactly once). Both paths first require that `bootstrap-preflight.sh --check` passes with the **already-approved private** `STAGING_CONFIG_DIR` and `STAGING_SECRETS_DIR`; in particular MariaDB must have **zero tables**, Redis DB0/DB1 must each have **zero keys**, F02 must be off and Docker must contain only healthy isolated infrastructure containers.

With explicit operator approval, `--create` generates a cryptographically random 256-bit password stored as 64 hex characters plus newline in `$STAGING_SECRETS_DIR/staging_admin_password`. The file is owner root, group 33 (container `www-data`), mode `0640`; its host parent remains root-owned mode `0700`. Creation is atomic and **never overwrites** an existing credential. The value is never displayed or passed as an argument, environment variable or GitHub secret. Do not paste or print it in logs/issues/chat. It must be preserved securely with the application key and future MariaDB/storage state. A future reviewed one-shot bootstrap can mount **this individual file only** read-only into a bootstrap-only container; it must never be mounted into app/Horizon/scheduler or included in a Docker image.

**Critical distinction:** generating this password does **not** change Core's seeded default admin account, does not run a migration, and does not create a bootstrap marker. Core's initial migration still inserts a known seeded admin; the later bootstrap must rotate the account with this protected password and verify it **before** any HTTP listener is made available. `v8core:install` remains prohibited in split staging because it mutates the application key and Redis settings. Nothing invokes this provisioner automatically. CI exercises it only on disposable hosted synthetic credentials and isolated databases.
