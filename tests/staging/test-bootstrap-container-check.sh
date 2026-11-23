#!/bin/sh
# Disposable GitHub-hosted CI only. NEVER uses the operator's staging state.
set -eu
[ "${GITHUB_ACTIONS:-}" = true ] || { echo 'Hosted CI only' >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || { echo 'Synthetic test requires ephemeral CI root' >&2; exit 1; }

script=docker/staging/bootstrap-container-entrypoint.sh
sh -n "$script"
root=$(mktemp -d)
trap 'rm -rf -- "$root"' EXIT
trap 'exit 1' HUP INT TERM

app=$root/app
admin=$root/staging_admin_password
mkdir -p "$app/storage/app/rconfig/data" "$app/storage/app/rconfig/templates" "$app/bootstrap/cache"
for tpl in default.yml ios-ssh-enable.yml ios-ssh-noenable.yml \
    ios-telnet-enable.yml ios-telnet-noenable.yml; do
    cp "storage/app/rconfig/templates/$tpl" "$app/storage/app/rconfig/templates/$tpl"
done
chmod 0750 "$app/storage/app/rconfig/data"
printf '%064d\n' 0 > "$admin"
chown 0:33 "$admin"
chmod 0640 "$admin"
cat > "$app/.env" <<'ENV'
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=rconfig_staging
DB_USERNAME=rconfig_staging
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
QUEUE_CONNECTION=redis
RCONFIG_F02_QUEUE_ENABLED=false
HEALTHCHECK_OFFLINE_MODE=true
HEALTHCHECK_AIR_GAPPED=true
SECURITY_CHECK_SCHEDULE=false
APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
ENV
chown 0:33 "$app/.env"
chmod 0640 "$app/.env"

export RCONFIG_STAGING_TEST_APP_DIR="$app"
export RCONFIG_STAGING_TEST_ADMIN_FILE="$admin"
export RCONFIG_STAGING_TEST_MODE=synthetic
export RCONFIG_STAGING_BOOTSTRAP=core-synthetic-v1

reject() {
    if sh "$script" "$@" >/dev/null 2>&1; then
        echo 'Expected bootstrap container check refusal' >&2
        exit 1
    fi
}

sh "$script" --check
reject
reject --apply
reject --check --apply
reject --wat
RCONFIG_STAGING_BOOTSTRAP=wrong reject --check
RCONFIG_F02_QUEUE_ENABLED=true reject --check

chmod 0644 "$app/.env"
reject --check
chmod 0640 "$app/.env"

sed -i 's/^RCONFIG_F02_QUEUE_ENABLED=false$/RCONFIG_F02_QUEUE_ENABLED=true/' "$app/.env"
reject --check
sed -i 's/^RCONFIG_F02_QUEUE_ENABLED=true$/RCONFIG_F02_QUEUE_ENABLED=false/' "$app/.env"

printf '%s\n' 'staging-bootstrap-v1' > "$app/storage/.staging-bootstrap-complete"
reject --check
rm -- "$app/storage/.staging-bootstrap-complete"

chmod 0755 "$app/storage/app/rconfig/data"
reject --check
chmod 0750 "$app/storage/app/rconfig/data"

mv "$app/storage/app/rconfig/templates/default.yml" "$root/default.yml"
reject --check
mv "$root/default.yml" "$app/storage/app/rconfig/templates/default.yml"

chmod 0644 "$admin"
reject --check
chmod 0640 "$admin"

sh "$script" --check
test ! -e "$app/storage/.staging-bootstrap-complete"
printf 'synthetic bootstrap-only container read-only positive/negative tests passed\n'
