#!/bin/sh
# One-shot synthetic staging administrator credential provisioner.
# NEVER calls migrations, updates the database, starts a service or prints
# the generated credential. Nothing invokes this automatically.
set -eu

fail() {
    printf 'staging admin secret refused: %s\n' "$1" >&2
    exit 1
}

[ "$#" -eq 1 ] || fail 'expected --check or --create'
case "$1" in
    --check|--create) action=$1 ;;
    *) fail 'expected --check or --create' ;;
esac
[ "$(id -u)" -eq 0 ] || fail 'requires root'
[ -n "${STAGING_CONFIG_DIR:-}" ] || fail 'STAGING_CONFIG_DIR missing'
[ -n "${STAGING_SECRETS_DIR:-}" ] || fail 'STAGING_SECRETS_DIR missing'

# Refuse existing regular files, dangling symlinks and unexpected objects.
target=$STAGING_SECRETS_DIR/staging_admin_password
[ ! -e "$target" ] && [ ! -L "$target" ] || fail 'admin credential already exists (never overwrite)'

# This is a prerequisite, not an authorization to run Core migrations. Its
# secret matching / fresh-DB tests are read-only and must succeed now.
script_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P) || fail 'cannot locate staging scripts'
sh "$script_dir/bootstrap-preflight.sh" --check >/dev/null ||
    fail 'bootstrap preflight did not pass'

if [ "$action" = --check ]; then
    printf 'staging admin credential preflight passed (no files written)\n'
    exit 0
fi

command -v openssl >/dev/null 2>&1 || fail 'openssl missing'
umask 077
temp=$(mktemp "$STAGING_SECRETS_DIR/.staging_admin_password.tmp.XXXXXXXX") ||
    fail 'cannot create protected temporary credential'
trap 'rm -f -- "$temp"' EXIT
trap 'exit 1' HUP INT TERM

# 256 bits of random entropy, represented by 64 ASCII hex characters.
# This file will later be mounted individually, read-only, into an explicitly
# approved one-shot bootstrap container (NOT into app/Horizon/scheduler).
openssl rand -hex 32 > "$temp" || fail 'cannot generate administrator credential'
[ "$(wc -c < "$temp")" -eq 65 ] || fail 'invalid credential length'
grep -Eq '^[[:xdigit:]]{64}$' "$temp" || fail 'invalid credential content'

# Docker image's www-data group is gid 33. Host parent remains root:0700.
chown 0:33 "$temp" || fail 'cannot assign container read-only group'
chmod 0640 "$temp" || fail 'cannot protect credential'

# Atomic creation without clobbering any other operator's credentials.
ln -- "$temp" "$target" || fail 'destination already exists; not overwritten'
rm -f -- "$temp"
trap - EXIT HUP INT TERM
printf 'staging admin credential created outside Git (secret not displayed)\n'
