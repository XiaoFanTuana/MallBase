#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -P "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(CDPATH= cd -P "$SCRIPT_DIR/../.." && pwd)
CHECK_ONLY=0

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

usage() {
    printf '%s\n' 'usage: host-preflight.sh [--check] [--project-root PATH]' >&2
    exit 2
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --check)
            CHECK_ONLY=1
            shift
            ;;
        --project-root)
            [ "$#" -ge 2 ] || usage
            PROJECT_ROOT=$2
            shift 2
            ;;
        *) usage ;;
    esac
done

[ -d "$PROJECT_ROOT" ] && [ ! -L "$PROJECT_ROOT" ] || fail HOST_PREFLIGHT_PROJECT_ROOT_INVALID
PROJECT_ROOT=$(CDPATH= cd -P "$PROJECT_ROOT" && pwd)
UPGRADE_ROOT=$PROJECT_ROOT/upgrade
BIN_ROOT=$UPGRADE_ROOT/bin
BACKEND_DATA_ROOT=$PROJECT_ROOT/data/backend
ROOT_ENV=$PROJECT_ROOT/.env
AGENT_USER=${MALLBASE_AGENT_USER:-}
if [ -n "$AGENT_USER" ]; then
    AGENT_UID=$(id -u "$AGENT_USER" 2>/dev/null) || fail HOST_PREFLIGHT_AGENT_USER_INVALID
    SHARED_GID=$(id -g "$AGENT_USER" 2>/dev/null) || fail HOST_PREFLIGHT_AGENT_USER_INVALID
else
    AGENT_UID=$(id -u)
    SHARED_GID=$(id -g)
fi
SHARED_DIRECTORY_MODE=2770
[ "$(uname -s)" = Darwin ] && SHARED_DIRECTORY_MODE=770
case "$(uname -m)" in
    x86_64|amd64) AGENT_ARCHITECTURE=amd64 ;;
    aarch64|arm64) AGENT_ARCHITECTURE=arm64 ;;
    *) fail AGENT_ARCHITECTURE_UNSUPPORTED ;;
esac
AGENT_BINARY_NAME=mallbase-agent-linux-$AGENT_ARCHITECTURE
AGENT_LAUNCHER=$BIN_ROOT/mallbase-agent

sha256_file() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
        return
    fi
    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$1" | awk '{print $1}'
        return
    fi
    fail HOST_PREFLIGHT_SHA256_UNAVAILABLE
}

mode_of() {
    if stat -f '%Lp' "$1" >/dev/null 2>&1; then
        stat -f '%Lp' "$1"
        return
    fi
    stat -c '%a' "$1" 2>/dev/null || fail HOST_PREFLIGHT_STAT_UNAVAILABLE
}

uid_of() {
    if stat -f '%u' "$1" >/dev/null 2>&1; then
        stat -f '%u' "$1"
        return
    fi
    stat -c '%u' "$1" 2>/dev/null || fail HOST_PREFLIGHT_STAT_UNAVAILABLE
}

gid_of() {
    if stat -f '%g' "$1" >/dev/null 2>&1; then
        stat -f '%g' "$1"
        return
    fi
    stat -c '%g' "$1" 2>/dev/null || fail HOST_PREFLIGHT_STAT_UNAVAILABLE
}

