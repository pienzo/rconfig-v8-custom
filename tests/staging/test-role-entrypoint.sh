#!/bin/sh
set -eu
script=${1:?script path needed}
sh -n "$script"
test_dir=$(mktemp -d)
trap 'rm -rf "$test_dir"' EXIT HUP INT TERM
mkdir -p "$test_dir/storage" "$test_dir/bootstrap/cache"
printf 'staging-bootstrap-v1\n' > "$test_dir/storage/.staging-bootstrap-complete"
{
  printf '%s\n' 'APP_ENV=production' 'APP_DEBUG=false' 'MAIL_MAILER=log' 'DB_CONNECTION=mysql' 'DB_HOST=db' 'DB_PORT=3306' 'DB_DATABASE=rconfig_staging' 'DB_USERNAME=rconfig_staging' 'REDIS_HOST=redis' 'REDIS_PORT=6379' 'QUEUE_CONNECTION=redis' 'CACHE_DRIVER=redis' 'SESSION_DRIVER=redis' 'RCONFIG_F02_QUEUE_ENABLED=false'
  printf 'APP_KEY=base64:%043d=\n' 0
  printf 'DB_PASSWORD=%064d\n' 0
  printf 'REDIS_PASSWORD=%064d\n' 0
} > "$test_dir/.env"
chmod 600 "$test_dir/.env"
for role in app horizon scheduler; do
  RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" "$role" --check
done
reject() {
 if "$@" >/dev/null 2>&1; then
   echo 'Expected preflight rejection but succeeded' >&2
   exit 1
 fi
}
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" invalid --check
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" RCONFIG_F02_QUEUE_ENABLED=true sh "$script" app --check
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" APP_DEBUG=true sh "$script" app --check
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" MAIL_MAILER=smtp sh "$script" app --check
cp "$test_dir/.env" "$test_dir/.env.orig"
sed -i 's/^MAIL_MAILER=log$/MAIL_MAILER=smtp/' "$test_dir/.env"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
cp "$test_dir/.env.orig" "$test_dir/.env"
sed -i '/^MAIL_MAILER=log$/d' "$test_dir/.env"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
cp "$test_dir/.env.orig" "$test_dir/.env"
printf '%s\n' 'MAIL_MAILER=log' >> "$test_dir/.env"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
cp "$test_dir/.env.orig" "$test_dir/.env"
sed -i 's/RCONFIG_F02_QUEUE_ENABLED=false/RCONFIG_F02_QUEUE_ENABLED=true/' "$test_dir/.env"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
cp "$test_dir/.env.orig" "$test_dir/.env"
chmod 600 "$test_dir/.env"
sed -i 's/APP_KEY=base64:/APP_KEY=/' "$test_dir/.env"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
cp "$test_dir/.env.orig" "$test_dir/.env"
chmod 644 "$test_dir/.env"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
chmod 600 "$test_dir/.env"
printf "%s\n" "RCONFIG_F02_QUEUE_ENABLED=false" >> "$test_dir/.env"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
cp "$test_dir/.env.orig" "$test_dir/.env"
chmod 600 "$test_dir/.env"
# All custom feature flags must remain off, including alternative syntax.
for key in \
    RCONFIG_F02_RUNTIME_ENABLED \
    RCONFIG_F02_QUEUE_ENABLED \
    RCONFIG_F02_RECEIPT_ACCEPTANCE_ENABLED \
    RCONFIG_F02_FINALIZATION_APPROVED \
    RCONFIG_CUSTOM_BACKUP_NOTIFICATIONS_ENABLED \
    RCONFIG_NATIVE_BACKUP_NOTIFICATIONS_ENABLED \
    RCONFIG_TELEGRAM_ENABLED \
    RCONFIG_BACKUP_EMAIL_ENABLED \
    RCONFIG_WHATSAPP_ENABLED \
    RCONFIG_WHATSAPP_POLICY_APPROVED
do
  reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" "$key=true" sh "$script" app --check
  reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" "$key=" sh "$script" app --check
  cp "$test_dir/.env.orig" "$test_dir/.env"
  sed -i "/^$key=/d" "$test_dir/.env"
  printf '%s=true\n' "$key" >> "$test_dir/.env"
  reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" horizon --check
  sed -i "s/^$key=true$/$key=false/" "$test_dir/.env"
  RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" scheduler --check
done
cp "$test_dir/.env.orig" "$test_dir/.env"
for line in 'export RCONFIG_TELEGRAM_ENABLED=true' ' RCONFIG_TELEGRAM_ENABLED=true' 'RCONFIG_TELEGRAM_ENABLED =true' 'RCONFIG_EXTERNAL_BACKUP_INTAKE_ROOT=/synthetic/inbox' 'APP_NAME="${APP_KEY}"'; do
  cp "$test_dir/.env.orig" "$test_dir/.env"
  printf '%s\n' "$line" >> "$test_dir/.env"
  reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
done
cp "$test_dir/.env.orig" "$test_dir/.env"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" RCONFIG_EXTERNAL_BACKUP_INTAKE_ROOT=/synthetic/inbox sh "$script" app --check
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" APP_CONFIG_CACHE=/synthetic/config.php sh "$script" app --check
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" CACHE_DRIVER=file sh "$script" app --check
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" SESSION_DRIVER=file sh "$script" app --check
touch "$test_dir/bootstrap/cache/config.php"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
rm "$test_dir/bootstrap/cache/config.php"
ln -s "$test_dir/absent-config" "$test_dir/bootstrap/cache/config.php"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
rm "$test_dir/bootstrap/cache/config.php"
rm "$test_dir/storage/.staging-bootstrap-complete"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
printf '%s\n' 'staging role preflight synthetic negative tests passed'
