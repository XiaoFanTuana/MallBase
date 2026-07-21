#!/bin/sh
# ============================================================
# ensure-env.sh —— Docker 开发全套模式配置派生脚本
# ============================================================
# 由 docker-compose.dev.yml 的 ensure-env 服务调用。
#
# 设计约束：
#   - 项目根目录 .env 是 Docker 开发全套模式的唯一主配置源
#   - backend/.env 是 ThinkPHP / Swoole 运行时派生文件
#   - 已定义的根 .env 值不会被重新随机化或覆盖
#
# 本脚本负责：
#   1. 根 .env 缺失时从 deploy/docker/.example.env 生成
#   2. 根 .env 已存在时只补齐缺失字段
#   3. 根据 backend/.example.env 派生 backend/.env
#   4. 用根 .env 覆盖数据库、Redis、JWT、CORS 与实例统计等共享字段
# ============================================================
set -eu
umask 077

WORKDIR="${WORKDIR:-/workdir}"
BACKEND_ENV="${WORKDIR}/backend/.env"
BACKEND_TPL="${WORKDIR}/backend/.example.env"
ROOT_ENV="${WORKDIR}/.env"
ROOT_TPL="${WORKDIR}/deploy/docker/.example.env"
PLACEHOLDER="please-change-or-leave-for-random"
BACKEND_HEADER_1="# 由 Docker 开发全套模式自动生成，请勿手动修改。"
BACKEND_HEADER_2="# 唯一主配置源：项目根目录 /.env"
ROOT_TO_BACKEND_KEYS="CRON_ENABLE QUEUE_CONNECTION SWOOLE_QUEUE_ENABLE QUEUE_REDIS_QUEUE QUEUE_REDIS_HOST QUEUE_REDIS_PORT QUEUE_REDIS_PASSWORD QUEUE_REDIS_SELECT QUEUE_REDIS_TIMEOUT QUEUE_REDIS_PERSISTENT SWOOLE_HTTP_PORT SWOOLE_WORKER_NUM SWOOLE_MAX_CONN SWOOLE_BACKLOG SWOOLE_DB_POOL_MAX_ACTIVE SWOOLE_CACHE_POOL_MAX_ACTIVE SWOOLE_REDIS_POOL_MAX_ACTIVE DB_HOST DB_PORT DB_NAME DB_USER DB_PASS REDIS_HOST REDIS_PORT REDIS_CACHE_DB REDIS_PASSWORD CACHE_DRIVER JWT_SECRET JWT_EXPIRE JWT_REFRESH_EXPIRE SITE_URL CORS_ALLOWED_ORIGINS CORS_ALLOW_CREDENTIALS PLATFORM_REPORT_DISABLED"
ROOT_INIT_FROM_BACKEND_KEYS="CRON_ENABLE QUEUE_CONNECTION SWOOLE_QUEUE_ENABLE QUEUE_REDIS_QUEUE QUEUE_REDIS_HOST QUEUE_REDIS_PORT QUEUE_REDIS_PASSWORD QUEUE_REDIS_SELECT QUEUE_REDIS_TIMEOUT QUEUE_REDIS_PERSISTENT SWOOLE_HTTP_PORT SWOOLE_WORKER_NUM SWOOLE_MAX_CONN SWOOLE_BACKLOG SWOOLE_DB_POOL_MAX_ACTIVE SWOOLE_CACHE_POOL_MAX_ACTIVE SWOOLE_REDIS_POOL_MAX_ACTIVE DB_HOST DB_PORT DB_NAME DB_USER DB_PASS REDIS_HOST REDIS_PORT REDIS_CACHE_DB REDIS_PASSWORD CACHE_DRIVER JWT_SECRET JWT_EXPIRE JWT_REFRESH_EXPIRE CORS_ALLOWED_ORIGINS CORS_ALLOW_CREDENTIALS PLATFORM_REPORT_DISABLED"

rand24() { LC_ALL=C od -An -N12 -tx1 /dev/urandom | tr -d ' \n'; }
rand64() { LC_ALL=C od -An -N32 -tx1 /dev/urandom | tr -d ' \n'; }

has_key() {
    file=$1
    key=$2
    grep -q "^${key}=" "$file"
}

get_value() {
    file=$1
    key=$2
    if [ ! -f "$file" ]; then
        return 0
    fi
    grep "^${key}=" "$file" | head -n1 | sed "s|^${key}=||" || true
}

escape_sed() {
    printf '%s' "$1" | sed -e 's/[\/&|]/\\&/g'
}

