#!/usr/bin/env bash
# Sourced helper for the hosted, disposable Apache harness. Do not run alone.
# Parent owns the unique infra project, protected mounts and EXIT cleanup.
combined_roles_cleanup() {
    docker rm -f -v "$project-observer" "$project-scheduler" "$project-horizon" >/dev/null 2>&1 || true
    rm -f -- "$RUNNER_TEMP/$project-roles-baseline.log" "$RUNNER_TEMP/$project-roles-live.log"
}

# Diagnostics are deliberately allowlisted, never raw environment or logs.
combined_roles_diagnostics() {
    local role container_id
    for role in app horizon scheduler; do
        container_id="$project-$role"
        printf 'ROLE_DIAGNOSTIC %s ' "$role"
        docker inspect --format 'status={{.State.Status}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}}' "$container_id" 2>/dev/null || true
        docker top "$container_id" -eo pid,euid,egid,comm,args 2>/dev/null | awk '
            $1 ~ /^[0-9]+$/ {
                if ($4 ~ /^php([0-9.]+)?$/) {
                    php++
                    if ($2 != 1000 || $3 != 33) bad++
                    if ($0 ~ /[[:space:]]horizon([[:space:]]|$)/) master++
                    if ($0 ~ /[[:space:]]horizon:supervisor([[:space:]]|$)/) supervisors++
                    if ($0 ~ /[[:space:]]horizon:work([[:space:]]|$)/) workers++
                    if ($0 ~ /[[:space:]]schedule:work([[:space:]]|$)/) scheduler++
                }
            }
            END { printf "PROCESS_COUNTS php=%d wrong_uid_gid=%d master=%d supervisors=%d workers=%d scheduler=%d\n", php,bad,master,supervisors,workers,scheduler }' || true
        docker logs --tail 200 "$container_id" 2>&1 | awk '
            /Horizon started successfully/ { started++ }
            /health:schedule-check-heartbeat/ { heartbeat++ }
            /health:check/ { health++ }
            /staging role refused:/ { refused++ }
            /Permission denied|Read-only file system/ { permission++ }
            /NOAUTH|WRONGPASS|Authentication required/ { auth++ }
            /Connection refused|Connection timed out/ { connection++ }
            /Fatal error|Uncaught|Exception|ERROR/ { errors++ }
            END { printf "LOG_COUNTS horizon_started=%d heartbeat_mentions=%d health_mentions=%d refused=%d permissions=%d auth=%d connection=%d errors=%d\n",started,heartbeat,health,refused,permission,auth,connection,errors }' || true
    done
}

combined_roles_verify_layout() {
    local role container_id image_id
    image_id="$(docker image inspect --format '{{.Id}}' "$image")"
    test "$(docker network inspect --format '{{.Internal}}' "$network")" = true
    for role in app horizon scheduler; do
        container_id="$project-$role"
        test "$(docker inspect --format '{{.State.Running}}' "$container_id")" = true
        test "$(docker inspect --format '{{.Image}}' "$container_id")" = "$image_id"
        test "$(docker inspect --format '{{.HostConfig.ReadonlyRootfs}}' "$container_id")" = true
        test "$(docker inspect --format '{{.HostConfig.Privileged}}' "$container_id")" = false
        test "$(docker inspect --format '{{len .HostConfig.PortBindings}}' "$container_id")" = 0
        test "$(docker inspect --format '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{end}}' "$container_id")" = "$network"
        test "$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/www/html/rconfig/storage"}}{{.Name}}{{end}}{{end}}' "$container_id")" = "$volume"
        test "$(docker inspect --format '{{range .Mounts}}{{if eq .Destination "/var/www/html/rconfig/.env"}}{{.RW}}{{end}}{{end}}' "$container_id")" = false
        docker exec --user 1000:33 "$container_id" sh -c '
            test ! -e /run/secrets/staging_admin_password &&
            test -r /var/www/html/rconfig/.env &&
            test -w /var/www/html/rconfig/storage &&
            test "$(cat /var/www/html/rconfig/storage/.staging-bootstrap-complete)" = staging-bootstrap-v1
        '
    done
}

