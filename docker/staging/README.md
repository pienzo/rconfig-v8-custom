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