set_value() {
    file=$1
    key=$2
    value=$3
    escaped=$(escape_sed "$value")
    if has_key "$file" "$key"; then
        tmp_file=$(mktemp)
        sed "s|^${key}=.*|${key}=${escaped}|" "$file" > "$tmp_file"
        mv "$tmp_file" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

fill_missing_from_template() {
    target=$1
    template=$2

    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            ''|\#*) continue ;;
        esac

        key=$(printf '%s' "$line" | awk -F'=' '{print $1}' | tr -d ' ')
        [ -z "$key" ] && continue

        if ! has_key "$target" "$key"; then
            printf '>>> [ensure-env] 向 %s 补齐字段：%s\n' "$target" "$key"
            printf '%s\n' "$line" >> "$target"
        fi
    done < "$template"
}

sync_root_from_existing_backend() {
    [ -f "$BACKEND_ENV" ] || return 0

    for key in $ROOT_INIT_FROM_BACKEND_KEYS; do
        backend_val=$(get_value "$BACKEND_ENV" "$key")
        [ -n "$backend_val" ] || continue
        if [ "$backend_val" = "$PLACEHOLDER" ]; then
            continue
        fi
        set_value "$ROOT_ENV" "$key" "$backend_val"
    done
}

randomize_root_if_placeholder() {
    current=$(get_value "$ROOT_ENV" "$1")
    if [ -z "$current" ] || [ "$current" = "$PLACEHOLDER" ]; then
        set_value "$ROOT_ENV" "$1" "$2"
    fi
}

migrate_legacy_redis_port() {
    if has_key "$ROOT_ENV" "REDIS_HOST_PORT"; then
        return 0
    fi

    legacy_port=$(get_value "$ROOT_ENV" "REDIS_PORT")
    [ -n "$legacy_port" ] || return 0

    set_value "$ROOT_ENV" "REDIS_HOST_PORT" "$legacy_port"
    set_value "$ROOT_ENV" "REDIS_PORT" "6379"
    echo ">>> [ensure-env] 检测到旧 REDIS_PORT 写法，已迁移为 REDIS_HOST_PORT=${legacy_port}，REDIS_PORT=6379"
}

rebuild_backend_env() {
    tmp_env=$(mktemp)
    : > "$tmp_env"

    while IFS= read -r line || [ -n "$line" ]; do
        case "$line" in
            ''|\#*)
                printf '%s\n' "$line" >> "$tmp_env"
                continue
                ;;
        esac

        key=${line%%=*}
        tpl_val=${line#*=}
        current_val=""
        value=$tpl_val

        if [ -f "$BACKEND_ENV" ]; then
            current_val=$(get_value "$BACKEND_ENV" "$key")
        fi

        if [ -n "$current_val" ]; then
            value=$current_val
        fi

        case " $ROOT_TO_BACKEND_KEYS " in
            *" $key "*)
                if has_key "$ROOT_ENV" "$key"; then
                    value=$(get_value "$ROOT_ENV" "$key")
                fi
                ;;
        esac

        if [ "$key" = "JWT_SECRET" ] && { [ -z "$value" ] || [ "$value" = "$PLACEHOLDER" ]; }; then
            value=$(rand64)
        fi

        printf '%s=%s\n' "$key" "$value" >> "$tmp_env"
    done < "$BACKEND_TPL"

    {
        printf '%s\n' "$BACKEND_HEADER_1"
        printf '%s\n' "$BACKEND_HEADER_2"
        printf '\n'
        cat "$tmp_env"
    } > "$BACKEND_ENV"
    chmod 0600 "$BACKEND_ENV"

    rm -f "$tmp_env"
}

export_root_env() {
    target="${ROOT_ENV_EXPORT:-}"
    [ -n "$target" ] || return 0

    mkdir -p "$(dirname "$target")"
    cp "$ROOT_ENV" "$target"
    chmod 600 "$target"
    echo ">>> [ensure-env] exported runtime config to ${target}"
}

export_backend_env() {
    target="${BACKEND_ENV_EXPORT:-}"
    [ -n "$target" ] || return 0

    mkdir -p "$(dirname "$target")"
    cp "$BACKEND_ENV" "$target"
    chmod 600 "$target"
    echo ">>> [ensure-env] exported backend bootstrap config to ${target}"
}

restore_host_file_ownership() {
    [ "$(id -u)" = "0" ] || return 0

    owner=$(stat -c '%u:%g' "$WORKDIR" 2>/dev/null || true)
    case "$owner" in
        *[!0-9:]*|'') return 0 ;;
    esac

    chown "$owner" "$ROOT_ENV" "$BACKEND_ENV" 2>/dev/null || true
}

sync_backend_queue_password() {
    if has_key "$ROOT_ENV" "QUEUE_REDIS_PASSWORD"; then
        set_value "$BACKEND_ENV" "QUEUE_REDIS_PASSWORD" "$(get_value "$ROOT_ENV" "QUEUE_REDIS_PASSWORD")"
    elif has_key "$ROOT_ENV" "REDIS_PASSWORD"; then
        set_value "$BACKEND_ENV" "QUEUE_REDIS_PASSWORD" "$(get_value "$ROOT_ENV" "REDIS_PASSWORD")"
    fi
}

if [ ! -f "$BACKEND_TPL" ]; then
    echo ">>> [ensure-env] 致命错误：找不到 $BACKEND_TPL"
    exit 1
fi

if [ ! -f "$ROOT_TPL" ]; then
    echo ">>> [ensure-env] 致命错误：找不到 $ROOT_TPL"
    exit 1
fi

if [ ! -f "$ROOT_ENV" ]; then
    if [ -n "${ROOT_ENV_EXPORT:-}" ] && [ -f "$ROOT_ENV_EXPORT" ]; then
        echo ">>> [ensure-env] 根目录 .env 不存在，从持久化配置副本恢复"
        cp "$ROOT_ENV_EXPORT" "$ROOT_ENV"
    else
        echo ">>> [ensure-env] 根目录 .env 不存在，从 deploy/docker/.example.env 生成"
        cp "$ROOT_TPL" "$ROOT_ENV"
        sync_root_from_existing_backend
    fi
fi

migrate_legacy_redis_port
fill_missing_from_template "$ROOT_ENV" "$ROOT_TPL"
randomize_root_if_placeholder "DB_PASS" "$(rand24)"
randomize_root_if_placeholder "MYSQL_ROOT_PASSWORD" "$(rand24)"
randomize_root_if_placeholder "JWT_SECRET" "$(rand64)"
chmod 600 "$ROOT_ENV" 2>/dev/null || true

echo ">>> [ensure-env] 开始根据根 .env 派生 backend/.env"
rebuild_backend_env
sync_backend_queue_password
chmod 600 "$BACKEND_ENV" 2>/dev/null || true
export_root_env
export_backend_env
restore_host_file_ownership

echo ">>> [ensure-env] 完成"
