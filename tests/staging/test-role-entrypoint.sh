#!/bin/sh
set -eu
script=${1:?script path needed}
sh -n "$script"
test_dir=$(mktemp -d)
trap 'rm -rf "$test_dir"' EXIT HUP INT TERM
mkdir -p "$test_dir/storage" "$test_dir/bootstrap/cache"
printf 'staging-bootstrap-v1\n' > "$test_dir/storage/.staging-bootstrap-complete"
{
  printf '%s\n' 'APP_ENV=production' 'APP_DEBUG=false' 'DB_CONNECTION=mysql' 'DB_HOST=db' 'DB_PORT=3306' 'DB_DATABASE=rconfig_staging' 'DB_USERNAME=rconfig_staging' 'REDIS_HOST=redis' 'REDIS_PORT=6379' 'QUEUE_CONNECTION=redis' 'CACHE_DRIVER=redis' 'SESSION_DRIVER=redis' 'RCONFIG_F02_QUEUE_ENABLED=false'
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
cp "$test_dir/.env" "$test_dir/.env.orig"
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
rm "$test_dir/storage/.staging-bootstrap-complete"
reject env RCONFIG_STAGING_TEST_APP_DIR="$test_dir" sh "$script" app --check
printf '%s\n' 'staging role preflight synthetic negative tests passed'
