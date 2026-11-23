#!/bin/sh
# Read-only, fail-closed preflight for a FUTURE explicit one-shot bootstrap.
# Does not run Laravel, migrations, write SQL/Redis, start Docker or create markers.
set -eu

fail() {
    printf 'staging bootstrap preflight refused: %s\n' "$1" >&2
    exit 1
}

[ "$#" -eq 1 ] && [ "$1" = '--check' ] || fail 'expected --check only'
[ "$(id -u)" -eq 0 ] || fail 'run as root for private staging configuration'
[ -n "${STAGING_CONFIG_DIR:-}" ] || fail 'STAGING_CONFIG_DIR missing'
[ -n "${STAGING_SECRETS_DIR:-}" ] || fail 'STAGING_SECRETS_DIR missing'

for dir in "$STAGING_CONFIG_DIR" "$STAGING_SECRETS_DIR"; do
    case "$dir" in
        /etc/*) ;;
        *) fail 'configuration and secrets must be under /etc' ;;
    esac
    [ -d "$dir" ] && [ ! -L "$dir" ] || fail 'private directory missing or symlinked'
    [ "$(realpath -e -- "$dir")" = "$dir" ] || fail 'noncanonical private directory'
    [ "$(stat -c '%u:%a' -- "$dir")" = '0:700' ] || fail 'private directory must be root:0700'
done
[ "$STAGING_CONFIG_DIR" != "$STAGING_SECRETS_DIR" ] || fail 'secrets and config directory must differ'

app_env=$STAGING_CONFIG_DIR/.env
db_secret=$STAGING_SECRETS_DIR/mariadb_app_password
redis_conf=$STAGING_SECRETS_DIR/redis.conf
[ -f "$app_env" ] && [ ! -L "$app_env" ] || fail 'application .env missing or symlinked'
[ "$(stat -c '%u:%g:%a' -- "$app_env")" = '0:33:640' ] || fail '.env must be root:33 mode 0640'
for file in "$db_secret" "$redis_conf"; do
    [ -f "$file" ] && [ ! -L "$file" ] || fail 'secret file missing or symlinked'
    [ "$(stat -c '%u:%a' -- "$file")" = '0:600' ] || fail 'secret file must be root mode 0600'
done

require_exact() {
    setting=$1
    setting_name=${setting%%=*}
    [ "$(grep -Ec "^$setting_name=" "$app_env")" -eq 1 ] || fail "missing or duplicate $setting_name"
    grep -Fxq -- "$setting" "$app_env" || fail "unexpected $setting_name"
}
for setting in \
    'APP_ENV=production' 'APP_DEBUG=false' 'MAIL_MAILER=log' \
    'DB_CONNECTION=mysql' 'DB_HOST=db' 'DB_PORT=3306' \
    'DB_DATABASE=rconfig_staging' 'DB_USERNAME=rconfig_staging' \
    'REDIS_HOST=redis' 'REDIS_PORT=6379' 'REDIS_DB=0' 'REDIS_CACHE_DB=1' \
    'QUEUE_CONNECTION=redis' 'RCONFIG_F02_QUEUE_ENABLED=false' \
    'HEALTHCHECK_OFFLINE_MODE=true' 'HEALTHCHECK_AIR_GAPPED=true'; do
    require_exact "$setting"
done
[ "$(grep -Ec '^APP_KEY=' "$app_env")" -eq 1 ] || fail 'missing or duplicate APP_KEY'
grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/]{43}=$' "$app_env" || fail 'invalid APP_KEY syntax'

# No password goes in a command argument, log or printed output.
[ "$(grep -Ec '^DB_PASSWORD=' "$app_env")" -eq 1 ] || fail 'missing or duplicate DB_PASSWORD'
[ "$(grep -Ec '^REDIS_PASSWORD=' "$app_env")" -eq 1 ] || fail 'missing or duplicate REDIS_PASSWORD'
app_db_pw=$(sed -n 's/^DB_PASSWORD=//p' "$app_env")
real_db_pw=$(cat "$db_secret")
[ -n "$app_db_pw" ] && [ "$app_db_pw" = "$real_db_pw" ] || fail 'MariaDB secret mismatch'
app_redis_pw=$(sed -n 's/^REDIS_PASSWORD=//p' "$app_env")
real_redis_pw=$(awk '$1 == "requirepass" { n++; if (NF != 2) bad=1; value=$2 } END { if (n != 1 || bad) exit 1; print value }' "$redis_conf") || fail 'invalid Redis requirepass'
[ -n "$app_redis_pw" ] && [ "$app_redis_pw" = "$real_redis_pw" ] || fail 'Redis secret mismatch'
unset app_db_pw real_db_pw app_redis_pw real_redis_pw

command -v docker >/dev/null 2>&1 || fail 'Docker not installed'
db=rconfig-v8-custom-staging-db-1
redis=rconfig-v8-custom-staging-redis-1
network=rconfig-v8-custom-staging_staging_internal

[ "$(docker network inspect "$network" --format '{{.Internal}}' 2>/dev/null)" = true ] || fail 'staging network is not internal'
for item in "$db" "$redis"; do
    [ "$(docker inspect "$item" --format '{{index .Config.Labels "com.docker.compose.project"}}' 2>/dev/null)" = rconfig-v8-custom-staging ] || fail "container outside staging project: $item"
    [ "$(docker inspect "$item" --format '{{.State.Health.Status}}' 2>/dev/null)" = healthy ] || fail "container is not healthy: $item"
    [ "$(docker inspect "$item" --format '{{json .HostConfig.PortBindings}}' 2>/dev/null)" = '{}' ] || fail "host port published: $item"
done

# The infrastructure-only Compose project must not contain an app/worker
# or an unknown third container; any unexpected writer blocks bootstrap.
container_count=$(docker ps -a --filter label=com.docker.compose.project=rconfig-v8-custom-staging --format '{{.Names}}' | wc -l | tr -d '[:space:]')
[ "$container_count" = 2 ] || fail 'unexpected number of staging containers'

# SQL client runs *inside* the existing MariaDB container against the
# dedicated synthetic database. No database state is written.
table_count=$(docker exec -i "$db" sh -c '
    MYSQL_PWD=$(cat /run/secrets/mariadb_app_password)
    export MYSQL_PWD
    exec mariadb --protocol=TCP -h 127.0.0.1 -u rconfig_staging \
      -D rconfig_staging --batch --skip-column-names \
      -e "SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE()"
') || fail 'cannot inspect synthetic MariaDB schema'
[ "$table_count" = 0 ] || fail 'synthetic MariaDB schema is not empty'

# Redis DB 0 and DB 1 must be empty before ANY Core migrations. The
# selected Core has a migration that deletes the Redis default queue.
for db_index in 0 1; do
    size=$(docker exec -i "$redis" sh -c '
        redis_pw=$(awk '\''$1 == "requirepass" {print $2; exit}'\'' /run/secrets/redis.conf)
        [ -n "$redis_pw" ] || exit 1
        REDISCLI_AUTH="$redis_pw" redis-cli --raw -n "$1" DBSIZE
    ' sh "$db_index") || fail "cannot inspect Redis DB $db_index"
    [ "$size" = 0 ] || fail "Redis DB $db_index contains data"
done

printf 'staging bootstrap read-only preflight passed (no changes)\n'
