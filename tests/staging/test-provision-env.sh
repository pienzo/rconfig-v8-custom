#!/bin/sh
# Hosted-CI-only synthetic test. Must run as root on a disposable runner.
set -eu
[ "$(id -u)" -eq 0 ] || { echo 'Test requires disposable runner root' >&2; exit 1; }
script=./docker/staging/provision-env.sh
role_script=./docker/staging/role-entrypoint.sh
sh -n "$script"
base=$(mktemp -d /etc/rconfig-v8-staging-ci-XXXXXXXX)
trap 'rm -rf -- "$base"' EXIT
trap 'exit 1' HUP INT TERM
umask 077
mkdir -m 0700 "$base/secrets" "$base/config"
export STAGING_SECRETS_DIR="$base/secrets" STAGING_CONFIG_DIR="$base/config"
openssl rand -hex 32 > "$STAGING_SECRETS_DIR/mariadb_app_password"
redis_pw=$(openssl rand -hex 32)
{
    printf '%s\n' 'bind 0.0.0.0' 'protected-mode yes' 'port 6379' 'dir /data' 'appendonly yes' 'maxmemory-policy noeviction'
    printf 'requirepass %s\n' "$redis_pw"
} > "$STAGING_SECRETS_DIR/redis.conf"
chmod 0600 "$STAGING_SECRETS_DIR/"*

# Explicit guard checks, before any config is generated.
if STAGING_CONFIG_DIR="$base/config/not-existing" sh "$script" --check >/dev/null 2>&1; then
    echo 'Missing directory was accepted' >&2; exit 1
fi
chmod 0755 "$STAGING_CONFIG_DIR"
if sh "$script" --check >/dev/null 2>&1; then
    echo 'Loose directory mode was accepted' >&2; exit 1
fi
chmod 0700 "$STAGING_CONFIG_DIR"
cp "$STAGING_SECRETS_DIR/mariadb_app_password" "$base/app-password-original"
printf 'APP_DEBUG=true\n' >> "$STAGING_SECRETS_DIR/mariadb_app_password"
if sh "$script" --check >/dev/null 2>&1; then
    echo 'Multiline MariaDB secret injection was accepted' >&2; exit 1
fi
cp "$base/app-password-original" "$STAGING_SECRETS_DIR/mariadb_app_password"
chmod 0600 "$STAGING_SECRETS_DIR/mariadb_app_password"
cp "$STAGING_SECRETS_DIR/redis.conf" "$base/redis-conf-original"
printf 'requirepass %s\n' "$redis_pw" >> "$STAGING_SECRETS_DIR/redis.conf"
if sh "$script" --check >/dev/null 2>&1; then
    echo 'Duplicate Redis requirepass was accepted' >&2; exit 1
fi
cp "$base/redis-conf-original" "$STAGING_SECRETS_DIR/redis.conf"
chmod 0600 "$STAGING_SECRETS_DIR/redis.conf"

sh "$script" --check
test ! -e "$STAGING_CONFIG_DIR/.env"
sh "$script" --create
test "$(stat -c '%u:%g:%a' "$STAGING_CONFIG_DIR/.env")" = '0:33:640'
grep -Fxq 'RCONFIG_F02_QUEUE_ENABLED=false' "$STAGING_CONFIG_DIR/.env"
grep -Fxq 'APP_DEBUG=false' "$STAGING_CONFIG_DIR/.env"
grep -Fxq 'MAIL_MAILER=log' "$STAGING_CONFIG_DIR/.env"
test "$(grep -Ec '^MAIL_MAILER=' "$STAGING_CONFIG_DIR/.env")" -eq 1
grep -Fxq 'HEALTHCHECK_OFFLINE_MODE=true' "$STAGING_CONFIG_DIR/.env"
grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/]{43}=$' "$STAGING_CONFIG_DIR/.env"
db_read=$(sed -n 's/^DB_PASSWORD=//p' "$STAGING_CONFIG_DIR/.env")
test "$db_read" = "$(cat "$STAGING_SECRETS_DIR/mariadb_app_password")"
redis_read=$(sed -n 's/^REDIS_PASSWORD=//p' "$STAGING_CONFIG_DIR/.env")
test "$redis_read" = "$redis_pw"
unset db_read redis_read redis_pw

# Confirm the completed .env is accepted by the INACTIVE role preflight.
mkdir -m 0700 -p "$base/app/storage" "$base/app/bootstrap/cache"
printf 'staging-bootstrap-v1\n' > "$base/app/storage/.staging-bootstrap-complete"
cp "$STAGING_CONFIG_DIR/.env" "$base/app/.env"
chmod 0600 "$base/app/.env"
RCONFIG_STAGING_TEST_APP_DIR="$base/app" sh "$role_script" app --check

# No second run may overwrite the stable APP_KEY or application credentials.
cp "$STAGING_CONFIG_DIR/.env" "$base/original-env"
if sh "$script" --create >/dev/null 2>&1; then
    echo 'Provisioner overwrote existing .env' >&2; exit 1
fi
cmp -s "$STAGING_CONFIG_DIR/.env" "$base/original-env"

printf '%s\n' 'Synthetic config generation/guard/no-overwrite: OK'
