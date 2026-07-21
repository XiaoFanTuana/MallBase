#!/bin/sh
set -eu
umask 077

APP_UID=${MALLBASE_APP_UID:-10000}
APP_GID=${MALLBASE_APP_GID:-10000}
CONFIG_DIR=/config
TARGET_ENV=$CONFIG_DIR/backend.env
LEGACY_ENV=$CONFIG_DIR/.env
BOOTSTRAP_ENV=/bootstrap/.env
LAYOUT_MARKER=$CONFIG_DIR/.mallbase-volume-layout-v2

case "$APP_UID" in
    ''|*[!0-9]*)
        echo "BACKEND_VOLUME_IDENTITY_INVALID" >&2
        exit 1
        ;;
esac
case "$APP_GID" in
    ''|*[!0-9]*)
        echo "BACKEND_VOLUME_IDENTITY_INVALID" >&2
        exit 1
        ;;
esac

for path in /runtime /uploads /cert /config /demo /public-storage /upgrade; do
    if [ -L "$path" ]; then
        echo "BACKEND_VOLUME_PATH_INVALID: $path" >&2
        exit 1
    fi
    mkdir -p "$path"
done

for path in /upgrade/config /upgrade/run /upgrade/run/requests /upgrade/jobs /upgrade/backups; do
    if [ -L "$path" ]; then
        echo "BACKEND_VOLUME_PATH_INVALID: $path" >&2
        exit 1
    fi
    mkdir -p "$path"
done

if [ ! -e "$TARGET_ENV" ]; then
    if [ -L "$TARGET_ENV" ] || [ -L "$LEGACY_ENV" ]; then
        echo "BACKEND_VOLUME_LEGACY_ENV_INVALID" >&2
        exit 1
    elif [ -f "$LEGACY_ENV" ]; then
        mv "$LEGACY_ENV" "$TARGET_ENV"
        echo ">>> [prepare-volumes] 已迁移旧版后端配置到 backend.env"
    elif [ -e "$LEGACY_ENV" ]; then
        echo "BACKEND_VOLUME_LEGACY_ENV_INVALID" >&2
        exit 1
    elif [ -f "$BOOTSTRAP_ENV" ] && [ ! -L "$BOOTSTRAP_ENV" ]; then
        temporary=$CONFIG_DIR/.backend.env.bootstrap.tmp
        cp "$BOOTSTRAP_ENV" "$temporary"
        chmod 0600 "$temporary"
        mv "$temporary" "$TARGET_ENV"
        echo ">>> [prepare-volumes] 已创建后端持久配置"
    else
        echo "BACKEND_VOLUME_BOOTSTRAP_ENV_MISSING" >&2
        exit 1
    fi
fi

if [ ! -f "$TARGET_ENV" ] || [ -L "$TARGET_ENV" ]; then
    echo "BACKEND_VOLUME_ENV_INVALID" >&2
    exit 1
fi

if [ -e "$LAYOUT_MARKER" ] || [ -L "$LAYOUT_MARKER" ]; then
    if [ ! -f "$LAYOUT_MARKER" ] || [ -L "$LAYOUT_MARKER" ]; then
        echo "BACKEND_VOLUME_LAYOUT_MARKER_INVALID" >&2
        exit 1
    fi
fi

# Existing full-stack volumes were created by a root-running backend. Perform
# the ownership migration once; later application writes already use APP_UID.
if [ ! -f "$LAYOUT_MARKER" ]; then
    chown -R "$APP_UID:$APP_GID" \
        /runtime /uploads /cert /config /demo /public-storage /upgrade
    printf '%s\n' 'layout=2' > "$LAYOUT_MARKER"
    chown "$APP_UID:$APP_GID" "$LAYOUT_MARKER"
fi

chown "$APP_UID:$APP_GID" "$TARGET_ENV"
chmod 0600 "$TARGET_ENV"
chmod 0750 /runtime /cert /demo /public-storage /upgrade
chmod 0755 /uploads
chmod 0700 /config
chmod 0770 /upgrade/config /upgrade/run /upgrade/run/requests /upgrade/jobs /upgrade/backups
chmod 0600 "$LAYOUT_MARKER"

echo ">>> [prepare-volumes] 后端命名卷已就绪"
