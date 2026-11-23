#!/bin/sh
# GitHub-hosted disposable CI only. No real or persistent VM credentials.
set -eu
[ "${GITHUB_ACTIONS:-}" = true ] || { echo 'CI runner only' >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || { echo 'requires disposable CI root' >&2; exit 1; }
[ -n "${CI_SOURCE_SECRETS_DIR:-}" ] || { echo 'missing synthetic CI sources' >&2; exit 1; }
sh -n docker/staging/provision-admin-secret.sh

root=$(mktemp -d /etc/rconfig-v8-admin-secret-ci.XXXXXXXX)
trap 'rm -rf -- "$root"' EXIT
trap 'exit 1' HUP INT TERM
mkdir -m 0700 "$root/config" "$root/secrets"
install -o root -g root -m 0600 "$CI_SOURCE_SECRETS_DIR/mariadb_app_password" "$root/secrets/mariadb_app_password"
install -o root -g root -m 0600 "$CI_SOURCE_SECRETS_DIR/redis.conf" "$root/secrets/redis.conf"
export STAGING_CONFIG_DIR="$root/config" STAGING_SECRETS_DIR="$root/secrets"
sh docker/staging/provision-env.sh --create
script=docker/staging/provision-admin-secret.sh
target=$STAGING_SECRETS_DIR/staging_admin_password

reject() {
    if sh "$script" --check >/dev/null 2>&1; then
        printf 'Expected admin credential preflight refusal\n' >&2
        exit 1
    fi
}

# No write may happen during --check.
sh "$script" --check
test ! -e "$target"

# A widened config directory fails closed.
chmod 0755 "$STAGING_CONFIG_DIR"
reject
chmod 0700 "$STAGING_CONFIG_DIR"

# F02 is never enabled in synthetic staging.
sed -i 's/^RCONFIG_F02_QUEUE_ENABLED=false$/RCONFIG_F02_QUEUE_ENABLED=true/' "$STAGING_CONFIG_DIR/.env"
reject
sed -i 's/^RCONFIG_F02_QUEUE_ENABLED=true$/RCONFIG_F02_QUEUE_ENABLED=false/' "$STAGING_CONFIG_DIR/.env"
chmod 0640 "$STAGING_CONFIG_DIR/.env"
sh "$script" --check

# Generate exactly once. No secrets are printed, even on success.
sh "$script" --create
test "$(stat -c '%u:%g:%a' -- "$target")" = '0:33:640'
test "$(wc -c < "$target")" -eq 65
grep -Eq '^[[:xdigit:]]{64}$' "$target"
! grep -Fxq admin "$target"

cp -- "$target" "$root/saved-secret"
chmod 0600 "$root/saved-secret"

# Existing credential must never be rotated or overwritten silently.
reject
if sh "$script" --create >/dev/null 2>&1; then
    printf 'Existing admin credential was overwritten\n' >&2
    exit 1
fi
cmp -s -- "$target" "$root/saved-secret"
printf 'staging administrator one-shot synthetic credential gates passed\n'
