#!/usr/bin/env bash
# Hosted disposable CI only. Never execute on a persistent staging host.
set -euo pipefail
test "$GITHUB_ACTIONS" = true
test "$RUNNER_ENVIRONMENT" = github-hosted
[[ "$GITHUB_RUN_ID" =~ ^[0-9]+$ && "$GITHUB_RUN_ATTEMPT" =~ ^[0-9]+$ ]]
mode="${1:---apache-only}"
test "$#" -le 1
case "$mode" in
    --apache-only|--combined|--recreate|--infra-restart|--bootstrap-crash) ;;
    *) exit 2 ;;
esac
if [[ "$mode" != --apache-only && "$mode" != --bootstrap-crash ]]; then
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
    local status=$?
    if [[ "$mode" != --apache-only && "$mode" != --bootstrap-crash ]]; then
        if (( status != 0 )); then combined_roles_diagnostics || true; fi
        combined_roles_cleanup
    fi
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
# The exact installed operator entrypoint is exercised on disposable CI only.
# Approval is a simulated invocation token, not evidence of a VM snapshot.
apply_bootstrap() {
    docker run --rm --network "$network" \
        "${common[@]}" "${admin[@]}" "${identity[@]}" \
        --env RCONFIG_STAGING_BOOTSTRAP=core-synthetic-v1 "$@" \
        --entrypoint /usr/local/bin/staging-bootstrap-container-entrypoint.sh "$image" --apply
}
expect_refusal() {
    local expected="$1" status=0
    shift
    apply_bootstrap "$@" > "$RUNNER_TEMP/$project-negative.log" 2>&1 || status=$?
    test "$status" -eq 1
    grep -Fxq "$expected" "$RUNNER_TEMP/$project-negative.log"
    rm -f "$RUNNER_TEMP/$project-negative.log"
}
approval=(--env RCONFIG_STAGING_BOOTSTRAP_APPROVAL=operator-reviewed-v1)
expect_refusal 'staging bootstrap container refused: explicit operator approval missing'
expect_refusal 'staging operator bootstrap refused: ambient configuration override' \
    "${approval[@]}" --env DB_HOST=db
expect_refusal 'staging operator bootstrap refused: ambient configuration override' \
    "${approval[@]}" --env RCONFIG_TELEGRAM_ENABLED=true
printf '%s\n' 'STAGING_AMBIENT_OVERRIDE_REFUSED_OK (SYNTHETIC_ONLY)'
if [[ "$mode" == --bootstrap-crash ]]; then
    probe_file="$GITHUB_WORKSPACE/tests/staging/integration/docker-bootstrap-crash.php"
    test -f "$probe_file" && test ! -L "$probe_file"
    test "$(docker image inspect "$image" --format '{{index .Config.Labels "org.opencontainers.image.revision"}}')" = "$GITHUB_SHA"
    run_crash_probe() {
        docker run --rm --name "$bootstrap" --network "$network" \
            "${common[@]}" "${admin[@]}" "${identity[@]}" \
            --workdir /var/www/html/rconfig \
            --env RUNNER_ENVIRONMENT=github-hosted \
            --env RCONFIG_STAGING_BOOTSTRAP=core-synthetic-v1 \
            --env RCONFIG_STAGING_CI_INTEGRATION=disposable-v1 \
            --mount "type=bind,source=$probe_file,target=/tmp/ci-bootstrap-crash.php,readonly" \
            --entrypoint php "$image" /tmp/ci-bootstrap-crash.php "$1"
    }
    status=0
    run_crash_probe crash > "$RUNNER_TEMP/$project-bootstrap.log" 2>&1 || status=$?
    grep -Fxq 'STAGING_STRICT_POSTMIGRATION_SIGKILL_OK (SYNTHETIC_ONLY)' "$RUNNER_TEMP/$project-bootstrap.log"
    test "$status" -eq 137
    printf '%s\n' 'STAGING_STRICT_POSTMIGRATION_SIGKILL_OK (SYNTHETIC_ONLY)'
    expect_refusal 'staging bootstrap container refused: previous bootstrap attempt exists; recovery required' "${approval[@]}"
    run_crash_probe inspect-crash | tee "$RUNNER_TEMP/$project-http.log"
    grep -Fxq 'STAGING_STRICT_POSTMIGRATION_INSPECT_OK (SYNTHETIC_ONLY)' "$RUNNER_TEMP/$project-http.log"
    printf '%s\n' 'STAGING_STRICT_OPERATOR_RETRY_REFUSED_OK (SYNTHETIC_ONLY)'
    exit 0
