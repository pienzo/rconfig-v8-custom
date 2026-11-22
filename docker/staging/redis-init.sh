#!/bin/sh
# Staging-only synthetic Redis config loader.
# Docker Compose file secrets may retain host file ownership/mode. Read as
# the container's initial root process, then run Redis as its official
# unprivileged user via the upstream image entrypoint.
set -eu

secret=/run/secrets/redis.conf
runtime=/run/rconfig-redis/redis.conf

if [ ! -s "$secret" ] || [ ! -r "$secret" ]; then
    echo "ERROR: staging Redis secret config is unavailable" >&2
    exit 1
fi

# This first sandbox uses exactly a 64-character hexadecimal password.
# Fail closed rather than accidentally starting an unauthenticated Redis.
if ! grep -Eq '^requirepass[[:space:]]+[[:xdigit:]]{64}[[:space:]]*$' "$secret"; then
    echo "ERROR: staging Redis config must contain an authenticated requirepass" >&2
    exit 1
fi

mkdir -p /run/rconfig-redis
cp "$secret" "$runtime"
chown redis:redis /run/rconfig-redis "$runtime"
chmod 0700 /run/rconfig-redis
chmod 0400 "$runtime"

# Invoke the official Redis entrypoint; it drops root privileges to redis.
exec /usr/local/bin/docker-entrypoint.sh redis-server "$runtime"
