#!/bin/sh
# Hosted disposable CI only; no VM, no company assets, no local operator use.
# A whole-DB SQL/Redis/storage restore is NOT claimed by these two scenarios.
set -eu

[ "$(printenv GITHUB_ACTIONS 2>/dev/null || true)" = true ] ||
  { echo 'refused: GitHub-hosted CI required' >&2; exit 1; }
[ "$(printenv RCONFIG_STAGING_CI_RUNNER 2>/dev/null || true)" = disposable-v1 ] ||
  { echo 'refused: synthetic CI only' >&2; exit 1; }
[ -n "$GITHUB_WORKSPACE" ] && [ -n "$GITHUB_SHA" ] && [ -n "$STAGING_APP_IMAGE" ] ||
  { echo 'refused: missing source/image identity' >&2; exit 1; }
[ "$(id -u)" != 0 ] || { echo 'refused: use an unprivileged hosted runner' >&2; exit 1; }
[ "$(pwd -P)" = "$(realpath -e "$GITHUB_WORKSPACE")" ] ||
  { echo 'refused: unexpected checkout' >&2; exit 1; }

probe=$GITHUB_WORKSPACE/tests/staging/ci-real-first-install.php
[ -f "$probe" ] && [ ! -L "$probe" ] ||
  { echo 'refused: synthetic probe missing' >&2; exit 1; }
[ "$(docker image inspect "$STAGING_APP_IMAGE" --format '{{index .Config.Labels "org.opencontainers.image.revision"}}')" = "$GITHUB_SHA" ] ||
  { echo 'refused: source image revision does not match CI checkout' >&2; exit 1; }

dc() {
  docker compose -f compose.staging.yaml -f compose.bootstrap.yaml "$@"
}

root=$(sudo mktemp -d /etc/rconfig-core-firstinstall-ci.XXXXXXXX)
case "$root" in
  /etc/rconfig-core-firstinstall-ci.*) [ -d "$root" ] && [ ! -L "$root" ] ;;
  *) echo 'refused: unsafe disposable root path' >&2; exit 1 ;;
esac

cleanup() {
  for project in rconfig-ci-first-success rconfig-ci-first-crash; do
    dc -p "$project" --profile infra --profile bootstrap \
      down --volumes --remove-orphans >/dev/null 2>&1 || true
  done
  case "$root" in
    /etc/rconfig-core-firstinstall-ci.*)
      if [ -d "$root" ] && [ ! -L "$root" ]; then sudo rm -rf -- "$root"; fi ;;
  esac
}
trap cleanup 0
trap 'exit 1' 1 2 15

sudo install -d -o root -g root -m 0700 "$root/secrets" "$root/config"
STAGING_CONFIG_DIR=$root/config
STAGING_SECRETS_DIR=$root/secrets
export STAGING_CONFIG_DIR STAGING_SECRETS_DIR

# Three completely new test-only credentials. Values are never printed,
# exported to Docker environment variables or passed on a command line.
sudo sh -c 'umask 077; openssl rand -hex 32 > "$1"' sh "$root/secrets/mariadb_root_password"
sudo sh -c 'umask 077; openssl rand -hex 32 > "$1"' sh "$root/secrets/mariadb_app_password"
sudo sh -c '
  umask 077
  password=$(openssl rand -hex 32) || exit 1
  printf "%s\n" "bind 0.0.0.0" "protected-mode yes" "port 6379" "dir /data" \
     "appendonly yes" "maxmemory-policy noeviction" "requirepass $password" > "$1"
  unset password
' sh "$root/secrets/redis.conf"
sudo sh -c 'umask 077; openssl rand -hex 32 > "$1"; chown 0:33 "$1"; chmod 0640 "$1"' \
  sh "$root/secrets/staging_admin_password"
sudo env STAGING_CONFIG_DIR="$STAGING_CONFIG_DIR" STAGING_SECRETS_DIR="$STAGING_SECRETS_DIR" \
  sh docker/staging/provision-env.sh --create >/dev/null

run_probe() {
  project=$1 scenario=$2
  dc -p "$project" --profile bootstrap run --rm --no-deps \
    --entrypoint php \
    -e GITHUB_ACTIONS=true \
    -e RCONFIG_STAGING_CI_INTEGRATION=disposable-v1 \
    -v "$probe:/usr/local/share/rconfig/ci-first-install.php:ro" \
    bootstrap /usr/local/share/rconfig/ci-first-install.php "$scenario"
}

for project in rconfig-ci-first-success rconfig-ci-first-crash; do
  # Separate fresh named volumes and internal-only bridge per project.
  dc -p "$project" --profile infra up -d --wait --wait-timeout 180
  network=$(printf '%s_staging_internal' "$project")
  [ "$(docker network inspect "$network" --format '{{.Internal}}')" = true ] ||
    { echo 'refused: staging network not isolated' >&2; exit 1; }
  for role in db redis; do
    container=$(dc -p "$project" --profile infra ps -q "$role")
    [ -n "$container" ] || { echo 'refused: infra container missing' >&2; exit 1; }
    [ "$(docker inspect "$container" --format '{{json .HostConfig.PortBindings}}')" = '{}' ] ||
      { echo 'refused: published container port' >&2; exit 1; }
  done
done

# Real nonroot gate, Core migrations, admin sealing and marker publication.
run_probe rconfig-ci-first-success success

# SIGKILL after migrations/seed verification, before protecting the admin.
# A nonzero status other than 137 means the expected point was NOT reached.
set +e
run_probe rconfig-ci-first-crash crash
crash_status=$?
set -e
[ "$crash_status" -eq 137 ] ||
  { echo 'refused: migration SIGKILL probe did not terminate as expected' >&2; exit 1; }
run_probe rconfig-ci-first-crash inspect-crash

echo 'hosted disposable Core migrations: success and SIGKILL/no-retry scenarios passed'