fi
apply_bootstrap "${approval[@]}" | tee "$RUNNER_TEMP/$project-bootstrap.log"
grep -Fxq "STAGING_OPERATOR_BOOTSTRAP_OK (SYNTHETIC_ONLY)" "$RUNNER_TEMP/$project-bootstrap.log"
rm -f "$RUNNER_TEMP/$project-bootstrap.log"
expect_refusal 'staging bootstrap container refused: bootstrap already completed or unexpected marker exists' \
    "${approval[@]}"
printf '%s\n' 'STAGING_STRICT_DOCKER_BOOTSTRAP_OK (SYNTHETIC_ONLY)'

# Start Apache first, without bootstrap secret or host port.
start_apache() {
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
}
start_apache
# In combined mode, require live native Horizon workers and a genuine
# scheduled heartbeat before testing HTTP with all three services running.
if [[ "$mode" != --apache-only ]]; then
    combined_roles_start_and_verify
fi
# The separate unprivileged client shares the app's network namespace,
# reads the bootstrap-only secret locally and never mounts it into Apache.
verify_apache_http() {
    local persistence="${1:-none}"
    docker run --rm -i --name "$probe" --network "container:$app" \
        "${common[@]}" "${admin[@]}" "${identity[@]}" \
        --env RCONFIG_ROLE_PROBE_PHASE=http --env "RCONFIG_ROLE_PERSISTENCE=$persistence" \
        --entrypoint php "$image" < tests/staging/integration/docker-apache.php | tee "$RUNNER_TEMP/$project-http.log"
    grep -Fxq "STAGING_APACHE_AUTHENTICATED_HTTP_OK (SYNTHETIC_ONLY)" "$RUNNER_TEMP/$project-http.log"
    case "$persistence" in
        seed) grep -Fxq 'STAGING_RECREATION_FIXTURES_SEEDED_OK (SYNTHETIC_ONLY)' "$RUNNER_TEMP/$project-http.log" ;;
        verify) grep -Fxq 'STAGING_RECREATION_FIXTURES_PERSISTED_OK (SYNTHETIC_ONLY)' "$RUNNER_TEMP/$project-http.log" ;;
        none) ;;
        *) exit 2 ;;
    esac
    rm -f "$RUNNER_TEMP/$project-http.log"
    test "$(docker inspect --format '{{.State.Running}}' "$app")" = true
    # Docker needs a PID column to identify container processes. Consume all rows
    # so pipefail cannot mistake a successful early match for SIGPIPE.
    docker top "$app" -eo pid,comm | awk '$1 ~ /^[0-9]+$/ && $2 == "apache2" { found = 1 } END { exit !found }'
    printf '%s\n' 'STAGING_ACTUAL_APACHE_ROLE_OK (SYNTHETIC_ONLY)'
}
if [[ "$mode" == --recreate || "$mode" == --infra-restart ]]; then verify_apache_http seed; else verify_apache_http; fi
if [[ "$mode" != --apache-only ]]; then
    combined_roles_verify_layout
    combined_roles_verify_processes
    printf '%s\n' 'STAGING_ACTUAL_THREE_ROLES_OK (SYNTHETIC_ONLY)'
fi