prepare_directory() {
    path=$1
    requested_mode=$2
    expected_mode=${requested_mode#0}
    if [ "$expected_mode" = 2770 ]; then
        expected_mode=$SHARED_DIRECTORY_MODE
    fi
    [ ! -L "$path" ] || fail HOST_PREFLIGHT_DIRECTORY_INVALID
    if [ "$CHECK_ONLY" -eq 0 ]; then
        mkdir -p "$path"
        chown "$AGENT_UID:$SHARED_GID" "$path"
        chmod "$requested_mode" "$path"
    fi
    [ -d "$path" ] || fail HOST_PREFLIGHT_DIRECTORY_INVALID
    [ "$(uid_of "$path")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
    [ "$(gid_of "$path")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
    [ "$(mode_of "$path")" = "$expected_mode" ] || fail HOST_PREFLIGHT_MODE_INVALID
}

set_root_env_value() {
    key=$1
    value=$2
    [ ! -L "$ROOT_ENV" ] && { [ ! -e "$ROOT_ENV" ] || [ -f "$ROOT_ENV" ]; } \
        || fail HOST_PREFLIGHT_ROOT_ENV_INVALID
    temporary=$(mktemp "$PROJECT_ROOT/.env.preflight.XXXXXX")
    trap 'rm -f "$temporary"' 0
    if [ -f "$ROOT_ENV" ]; then
        awk -v key="$key" -v value="$value" '
            BEGIN { replaced = 0 }
            index($0, key "=") == 1 {
                if (replaced == 0) print key "=" value
                replaced = 1
                next
            }
            { print }
            END { if (replaced == 0) print key "=" value }
        ' "$ROOT_ENV" > "$temporary"
    else
        printf '%s=%s\n' "$key" "$value" > "$temporary"
    fi
    chmod 0600 "$temporary"
    mv "$temporary" "$ROOT_ENV"
    trap - 0
}

root_env_value() {
    key=$1
    [ -f "$ROOT_ENV" ] || return 0
    awk -F= -v key="$key" '$1 == key { value = substr($0, length(key) + 2) } END { print value }' "$ROOT_ENV"
}

[ -d "$UPGRADE_ROOT" ] && [ ! -L "$UPGRADE_ROOT" ] || fail HOST_PREFLIGHT_UPGRADE_ROOT_INVALID
[ -d "$BIN_ROOT" ] && [ ! -L "$BIN_ROOT" ] || fail AGENT_BINARY_ROOT_MISSING
MANIFEST=$BIN_ROOT/checksums.sha256
[ -f "$MANIFEST" ] && [ ! -L "$MANIFEST" ] || fail AGENT_BINARY_CHECKSUM_MISSING

[ "$(awk 'NF { count++ } END { print count + 0 }' "$MANIFEST")" = 2 ] \
    || fail AGENT_BINARY_CHECKSUM_INVALID
awk 'NF && ($1 !~ /^[0-9a-f]{64}$/ || $2 !~ /^mallbase-agent-linux-(amd64|arm64)$/ || NF != 2) { exit 1 }' "$MANIFEST" \
    || fail AGENT_BINARY_CHECKSUM_INVALID

for architecture in amd64 arm64; do
    name=mallbase-agent-linux-$architecture
    binary=$BIN_ROOT/$name
    [ -f "$binary" ] && [ ! -L "$binary" ] && [ -s "$binary" ] || fail AGENT_BINARY_MISSING
    [ "$(awk -v name="$name" '$2 == name { count++ } END { print count + 0 }' "$MANIFEST")" = 1 ] \
        || fail AGENT_BINARY_CHECKSUM_INVALID
    expected=$(awk -v name="$name" '$2 == name { print $1 }' "$MANIFEST")
    [ "$expected" = "$(sha256_file "$binary")" ] || fail AGENT_BINARY_CHECKSUM_INVALID
done

if [ "$CHECK_ONLY" -eq 0 ]; then
    chmod 0755 "$BIN_ROOT"
    [ ! -e "$AGENT_LAUNCHER" ] || [ -L "$AGENT_LAUNCHER" ] || fail AGENT_LAUNCHER_INVALID
    rm -f "$AGENT_LAUNCHER"
    ln -s "$AGENT_BINARY_NAME" "$AGENT_LAUNCHER"
    chown "$AGENT_UID:$SHARED_GID" \
        "$UPGRADE_ROOT" \
        "$BIN_ROOT" \
        "$MANIFEST" \
        "$BIN_ROOT/mallbase-agent-linux-amd64" \
        "$BIN_ROOT/mallbase-agent-linux-arm64"
    chmod 0750 "$UPGRADE_ROOT"
    chmod 0555 "$BIN_ROOT" "$BIN_ROOT/mallbase-agent-linux-amd64" "$BIN_ROOT/mallbase-agent-linux-arm64"
    chmod 0444 "$MANIFEST"
fi

prepare_directory "$UPGRADE_ROOT/config" 2770
prepare_directory "$UPGRADE_ROOT/run" 2770
prepare_directory "$UPGRADE_ROOT/run/requests" 2770
prepare_directory "$UPGRADE_ROOT/jobs" 2770
prepare_directory "$UPGRADE_ROOT/backups" 2770
prepare_directory "$UPGRADE_ROOT/packages" 0700
prepare_directory "$UPGRADE_ROOT/agent-private" 0700
prepare_directory "$UPGRADE_ROOT/staging" 0750

# 后端业务数据与升级工作区分开持久化。
prepare_directory "$PROJECT_ROOT/data" 0750
prepare_directory "$BACKEND_DATA_ROOT" 0750
prepare_directory "$BACKEND_DATA_ROOT/env" 2770
prepare_directory "$BACKEND_DATA_ROOT/cert" 2770
prepare_directory "$BACKEND_DATA_ROOT/demo" 2770
prepare_directory "$BACKEND_DATA_ROOT/public-storage" 2770

if [ "$CHECK_ONLY" -eq 0 ]; then
    set_root_env_value MALLBASE_AGENT_UID "$AGENT_UID"
    set_root_env_value MALLBASE_UPGRADE_SHARED_GID "$SHARED_GID"
    set_root_env_value MALLBASE_DEV_UID "$AGENT_UID"
    set_root_env_value MALLBASE_DEV_GID "$SHARED_GID"
fi

[ "$(uid_of "$UPGRADE_ROOT")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
[ "$(gid_of "$UPGRADE_ROOT")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
[ "$(mode_of "$UPGRADE_ROOT")" = 750 ] || fail HOST_PREFLIGHT_MODE_INVALID
[ "$(uid_of "$BIN_ROOT")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
[ "$(gid_of "$BIN_ROOT")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
[ "$(mode_of "$BIN_ROOT")" = 555 ] || fail HOST_PREFLIGHT_MODE_INVALID
[ -L "$AGENT_LAUNCHER" ] && [ "$(readlink "$AGENT_LAUNCHER")" = "$AGENT_BINARY_NAME" ] \
    || fail AGENT_LAUNCHER_INVALID
[ "$(uid_of "$MANIFEST")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
[ "$(gid_of "$MANIFEST")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
[ "$(mode_of "$MANIFEST")" = 444 ] || fail HOST_PREFLIGHT_MODE_INVALID
for architecture in amd64 arm64; do
    binary=$BIN_ROOT/mallbase-agent-linux-$architecture
    [ "$(uid_of "$binary")" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_OWNER_INVALID
    [ "$(gid_of "$binary")" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_GROUP_INVALID
    [ "$(mode_of "$binary")" = 555 ] || fail HOST_PREFLIGHT_MODE_INVALID
done
[ "$(root_env_value MALLBASE_AGENT_UID)" = "$AGENT_UID" ] || fail HOST_PREFLIGHT_ROOT_ENV_INVALID
[ "$(root_env_value MALLBASE_UPGRADE_SHARED_GID)" = "$SHARED_GID" ] || fail HOST_PREFLIGHT_ROOT_ENV_INVALID

printf '%s\n' HOST_PREFLIGHT_OK