combined_roles_verify_processes() {
    # Host ps output needs PID for docker top. Root Apache master and runuser
    # wrappers are expected; application workers must be www-data (1000:33).
    docker top "$project-app" -eo pid,euid,egid,comm | awk '
        $1 ~ /^[0-9]+$/ && $4 == "apache2" && $2 != 0 {
            if ($2 != 1000 || $3 != 33) bad = 1
            else workers++
        }
        END { exit !(workers > 0 && !bad) }'
    docker top "$project-horizon" -eo pid,euid,egid,comm,args | awk '
        $1 ~ /^[0-9]+$/ && $4 ~ /^php([0-9.]+)?$/ {
            if ($2 != 1000 || $3 != 33) bad = 1
            if ($0 ~ /[[:space:]]horizon:work([[:space:]]|$)/) workers++
            if ($0 ~ /[[:space:]]horizon([[:space:]]|$)/) masters++
        }
        END { exit !(masters == 1 && workers > 0 && !bad) }'
    docker top "$project-scheduler" -eo pid,euid,egid,comm,args | awk '
        $1 ~ /^[0-9]+$/ && $4 ~ /^php([0-9.]+)?$/ {
            if ($2 != 1000 || $3 != 33) bad = 1
            if ($0 ~ /[[:space:]]schedule:work([[:space:]]|$)/) workers++
        }
        END { exit !(workers == 1 && !bad) }'
    printf '%s\n' 'STAGING_COMBINED_UNPRIVILEGED_PROCESSES_OK (SYNTHETIC_ONLY)'
}

combined_roles_start_and_verify() {
    local started role lifecycle="${1:-initial}"
    test "$GITHUB_ACTIONS" = true
    test "$RUNNER_ENVIRONMENT" = github-hosted
    [[ "$project" =~ ^rconfig-apache-ci-[0-9]+-[0-9]+$ ]]
    test "$app" = "$project-app"
    test "$(docker network inspect --format '{{.Internal}}' "$network")" = true
    # No admin secret in this probe, only the protected common application mounts.
    if [[ "$lifecycle" == initial ]]; then
    docker run --rm -i --name "$project-observer" --network "$network" \
        "${common[@]}" "${identity[@]}" --env RCONFIG_COMBINED_ROLE_PHASE=baseline \
        --entrypoint php "$image" < tests/staging/integration/docker-combined-roles.php \
        | tee "$RUNNER_TEMP/$project-roles-baseline.log"
    grep -Fxq 'STAGING_COMBINED_ROLE_BASELINE_OK (SYNTHETIC_ONLY)' "$RUNNER_TEMP/$project-roles-baseline.log"

    else
        test "$lifecycle" = recreate
    fi

    started="$(date +%s)"
    for role in horizon scheduler; do
        docker run -d --name "$project-$role" --network "$network" \
            "${common[@]}" --entrypoint /usr/local/bin/staging-role-entrypoint.sh "$image" "$role" >/dev/null
    done
    combined_roles_verify_layout
    # Redis auth must remain enforced, even inside the isolated network.
    compose exec -T redis redis-cli ping 2>&1 | grep -Fx 'NOAUTH Authentication required.' >/dev/null
    docker run --rm -i --name "$project-observer" --network "$network" \
        "${common[@]}" "${identity[@]}" --env RCONFIG_COMBINED_ROLE_PHASE=live \
        --env "RCONFIG_COMBINED_ROLE_STARTED_AT=$started" --env "RCONFIG_COMBINED_LIFECYCLE=$lifecycle" \
        --entrypoint php "$image" < tests/staging/integration/docker-combined-roles.php \
        | tee "$RUNNER_TEMP/$project-roles-live.log"
    grep -Fxq 'STAGING_COMBINED_HORIZON_WORKERS_OK (SYNTHETIC_ONLY)' "$RUNNER_TEMP/$project-roles-live.log"
    grep -Fxq 'STAGING_COMBINED_SCHEDULER_HEARTBEAT_OK (SYNTHETIC_ONLY)' "$RUNNER_TEMP/$project-roles-live.log"
    combined_roles_verify_layout
    combined_roles_verify_processes
}

combined_roles_observe() {
    local phase="$1"
    test "$phase" = stopped
    docker run --rm -i --name "$project-observer" --network "$network" \
        "${common[@]}" "${identity[@]}" --env RCONFIG_COMBINED_ROLE_PHASE=stopped \
        --entrypoint php "$image" < tests/staging/integration/docker-combined-roles.php \
        | tee "$RUNNER_TEMP/$project-roles-baseline.log"
    grep -Fxq 'STAGING_OLD_HORIZON_LEASES_EXPIRED_OK (SYNTHETIC_ONLY)' "$RUNNER_TEMP/$project-roles-baseline.log"
}