if [[ "$mode" == --recreate || "$mode" == --infra-restart ]]; then
    # Stop only exact hosted test containers. Keep both infra IDs and all mounts.
    declare -A original_ids
    for role in app horizon scheduler; do
        original_ids[$role]="$(docker inspect --format '{{.Id}}' "$project-$role")"
    done
    db_id="$(compose ps -q db)"
    redis_id="$(compose ps -q redis)"
    test -n "$db_id" && test -n "$redis_id"
    docker stop -t 15 "$project-scheduler" "$project-horizon" "$app" >/dev/null
    docker rm "$project-scheduler" "$project-horizon" "$app" >/dev/null
    # The observer waits for the old Redis Horizon leases to expire naturally.
    combined_roles_observe stopped
    if [[ "$mode" == --infra-restart ]]; then
        # Both services are the unique disposable Compose project. Keep the
        # original containers, named volumes, secrets and network intact.
        declare -A infra_started infra_volume
        for service in db redis; do
            id="$(compose ps -q "$service")"
            test -n "$id"
            infra_started[$service]="$(docker inspect --format '{{.State.StartedAt}}' "$id")"
            destination=/var/lib/mysql
            if [[ "$service" == redis ]]; then destination=/data; fi
            infra_volume[$service]="$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "'"$destination"'"}}{{.Name}}{{end}}{{end}}' "$id")"
            test -n "${infra_volume[$service]}"
        done
        # Explicit orderly shutdown; do not count a forced termination as pass.
        compose stop -t 60 db redis
        for id in "$db_id" "$redis_id"; do
            test "$(docker inspect --format '{{.State.Status}}' "$id")" = exited
            test "$(docker inspect --format '{{.State.ExitCode}}' "$id")" = 0
        done
        printf '%s\n' 'STAGING_INFRA_ORDERLY_STOP_OK (SYNTHETIC_ONLY)'
        compose up -d --no-recreate --wait --wait-timeout 180 db redis
        test "$(docker network inspect --format '{{.Internal}}' "$network")" = true
        for service in db redis; do
            id="$(compose ps -q "$service")"
            test "$(docker inspect --format '{{.State.Health.Status}}' "$id")" = healthy
            test "$(docker inspect --format '{{.State.StartedAt}}' "$id")" != "${infra_started[$service]}"
            test "$(docker inspect --format '{{len .HostConfig.PortBindings}}' "$id")" = 0
            destination=/var/lib/mysql
            if [[ "$service" == redis ]]; then destination=/data; fi
            test "$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "'"$destination"'"}}{{.Name}}{{end}}{{end}}' "$id")" = "${infra_volume[$service]}"
        done
        test "$(compose ps -q db)" = "$db_id"
        test "$(compose ps -q redis)" = "$redis_id"
        compose exec -T redis redis-cli ping 2>&1 | grep -Fx 'NOAUTH Authentication required.' >/dev/null
        # Only the non-secret AOF status is emitted; password never becomes
        # an argument or a log line. Require successful loading and AOF write status.
        compose exec -T redis sh -ec '
            pass=$(awk '\''$1 == "requirepass" { print $2; exit }'\'' /run/secrets/redis.conf)
            test -n "$pass"
            REDISCLI_AUTH="$pass" redis-cli --raw INFO persistence | tr -d "\r" | awk '\''
                /^loading:0$/ { loaded=1 }
                /^aof_enabled:1$/ { enabled=1 }
                /^aof_last_write_status:ok$/ { written=1 }
                END { exit !(loaded && enabled && written) }'\''
        '
        # Verify old heartbeat still exists and old leases stay absent before
        # allowing any role to start on the recovered infrastructure.
        combined_roles_observe stopped
        printf '%s\n' 'STAGING_INFRA_RESTART_AOF_AUTH_OK (SYNTHETIC_ONLY)'
    fi
    start_apache
    combined_roles_start_and_verify recreate
    for role in app horizon scheduler; do
        test "$(docker inspect --format '{{.Id}}' "$project-$role")" != "${original_ids[$role]}"
    done
    test "$(compose ps -q db)" = "$db_id"
    test "$(compose ps -q redis)" = "$redis_id"
    verify_apache_http verify
    combined_roles_verify_layout
    combined_roles_verify_processes
    printf '%s\n' 'STAGING_ROLE_RECREATION_PERSISTENCE_OK (SYNTHETIC_ONLY)'
    if [[ "$mode" == --infra-restart ]]; then
        printf '%s\n' 'STAGING_FULL_INFRA_RESTART_PERSISTENCE_OK (SYNTHETIC_ONLY)'
    fi
fi
