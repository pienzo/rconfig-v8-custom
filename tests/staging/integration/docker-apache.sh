#!/usr/bin/env bash
# Hosted disposable CI only. Never execute on a persistent staging host.
set -euo pipefail
test "$GITHUB_ACTIONS" = true
test "$RUNNER_ENVIRONMENT" = github-hosted
[[ "$GITHUB_RUN_ID" =~ ^[0-9]+$ && "$GITHUB_RUN_ATTEMPT" =~ ^[0-9]+$ ]]
project="rconfig-apache-ci-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"
network="$project"_staging_internal
volume="$project"-storage
app="$project"-app
bootstrap="$project"-bootstrap
preflight="$project"-preflight
probe="$project"-probe
image=rconfig-custom-ci:apache
root="$(sudo mktemp -d /etc/rconfig-apache-ci.XXXXXXXX)"

compose() {
    sudo env STAGING_SECRETS_DIR="$root/secrets" docker compose \
        --project-name "$project" -f compose.staging.yaml --profile infra "$@"
}

cleanup() {
    docker rm -f -v "$probe" "$app" "$bootstrap" "$preflight" >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true
    sudo rm -f -- "$root/config/.env" "$root/secrets/mariadb_root_password" \
        "$root/secrets/mariadb_app_password" "$root/secrets/redis.conf" "$root/admin"
    sudo rmdir -- "$root/config" "$root/secrets" "$root" || true
}
trap cleanup EXIT

sudo install -d -m 0700 "$root/config" "$root/secrets"
sudo sh -c '
    umask 077
    openssl rand -hex 32 > "$1/secrets/mariadb_root_password"
    openssl rand -hex 32 > "$1/secrets/mariadb_app_password"
    {
        printf "bind 0.0.0.0\nprotected-mode yes\nport 6379\ndir /data\nappendonly yes\nmaxmemory-policy noeviction\n"
        printf "requirepass %s\n" "$(openssl rand -hex 32)"
    } > "$1/secrets/redis.conf"
    openssl rand -hex 32 > "$1/admin"
    chown 0:33 "$1/admin"
    chmod 0640 "$1/admin"
' sh "$root"
sudo env STAGING_CONFIG_DIR="$root/config" STAGING_SECRETS_DIR="$root/secrets" \
    sh docker/staging/provision-env.sh --check
sudo env STAGING_CONFIG_DIR="$root/config" STAGING_SECRETS_DIR="$root/secrets" \
    sh docker/staging/provision-env.sh --create

compose config --quiet
compose up -d --wait --wait-timeout 180
test "$(docker network inspect --format '{{.Internal}}' "$network")" = true
test "$(compose ps --status running --services | wc -l)" -eq 2
for service in db redis; do
    container_id="$(compose ps -q "$service")"
    test "$(docker inspect --format '{{len .HostConfig.PortBindings}}' "$container_id")" = 0
done

docker volume create "$volume" >/dev/null
common=(
    --read-only --security-opt no-new-privileges
    --mount "type=bind,source=$root/config/.env,target=/var/www/html/rconfig/.env,readonly"
    --mount "type=volume,source=$volume,target=/var/www/html/rconfig/storage"
    --tmpfs /tmp:rw,nosuid,nodev,mode=1777
    --tmpfs /var/www/html/rconfig/bootstrap/cache:rw,nosuid,nodev,uid=1000,gid=33,mode=0770
)
admin=(--mount "type=bind,source=$root/admin,target=/run/secrets/staging_admin_password,readonly")
identity=(--user 1000:33 --cap-drop ALL --env GITHUB_ACTIONS=true)

# Actual installed read-only bootstrap preflight; never manufacture a marker.
docker run --rm --name "$preflight" --network "$network" \
    "${common[@]}" "${admin[@]}" "${identity[@]}" \
    --env RCONFIG_STAGING_BOOTSTRAP=core-synthetic-v1 \
    --entrypoint /usr/local/bin/staging-bootstrap-container-entrypoint.sh "$image" --check
docker run --rm -i --name "$bootstrap" --network "$network" \
    "${common[@]}" "${admin[@]}" "${identity[@]}" \
    --env RCONFIG_STAGING_BOOTSTRAP=core-synthetic-v1 \
    --env RCONFIG_ROLE_PROBE_PHASE=bootstrap \
    --entrypoint php "$image" < tests/staging/integration/docker-apache.php

# Only the app role; no bootstrap secret, Horizon, scheduler or host port.
docker run -d --name "$app" --network "$network" \
    "${common[@]}" \
    --tmpfs /run/apache2:rw,nosuid,nodev,mode=0755 \
    --tmpfs /run/lock/apache2:rw,nosuid,nodev,mode=0755 \
    --entrypoint /usr/local/bin/staging-role-entrypoint.sh "$image" app >/dev/null
test "$(docker inspect --format '{{len .HostConfig.PortBindings}}' "$app")" = 0
test "$(docker inspect --format '{{.HostConfig.ReadonlyRootfs}}' "$app")" = true
# The separate unprivileged client shares the app's network namespace,
# reads the bootstrap-only secret locally and never mounts it into Apache.
docker run --rm -i --name "$probe" --network "container:$app" \
    "${common[@]}" "${admin[@]}" "${identity[@]}" \
    --env RCONFIG_ROLE_PROBE_PHASE=http \
    --entrypoint php "$image" < tests/staging/integration/docker-apache.php
test "$(docker inspect --format '{{.State.Running}}' "$app")" = true
docker top "$app" -eo comm | grep -qx apache2
printf '%s\n' 'STAGING_ACTUAL_APACHE_ROLE_OK (SYNTHETIC_ONLY)'
