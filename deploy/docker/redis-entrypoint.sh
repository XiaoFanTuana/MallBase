#!/bin/sh
set -eu

ROOT_ENV="${ROOT_ENV:-/workdir/.env}"

if [ ! -f "$ROOT_ENV" ]; then
    echo ">>> [redis-entrypoint] missing runtime config: ${ROOT_ENV}" >&2
    exit 1
fi

set -a
. "$ROOT_ENV"
set +a

if [ "${1:-}" = "redis-server" ] && [ -n "${REDIS_PASSWORD:-}" ]; then
    set -- "$@" --requirepass "$REDIS_PASSWORD"
fi

exec /usr/local/bin/docker-entrypoint.sh "$@"
