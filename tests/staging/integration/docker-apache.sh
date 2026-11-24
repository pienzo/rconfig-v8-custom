#!/usr/bin/env bash
# Hosted disposable CI only. Never execute on a persistent staging host.
set -euo pipefail
test "$GITHUB_ACTIONS" = true
test "$RUNNER_ENVIRONMENT" = github-hosted
[[ "$GITHUB_RUN_ID" =~ ^[0-9]+$ && "$GITHUB_RUN_ATTEMPT" =~ ^[0-9]+$ ]]
mode="${1:---apache-only}"
test "$#" -le 1
case "$mode" in
    --apache-only|--combined) ;;
    *) exit 2 ;;
esac
if [[ "$mode" == --combined ]]; then
    source tests/staging/integration/docker-combined-roles.sh
fi
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
    if [[ "$mode" == --combined ]]; then combined_roles_cleanup; fi
    docker rm -f -v "$probe" "$app" "$bootstrap" "$preflight" >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
    compose down --volumes --remove-orphans >/dev/null 2>&1 || true
    sudo rm -f -- "$root/config/.env" "$root/secrets/mariadb_root_password" \
        "$root/secrets/mariadb_app_password" "$root/secrets/redis.conf" "$root/admin"
    sudo rmdir -- "$root/config" "$root/secrets" "$root" || true
    rm -f -- "$RUNNER_TEMP/$project-bootstrap.log" "$RUNNER_TEMP/$project-http.log" "$RUNNER_TEMP/$project-negative.log"
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
# A deliberately injected ambient value must fail before any migration.
negative_status=0
docker run --rm -i --network "$network" \
    "${common[@]}" "${admin[@]}" "${identity[@]}" \
    --env RCONFIG_STAGING_BOOTSTRAP=core-synthetic-v1 \
    --env RCONFIG_ROLE_PROBE_PHASE=bootstrap --env DB_HOST=db \
    --entrypoint php "$image" < tests/staging/integration/docker-apache.php \
    > "$RUNNER_TEMP/$project-negative.log" 2>&1 || negative_status=$?
test "$negative_status" -eq 1
grep -Fxq "Staging Apache integration failed: ambient override DB_HOST" "$RUNNER_TEMP/$project-negative.log"
printf '%s\n' 'STAGING_AMBIENT_OVERRIDE_REFUSED_OK (SYNTHETIC_ONLY)'
rm -f "$RUNNER_TEMP/$project-negative.log"
docker run --rm -i --name "$bootstrap" --network "$network" \
    "${common[@]}" "${admin[@]}" "${identity[@]}" \
    --env RCONFIG_STAGING_BOOTSTRAP=core-synthetic-v1 \
    --env RCONFIG_ROLE_PROBE_PHASE=bootstrap \
    --entrypoint php "$image" < tests/staging/integration/docker-apache.php | tee "$RUNNER_TEMP/$project-bootstrap.log"
grep -Fxq "STAGING_STRICT_DOCKER_BOOTSTRAP_OK (SYNTHETIC_ONLY)" "$RUNNER_TEMP/$project-bootstrap.log"
rm -f "$RUNNER_TEMP/$project-bootstrap.log"

# Start Apache first, without bootstrap secret or host port.
docker run -d --name "$app" --network "$network" \
    "${common[@]}" \
    --tmpfs /run/apache2:rw,nosuid,nodev,mode=0755 \
    --tmpfs /run/lock/apache2:rw,nosuid,nodev,mode=0755 \
    --entrypoint /usr/local/bin/staging-role-entrypoint.sh "$image" app >/dev/null
test "$(docker inspect --format '{{len .HostConfig.PortBindings}}' "$app")" = 0
test "$(docker inspect --format '{{.HostConfig.ReadonlyRootfs}}' "$app")" = true
if ! test "$(docker inspect --format '{{.State.Running}}' "$app")" = true; then
    docker logs "$app" 2>&1 | grep -E '^staging role refused:|^AH[0-9]+:' | tail -n 10 || true
    exit 1
fi
# In combined mode, require live native Horizon workers and a genuine
# scheduled heartbeat before testing HTTP with all three services running.
if [[ "$mode" == --combined ]]; then
    combined_roles_start_and_verify
fi
# The separate unprivileged client shares the app's network namespace,
# reads the bootstrap-only secret locally and never mounts it into Apache.
docker run --rm -i --name "$probe" --network "container:$app" \
    "${common[@]}" "${admin[@]}" "${identity[@]}" \
    --env RCONFIG_ROLE_PROBE_PHASE=http \
    --entrypoint php "$image" < tests/staging/integration/docker-apache.php | tee "$RUNNER_TEMP/$project-http.log"
grep -Fxq "STAGING_APACHE_AUTHENTICATED_HTTP_OK (SYNTHETIC_ONLY)" "$RUNNER_TEMP/$project-http.log"
rm -f "$RUNNER_TEMP/$project-http.log"
test "$(docker inspect --format '{{.State.Running}}' "$app")" = true
# Docker needs a PID column to identify container processes. Consume all rows
# so pipefail cannot mistake a successful early match for SIGPIPE.
docker top "$app" -eo pid,comm | awk '$1 ~ /^[0-9]+$/ && $2 == "apache2" { found = 1 } END { exit !found }'
printf '%s\n' 'STAGING_ACTUAL_APACHE_ROLE_OK (SYNTHETIC_ONLY)'
if [[ "$mode" == --combined ]]; then
    combined_roles_verify_layout
    combined_roles_verify_processes
    printf '%s\n' 'STAGING_ACTUAL_THREE_ROLES_OK (SYNTHETIC_ONLY)'
fi
