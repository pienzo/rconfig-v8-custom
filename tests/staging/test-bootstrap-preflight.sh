#!/bin/sh
# Disposable GitHub-hosted CI only: writes synthetic probes to temporary
# MariaDB/Redis volumes, never to the operator's VM or persistent staging.
set -eu
[ "${GITHUB_ACTIONS:-}" = true ] || { echo 'CI runner only' >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || { echo 'requires disposable runner root' >&2; exit 1; }
[ -n "${CI_SOURCE_SECRETS_DIR:-}" ] || { echo 'missing CI secrets' >&2; exit 1; }

root=$(mktemp -d /etc/rconfig-v8-bootstrap-ci.XXXXXXXX)
trap 'rm -rf -- "$root"' EXIT
trap 'exit 1' HUP INT TERM
mkdir -m 0700 "$root/config" "$root/secrets"
install -o root -g root -m 0600 "$CI_SOURCE_SECRETS_DIR/mariadb_app_password" "$root/secrets/mariadb_app_password"
install -o root -g root -m 0600 "$CI_SOURCE_SECRETS_DIR/redis.conf" "$root/secrets/redis.conf"
export STAGING_CONFIG_DIR="$root/config" STAGING_SECRETS_DIR="$root/secrets"
sh docker/staging/provision-env.sh --create
sh docker/staging/bootstrap-preflight.sh --check

reject() {
    if sh docker/staging/bootstrap-preflight.sh --check >/dev/null 2>&1; then
        echo 'Expected staging bootstrap preflight refusal' >&2
        exit 1
    fi
}

# Reject an existing table before migrations, even an unrelated synthetic one.
db=rconfig-v8-custom-staging-db-1
docker exec -i "$db" sh -c '
    MYSQL_PWD=$(cat /run/secrets/mariadb_app_password)
    export MYSQL_PWD
    mariadb --protocol=TCP -h 127.0.0.1 -u rconfig_staging -D rconfig_staging \
      -e "CREATE TABLE _staging_preflight_ci_probe (id INT PRIMARY KEY)"
'
reject
docker exec -i "$db" sh -c '
    MYSQL_PWD=$(cat /run/secrets/mariadb_app_password)
    export MYSQL_PWD
    mariadb --protocol=TCP -h 127.0.0.1 -u rconfig_staging -D rconfig_staging \
      -e "DROP TABLE _staging_preflight_ci_probe"
'
sh docker/staging/bootstrap-preflight.sh --check

# Reject any queue state in Redis DB0 and any cache state in Redis DB1.
redis=rconfig-v8-custom-staging-redis-1
for index in 0 1; do
    docker exec -i "$redis" sh -c '
        redis_pw=$(awk '\''$1 == "requirepass" {print $2; exit}'\'' /run/secrets/redis.conf)
        REDISCLI_AUTH="$redis_pw" redis-cli --raw -n "$1" SET rconfig:bootstrap-ci:preflight-probe synthetic >/dev/null
    ' sh "$index"
    reject
    docker exec -i "$redis" sh -c '
        redis_pw=$(awk '\''$1 == "requirepass" {print $2; exit}'\'' /run/secrets/redis.conf)
        REDISCLI_AUTH="$redis_pw" redis-cli --raw -n "$1" DEL rconfig:bootstrap-ci:preflight-probe >/dev/null
    ' sh "$index"
    sh docker/staging/bootstrap-preflight.sh --check
done

# Reject default outbound SMTP, missing and duplicate mailer setting.
cp "$root/config/.env" "$root/mail-safe-original"
sed -i 's/^MAIL_MAILER=log$/MAIL_MAILER=smtp/' "$root/config/.env"
reject
cp "$root/mail-safe-original" "$root/config/.env"
chmod 0640 "$root/config/.env"
sed -i '/^MAIL_MAILER=log$/d' "$root/config/.env"
reject
cp "$root/mail-safe-original" "$root/config/.env"
chmod 0640 "$root/config/.env"
printf '%s\n' 'MAIL_MAILER=log' >> "$root/config/.env"
reject
cp "$root/mail-safe-original" "$root/config/.env"
chmod 0640 "$root/config/.env"
sh docker/staging/bootstrap-preflight.sh --check

# Reject config permission widening or F02 enablement.
chmod 0644 "$root/config/.env"
reject
chmod 0640 "$root/config/.env"
sed -i 's/^RCONFIG_F02_QUEUE_ENABLED=false$/RCONFIG_F02_QUEUE_ENABLED=true/' "$root/config/.env"
reject
sed -i 's/^RCONFIG_F02_QUEUE_ENABLED=true$/RCONFIG_F02_QUEUE_ENABLED=false/' "$root/config/.env"
chmod 0640 "$root/config/.env"
sh docker/staging/bootstrap-preflight.sh --check
printf 'staging bootstrap preflight CI synthetic positive and negative gates passed\n'
