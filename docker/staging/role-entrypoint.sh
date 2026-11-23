#!/bin/sh
# Opt-in staging-only role dispatcher. This NEVER installs, migrates,
# rewrites keys, seeds storage, changes permissions or starts embedded Redis.
set -eu

fail() {
    printf 'staging role refused: %s\n' "$1" >&2
    exit 1
}

[ "$#" -ge 1 ] && [ "$#" -le 2 ] || fail 'expected role [--check]'
role=$1
mode=${2:-}

case "$role" in
    app|horizon|scheduler) ;;
    *) fail 'invalid role' ;;
esac
case "$mode" in
    ''|--check) ;;
    *) fail 'invalid option' ;;
esac

# Test-only path substitution: cannot be used to launch a service.
app_dir=/var/www/html/rconfig
if [ -n "${RCONFIG_STAGING_TEST_APP_DIR:-}" ]; then
    [ "$mode" = '--check' ] || fail 'test path override cannot launch services'
    app_dir=$RCONFIG_STAGING_TEST_APP_DIR
fi

env_file=$app_dir/.env
storage=$app_dir/storage
bootstrap_marker=$storage/.staging-bootstrap-complete

# Do not start when the separate, audited, one-time bootstrap has not run.
[ -f "$bootstrap_marker" ] && [ ! -L "$bootstrap_marker" ] || fail 'one-time staging bootstrap marker missing'
[ "$(cat "$bootstrap_marker")" = 'staging-bootstrap-v1' ] || fail 'invalid bootstrap marker'
[ -f "$env_file" ] && [ ! -L "$env_file" ] && [ -r "$env_file" ] || fail 'runtime .env is absent, symlinked, or unreadable'

# Require no write access by group/other, and no access at all by other.
mode_bits=$(stat -c '%a' "$env_file") || fail 'cannot inspect env mode'
[ $((0$mode_bits & 0027)) -eq 0 ] || fail 'runtime .env permissions too open'

# Validate the *file* without sourcing an untrusted .env as shell code or
# displaying application key/password contents. An external provisioner must
# supply an independent, stable key and matching DB/Redis credentials.
require_exact_line() {
    key=${1%%=*}
    [ "$(grep -Ec "^${key}=" "$env_file")" -eq 1 ] || fail "required setting duplicate or missing: $key"
    grep -Fxq -- "$1" "$env_file" || fail "required setting invalid: $key"
}
require_pattern() {
    key=$2
    [ "$(grep -Ec "^${key}=" "$env_file")" -eq 1 ] || fail "required setting duplicate or missing: $key"
    grep -Eq -- "$1" "$env_file" || fail "invalid setting: $key"
}

require_exact_line 'APP_ENV=production'
require_exact_line 'APP_DEBUG=false'
require_exact_line 'MAIL_MAILER=log'
require_exact_line 'DB_CONNECTION=mysql'
require_exact_line 'DB_HOST=db'
require_exact_line 'DB_PORT=3306'
require_exact_line 'DB_DATABASE=rconfig_staging'
require_exact_line 'DB_USERNAME=rconfig_staging'
require_exact_line 'REDIS_HOST=redis'
require_exact_line 'REDIS_PORT=6379'
require_exact_line 'QUEUE_CONNECTION=redis'
require_exact_line 'CACHE_DRIVER=redis'
require_exact_line 'SESSION_DRIVER=redis'
require_exact_line 'RCONFIG_F02_QUEUE_ENABLED=false'
require_pattern '^APP_KEY=base64:[A-Za-z0-9+/]{43}=$' 'APP_KEY'
require_pattern '^DB_PASSWORD=[[:xdigit:]]{64}$' 'DB_PASSWORD'
require_pattern '^REDIS_PASSWORD=[[:xdigit:]]{64}$' 'REDIS_PASSWORD'

# Docker-supplied environment overrides win over Dotenv. Fail closed if they
# could contradict the on-disk synthetic-only, F02-disabled contract.
[ -z "${APP_KEY:-}" ] || fail 'APP_KEY environment override forbidden'
[ -z "${DB_PASSWORD:-}" ] || fail 'DB_PASSWORD environment override forbidden'
[ -z "${REDIS_PASSWORD:-}" ] || fail 'REDIS_PASSWORD environment override forbidden'
[ "${RCONFIG_F02_QUEUE_ENABLED:-false}" = false ] || fail 'F02 environment override forbidden'
[ "${APP_DEBUG:-false}" = false ] || fail 'debug environment override forbidden'
[ -z "${MAIL_MAILER+x}" ] || fail 'mail transport environment override forbidden'
[ "${APP_ENV:-production}" = production ] || fail 'APP_ENV environment override forbidden'
[ "${DB_HOST:-db}" = db ] || fail 'DB_HOST environment override forbidden'
[ "${REDIS_HOST:-redis}" = redis ] || fail 'REDIS_HOST environment override forbidden'
[ "${DB_PORT:-3306}" = 3306 ] || fail 'DB_PORT environment override forbidden'
[ "${REDIS_PORT:-6379}" = 6379 ] || fail 'REDIS_PORT environment override forbidden'
[ "${DB_DATABASE:-rconfig_staging}" = rconfig_staging ] || fail 'DB_DATABASE environment override forbidden'
[ "${DB_USERNAME:-rconfig_staging}" = rconfig_staging ] || fail 'DB_USERNAME environment override forbidden'
[ "${DB_CONNECTION:-mysql}" = mysql ] || fail 'DB_CONNECTION environment override forbidden'
[ "${QUEUE_CONNECTION:-redis}" = redis ] || fail 'QUEUE_CONNECTION environment override forbidden'

if [ "$mode" = '--check' ]; then
    printf 'staging role preflight passed: %s (no service started)\n' "$role"
    exit 0
fi

# This image is built with www-data as the application identity. Prevent
# launching PHP workers as root or if they cannot read their credentials.
[ "$(id -u)" -eq 0 ] || fail 'role launcher must be invoked by container root'
runuser -u www-data -- test -r "$env_file" || fail 'web/worker user cannot read runtime .env'
runuser -u www-data -- test -w "$storage" || fail 'web/worker user cannot write storage'
runuser -u www-data -- test -w "$app_dir/bootstrap/cache" || fail 'web/worker user cannot write bootstrap/cache'

cd "$app_dir" || fail 'application working directory unavailable'
case "$role" in
    app) exec apache2ctl -D FOREGROUND ;;
    horizon) exec runuser -u www-data -- php artisan horizon ;;
    scheduler) exec runuser -u www-data -- php artisan schedule:work --no-interaction ;;
esac
