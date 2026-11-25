#!/bin/sh
# Operator-explicit synthetic staging bootstrap IMAGE preflight.
# Default --check remains read-only. Explicit --apply delegates to the
# separate reviewed one-shot driver; never starts web or worker services.
set -eu

fail() {
    printf 'staging bootstrap container refused: %s\n' "$1" >&2
    exit 1
}

[ "$#" -eq 1 ] || fail 'expected --check or --apply'
action=$1
case "$action" in
    --check) ;;
    --apply)
        [ "${RCONFIG_STAGING_BOOTSTRAP_APPROVAL:-}" = operator-reviewed-v1 ] ||
            fail 'explicit operator approval missing'
        ;;
    *) fail 'expected --check or --apply' ;;
esac

app_dir=/var/www/html/rconfig
admin_file=/run/secrets/staging_admin_password

# Synthetic GitHub-hosted test paths are limited to the read-only check.
if [ -n "${RCONFIG_STAGING_TEST_APP_DIR:-}" ] || [ -n "${RCONFIG_STAGING_TEST_ADMIN_FILE:-}" ]; then
    [ "$action" = '--check' ] || fail 'test path override forbidden for apply'
    [ "${GITHUB_ACTIONS:-}" = true ] &&
        [ "${RCONFIG_STAGING_TEST_MODE:-}" = synthetic ] ||
        fail 'test path override is restricted to hosted synthetic CI'
    [ -n "${RCONFIG_STAGING_TEST_APP_DIR:-}" ] &&
        [ -n "${RCONFIG_STAGING_TEST_ADMIN_FILE:-}" ] ||
        fail 'both test paths are required'
    app_dir=$RCONFIG_STAGING_TEST_APP_DIR
    admin_file=$RCONFIG_STAGING_TEST_ADMIN_FILE
else
    [ "$(id -u)" -eq 1000 ] && [ "$(id -g)" -eq 33 ] ||
        fail 'bootstrap container must run as unprivileged uid1000:gid33'
    [ "${IS_DOCKER:-}" = true ] || fail 'expected source-built Docker image'
fi

[ "${RCONFIG_STAGING_BOOTSTRAP:-}" = core-synthetic-v1 ] ||
    fail 'synthetic-only bootstrap identity missing'
[ -z "${APP_KEY:-}" ] && [ -z "${DB_PASSWORD:-}" ] &&
    [ -z "${REDIS_PASSWORD:-}" ] ||
    fail 'secret environment overrides are forbidden'
[ "${RCONFIG_F02_QUEUE_ENABLED:-false}" = false ] ||
    fail 'F02 environment override forbidden'
[ "${APP_DEBUG:-false}" = false ] || fail 'debug environment override forbidden'
[ -z "${MAIL_MAILER+x}" ] || fail 'mail transport environment override forbidden'

env_file=$app_dir/.env
storage=$app_dir/storage
cache=$app_dir/bootstrap/cache
marker=$storage/.staging-bootstrap-complete
[ -f "$env_file" ] && [ ! -L "$env_file" ] && [ -r "$env_file" ] ||
    fail 'mounted Laravel .env missing, symlinked or unreadable'
[ "$(stat -c '%u:%g:%a' -- "$env_file")" = '0:33:640' ] ||
    fail 'Laravel .env must be root:gid33 mode0640'
[ -f "$admin_file" ] && [ ! -L "$admin_file" ] && [ -r "$admin_file" ] ||
    fail 'mounted bootstrap-only administrator secret missing or unreadable'
[ "$(stat -c '%u:%g:%a' -- "$admin_file")" = '0:33:640' ] ||
    fail 'administrator secret must be root:gid33 mode0640'
[ "$(wc -c < "$admin_file")" -eq 65 ] &&
    LC_ALL=C grep -Eq '^[0-9a-fA-F]{64}$' "$admin_file" ||
    fail 'administrator secret is not the expected 64-hex format'

require_exact() {
    key=${1%%=*}
    [ "$(grep -Ec "^$key=" "$env_file")" -eq 1 ] ||
        fail "missing or duplicate config setting: $key"
    grep -Fxq -- "$1" "$env_file" ||
        fail "unexpected config setting: $key"
}

for setting in \
    'APP_ENV=production' 'APP_DEBUG=false' 'MAIL_MAILER=log' \
    'DB_CONNECTION=mysql' 'DB_HOST=db' 'DB_PORT=3306' \
    'DB_DATABASE=rconfig_staging' 'DB_USERNAME=rconfig_staging' \
    'REDIS_HOST=redis' 'REDIS_PORT=6379' 'REDIS_DB=0' \
    'REDIS_CACHE_DB=1' 'QUEUE_CONNECTION=redis' \
    'RCONFIG_F02_QUEUE_ENABLED=false' 'HEALTHCHECK_OFFLINE_MODE=true' \
    'HEALTHCHECK_AIR_GAPPED=true' 'SECURITY_CHECK_SCHEDULE=false'; do
    require_exact "$setting"
done

[ "$(grep -Ec '^APP_KEY=' "$env_file")" -eq 1 ] &&
    grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/]{43}=$' "$env_file" ||
    fail 'APP_KEY missing, duplicated or invalid'

[ -d "$storage" ] && [ ! -L "$storage" ] && [ -w "$storage" ] ||
    fail 'persistent Laravel storage is missing, symlinked or not writable'
[ -d "$cache" ] && [ ! -L "$cache" ] && [ -w "$cache" ] ||
    fail 'bootstrap/cache tmpfs is missing or not writable'
[ ! -e "$marker" ] && [ ! -L "$marker" ] ||
    fail 'bootstrap already completed or unexpected marker exists'
[ -d "$storage/app/rconfig/data" ] &&
    [ "$(stat -c '%a' -- "$storage/app/rconfig/data")" = 750 ] ||
    fail 'device-config directory must be mode0750'
for template in default.yml ios-ssh-enable.yml ios-ssh-noenable.yml \
    ios-telnet-enable.yml ios-telnet-noenable.yml; do
    [ -s "$storage/app/rconfig/templates/$template" ] &&
        [ -f "$storage/app/rconfig/templates/$template" ] &&
        [ ! -L "$storage/app/rconfig/templates/$template" ] ||
        fail "bundled Core template missing: $template"
done

printf 'staging bootstrap container preflight passed (no files or database changed)\n'

if [ "$action" = '--apply' ]; then
    cd /var/www/html/rconfig || fail 'application directory unavailable'
    exec php /var/www/html/rconfig/docker/staging/bootstrap-apply.php --apply
fi
