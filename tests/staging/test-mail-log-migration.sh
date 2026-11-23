#!/bin/sh
# GitHub-hosted disposable root fixture ONLY. Never run against vm-gpt.
set -eu
[ "$(printenv GITHUB_ACTIONS 2>/dev/null || true)" = true ] || { echo 'Hosted CI only' >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || { echo 'Disposable CI root required' >&2; exit 1; }
script=docker/staging/enable-log-mailer.py
provision=docker/staging/provision-env.sh
root=$(mktemp -d /etc/rconfig-mail-log-ci.XXXXXXXX)
trap 'rm -rf -- "$root"' EXIT
trap 'exit 1' HUP INT TERM
umask 077
mkdir -m 0700 "$root/config" "$root/secrets"
export STAGING_CONFIG_DIR="$root/config" STAGING_SECRETS_DIR="$root/secrets"
openssl rand -hex 32 > "$root/secrets/mariadb_app_password"
pw=$(openssl rand -hex 32)
{
    printf '%s\n' 'bind 0.0.0.0' 'protected-mode yes' 'port 6379' 'dir /data' 'appendonly yes' 'maxmemory-policy noeviction'
    printf 'requirepass %s\n' "$pw"
} > "$root/secrets/redis.conf"
unset pw
chmod 0600 "$root/secrets/"*

reject() {
    if "$@" > /dev/null 2>&1; then
        echo 'Expected one-time mailer transition refusal' >&2
        exit 1
    fi
}
restore_legacy() {
    cp "$root/legacy" "$root/config/.env"
    chown 0:33 "$root/config/.env"
    chmod 0640 "$root/config/.env"
    rm -f -- "$root/config/.env.before-mail-log"
}

sh "$provision" --create
grep -Fxq 'MAIL_MAILER=log' "$root/config/.env"
cp "$root/config/.env" "$root/hardened"
python3 "$script" --check
reject python3 "$script" --apply

# Mimic a previously generated version of the synthetic config.
sed -i '/^MAIL_MAILER=log$/d' "$root/config/.env"
chown 0:33 "$root/config/.env"
chmod 0640 "$root/config/.env"
cp "$root/config/.env" "$root/legacy"
python3 "$script" --check
test ! -e "$root/config/.env.before-mail-log"

python3 "$script" --apply
python3 "$script" --check
cmp -s "$root/config/.env" "$root/hardened"
cmp -s "$root/config/.env.before-mail-log" "$root/legacy"
test "$(stat -c '%u:%g:%a' "$root/config/.env")" = '0:33:640'
test "$(stat -c '%u:%g:%a' "$root/config/.env.before-mail-log")" = '0:33:640'
reject python3 "$script" --apply
cmp -s "$root/config/.env" "$root/hardened"

# Fail closed on drift, duplicate, wrong transport, loose mode or symlinks.
restore_legacy
sed -i 's/^LOG_CHANNEL=daily$/LOG_CHANNEL=external/' "$root/config/.env"
reject python3 "$script" --check
reject python3 "$script" --apply
restore_legacy
printf '%s\n' 'MAIL_MAILER=log' >> "$root/config/.env"
reject python3 "$script" --check
reject python3 "$script" --apply
restore_legacy
printf '%s\n' 'MAIL_MAILER=smtp' >> "$root/config/.env"
reject python3 "$script" --check
reject python3 "$script" --apply
restore_legacy
chmod 0644 "$root/config/.env"
reject python3 "$script" --check
reject python3 "$script" --apply
chmod 0640 "$root/config/.env"
restore_legacy
mv "$root/config/.env" "$root/original"
ln -s "$root/original" "$root/config/.env"
reject python3 "$script" --check
reject python3 "$script" --apply
rm -- "$root/config/.env"
restore_legacy
chmod 0755 "$root/config"
reject python3 "$script" --check
chmod 0700 "$root/config"
restore_legacy
printf '%s\n' 'synthetic artifact from interrupted attempt' > "$root/config/.env.mail-log.synthetic"
reject python3 "$script" --check
reject python3 "$script" --apply
rm -- "$root/config/.env.mail-log.synthetic"
restore_legacy
printf '%s\n' 'preexisting private backup' > "$root/config/.env.before-mail-log"
chmod 0640 "$root/config/.env.before-mail-log"
chown 0:33 "$root/config/.env.before-mail-log"
reject python3 "$script" --check
reject python3 "$script" --apply
restore_legacy

# Process overrides are refused regardless of the on-disk configuration.
reject env MAIL_MAILER=smtp STAGING_CONFIG_DIR="$root/config" STAGING_SECRETS_DIR="$root/secrets" python3 "$script" --check
reject env MAIL_MAILER=log STAGING_CONFIG_DIR="$root/config" STAGING_SECRETS_DIR="$root/secrets" python3 "$script" --apply

python3 "$script" --check
test ! -e "$root/config/.env.before-mail-log"
printf '%s\n' 'synthetic private log-mailer transition, rollback, and refusal tests passed'
