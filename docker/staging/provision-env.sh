#!/bin/sh
# Explicit, one-shot, synthetic-only staging .env provisioner.
# This script DOES NOT install rConfig, migrate schemas, write Redis
# configuration, run Docker, or start services.
set -eu

fail() {
    printf 'staging env provision refused: %s\n' "$1" >&2
    exit 1
}

[ "$#" -eq 1 ] || fail 'expected --check or --create'
case "$1" in
    --check|--create) action=$1 ;;
    *) fail 'expected --check or --create' ;;
esac

[ "$(id -u)" -eq 0 ] || fail 'must run as root'
[ -n "${STAGING_SECRETS_DIR:-}" ] || fail 'STAGING_SECRETS_DIR missing'
[ -n "${STAGING_CONFIG_DIR:-}" ] || fail 'STAGING_CONFIG_DIR missing'

# Only root-owned, persistent system config paths are acceptable. A checked
# out Git tree and /tmp must never become a credential destination.
for dir in "$STAGING_SECRETS_DIR" "$STAGING_CONFIG_DIR"; do
    case "$dir" in
        /etc/*) ;;
        *) fail 'both directories must be absolute paths below /etc' ;;
    esac
    [ -d "$dir" ] && [ ! -L "$dir" ] || fail 'required directory missing or symlinked'
    [ "$(realpath -e -- "$dir")" = "$dir" ] || fail 'directory path must be canonical (no symlinks)'
    [ "$(stat -c '%u' -- "$dir")" = 0 ] || fail 'directory must be root owned'
    [ "$(stat -c '%a' -- "$dir")" = 700 ] || fail 'directory must have mode 0700'
done

[ "$STAGING_CONFIG_DIR" != "$STAGING_SECRETS_DIR" ] || fail 'config and secrets directories must differ'
db_source=$STAGING_SECRETS_DIR/mariadb_app_password
redis_source=$STAGING_SECRETS_DIR/redis.conf
target=$STAGING_CONFIG_DIR/.env

for source in "$db_source" "$redis_source"; do
    [ -f "$source" ] && [ ! -L "$source" ] || fail 'required secret file missing or symlinked'
    [ "$(stat -c '%u' -- "$source")" = 0 ] || fail 'secret source must be root owned'
    [ "$(stat -c '%a' -- "$source")" = 600 ] || fail 'secret source must have mode 0600'
done

db_password=$(cat "$db_source") || fail 'cannot read MariaDB app secret'
[ "$(printf '%s\n' "$db_password" | grep -Ec '^[[:xdigit:]]{64}$')" -eq 1 ] || fail 'invalid MariaDB app secret'
redis_password=$(awk '
    $1 == "requirepass" {
        n++
        if (NF != 2) bad = 1
        if (n == 1) value = $2
    }
    END {
        if (n != 1 || bad) exit 1
        print value
    }
' "$redis_source") || fail 'invalid Redis config requirepass'
[ "$(printf '%s\n' "$redis_password" | grep -Ec '^[[:xdigit:]]{64}$')" -eq 1 ] || fail 'invalid Redis password'

if [ "$action" = --check ]; then
    [ ! -e "$target" ] && [ ! -L "$target" ] || fail 'runtime .env already exists (no overwrite)'
    printf 'staging env provisioning preflight passed (no files written)\n'
    exit 0
fi

[ ! -e "$target" ] && [ ! -L "$target" ] || fail 'runtime .env already exists (no overwrite)'
command -v openssl >/dev/null 2>&1 || fail 'openssl missing'
app_key=$(openssl rand -base64 32) || fail 'cannot generate APP_KEY'
printf 'APP_KEY=base64:%s\n' "$app_key" | grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/]{43}=$' || fail 'APP_KEY invalid'

umask 077
temp=$(mktemp "$STAGING_CONFIG_DIR/.env.tmp.XXXXXXXX") || fail 'cannot create private config temp file'
trap 'rm -f -- "$temp"' EXIT HUP INT TERM

# Fixed synthetic-only settings: no actual devices, SMTP, SFTP or external
# messaging. Bootstrap must independently validate DB/Redis connectivity.
cat > "$temp" <<EOF
APP_NAME="rConfig V8 Core Synthetic Staging"
APP_ENV=production
APP_KEY=base64:$app_key
APP_DEBUG=false
APP_URL=http://127.0.0.1:18080
APP_FORCE_HTTPS=false
APP_DIR_PATH=/var/www/html/rconfig
LOG_CHANNEL=daily
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=rconfig_staging
DB_USERNAME=rconfig_staging
DB_PASSWORD=$db_password
BROADCAST_DRIVER=log
CACHE_DRIVER=redis
CACHE_PREFIX=rconfig_staging
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_LIFETIME=120
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=$redis_password
REDIS_DB=0
REDIS_CACHE_DB=1
HORIZON_PREFIX=rconfig_staging_horizon:
HORIZON_MIN_PROCESS=1
HORIZON_MAX_PROCESS=1
HORIZON_DEFAULT_MAX_PROCESS=1
RCONFIG_F02_QUEUE_ENABLED=false
HEALTHCHECK_PING_ENABLED=false
HEALTHCHECK_OFFLINE_MODE=true
HEALTHCHECK_AIR_GAPPED=true
SECURITY_CHECK_SCHEDULE=false
RCONFIG_CONFIG_FILE_MODE=0440
RCONFIG_CONFIG_DIR_MODE=0750
EOF

# Container image keeps www-data GID 33; the parent /etc directory is mode
# 0700 on the host, so host users cannot traverse to this group-readable file.
# A future Compose mounts *this file only* read-only at the app .env path.
chown 0:33 "$temp" || fail 'cannot set container web-group ownership'
chmod 0640 "$temp" || fail 'cannot protect generated .env'

# Hard-link create is atomic and refuses an already-existing destination,
# even if another operator created it during this operation.
ln -- "$temp" "$target" || fail 'destination unexpectedly exists; no overwrite'
rm -f -- "$temp"
trap - EXIT HUP INT TERM
printf 'staging env provision created outside Git (no secret values displayed)\n'
