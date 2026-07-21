#!/bin/sh
set -eu
umask 077

MALLBASE_RUNTIME_MODE=${MALLBASE_RUNTIME_MODE:-production}
case "$MALLBASE_RUNTIME_MODE" in
    production)
        BACKEND_ENV=${MALLBASE_BACKEND_ENV_PATH:-${BACKEND_ENV_PATH:-/app/.mallbase-env/backend.env}}
        ;;
    development)
        BACKEND_ENV=${MALLBASE_BACKEND_ENV_PATH:-${BACKEND_ENV_PATH:-/app/.env}}
        mkdir -p /app/runtime /app/public/uploads
        ;;
    *)
        echo "RUNTIME_MODE_INVALID" >&2
        exit 1
        ;;
esac

# Runtime role values must not be persisted because HTTP, queue and cron share
# one backend environment file.
ROLE_CRON_ENABLE=${CRON_ENABLE:-}
ROLE_SWOOLE_QUEUE_ENABLE=${SWOOLE_QUEUE_ENABLE:-}
ROLE_SWOOLE_WORKER_NUM=${SWOOLE_WORKER_NUM:-}

BACKEND_TPL="/app/.example.env"
ROOT_ENV="/workspace/.env"
PLACEHOLDER="please-change-or-leave-for-random"
ROOT_TO_BACKEND_KEYS="APP_DEBUG QUEUE_CONNECTION QUEUE_REDIS_QUEUE QUEUE_REDIS_HOST QUEUE_REDIS_PORT QUEUE_REDIS_PASSWORD QUEUE_REDIS_SELECT QUEUE_REDIS_TIMEOUT QUEUE_REDIS_PERSISTENT SWOOLE_HTTP_PORT SWOOLE_MAX_CONN SWOOLE_BACKLOG SWOOLE_DB_POOL_MAX_ACTIVE SWOOLE_CACHE_POOL_MAX_ACTIVE SWOOLE_REDIS_POOL_MAX_ACTIVE DB_HOST DB_PORT DB_NAME DB_USER DB_PASS REDIS_HOST REDIS_PORT REDIS_CACHE_DB REDIS_PASSWORD CACHE_DRIVER JWT_SECRET JWT_EXPIRE JWT_REFRESH_EXPIRE SITE_URL CORS_ALLOWED_ORIGINS CORS_ALLOW_CREDENTIALS PLATFORM_REPORT_DISABLED"
RUNTIME_TO_BACKEND_KEYS="APP_DEBUG QUEUE_CONNECTION QUEUE_REDIS_QUEUE QUEUE_REDIS_HOST QUEUE_REDIS_PORT QUEUE_REDIS_PASSWORD QUEUE_REDIS_SELECT QUEUE_REDIS_TIMEOUT QUEUE_REDIS_PERSISTENT DEFAULT_LANG DB_TYPE DB_HOST DB_NAME DB_USER DB_PASS DB_PORT DB_CHARSET DB_PREFIX CACHE_DRIVER CACHE_PREFIX CACHE_EXPIRE CACHE_TAG_PREFIX REDIS_HOST REDIS_PORT REDIS_PASSWORD REDIS_TIMEOUT REDIS_PERSISTENT REDIS_CACHE_DB JWT_SECRET JWT_EXPIRE JWT_REFRESH_EXPIRE SWOOLE_HTTP_HOST SWOOLE_HTTP_PORT SWOOLE_MAX_CONN SWOOLE_BACKLOG SWOOLE_MAX_REQUEST SWOOLE_RELOAD_ASYNC SWOOLE_MAX_WAIT_TIME SWOOLE_HEARTBEAT_IDLE_TIME SWOOLE_HEARTBEAT_CHECK_INTERVAL SWOOLE_POOL_MAX_WAIT_TIME SWOOLE_DB_POOL_MAX_ACTIVE SWOOLE_CACHE_POOL_MAX_ACTIVE SWOOLE_REDIS_POOL_MAX_ACTIVE SITE_URL CORS_ALLOWED_ORIGINS CORS_ALLOW_CREDENTIALS PLATFORM_REPORT_DISABLED"

rand64() { LC_ALL=C od -An -N32 -tx1 /dev/urandom | tr -d ' \n'; }

has_key() {
    file=$1
    key=$2
    php -r '
$values = @parse_ini_file($argv[1], false, INI_SCANNER_RAW);
exit(is_array($values) && array_key_exists($argv[2], $values) ? 0 : 1);
' "$file" "$key"
}

get_value() {
    file=$1
    key=$2
    if [ ! -f "$file" ]; then
        return 0
    fi
    php -r '
$values = @parse_ini_file($argv[1], false, INI_SCANNER_RAW);
if (is_array($values) && array_key_exists($argv[2], $values) && is_string($values[$argv[2]])) {
    echo $values[$argv[2]];
}
' "$file" "$key"
}

get_env_value() {
    key=$1
    eval "printf '%s' \"\${$key:-}\""
}

has_env_key() {
    key=$1
    eval "[ \"\${$key+x}\" = x ]"
}

set_value() {
    file=$1
    key=$2
    value=$3
    tmp_file=$(mktemp "$(dirname "$file")/.backend.env.XXXXXX")
    php -r '
$source = $argv[1];
$target = $argv[2];
$key = $argv[3];
$value = $argv[4];
if (preg_match("/^[A-Z][A-Z0-9_]*$/D", $key) !== 1
    || strpbrk($value, "\0\r\n") !== false
    || preg_match("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/", $value) === 1) {
    fwrite(STDERR, "RUNTIME_ENV_VALUE_INVALID\n");
    exit(1);
}
$content = is_file($source) ? file_get_contents($source) : "";
if (!is_string($content)) {
    fwrite(STDERR, "RUNTIME_ENV_FILE_INVALID\n");
    exit(1);
}
$formatted = $value === "" ? "" : "\"" . $value . "\"";
$line = $key . "=" . $formatted;
$pattern = "/^" . preg_quote($key, "/") . "\\s*=.*$/m";
$count = preg_match_all($pattern, $content);
if ($count === false || $count > 1) {
    fwrite(STDERR, "RUNTIME_ENV_FILE_INVALID\n");
    exit(1);
}
if ($count === 1) {
    $content = preg_replace_callback($pattern, static fn(): string => $line, $content, 1);
} else {
    $content = rtrim($content, "\n") . ($content === "" ? "" : "\n") . $line . "\n";
}
if (!is_string($content) || file_put_contents($target, $content) !== strlen($content)) {
    fwrite(STDERR, "RUNTIME_ENV_WRITE_FAILED\n");
    exit(1);
}
' "$file" "$tmp_file" "$key" "$value"
    publish_backend_env "$tmp_file" "$file"
}

fsync_path() {
    php -r '
$path = $argv[1];
$handle = @fopen($path, "rb");
if ($handle === false || !fsync($handle)) {
    fwrite(STDERR, "RUNTIME_ENV_FSYNC_FAILED\n");
    exit(1);
}
fclose($handle);
' "$1"
}

fsync_parent() {
    php -r '
$handle = @fopen($argv[1], "rb");
if ($handle === false || !fsync($handle)) {
    fwrite(STDERR, "RUNTIME_ENV_PARENT_FSYNC_FAILED\n");
    exit(1);
}
fclose($handle);
' "$(dirname "$1")"
}

publish_backend_env() {
    source_file=$1
    target_file=$2
    chmod 0600 "$source_file"
    fsync_path "$source_file"
    mv "$source_file" "$target_file"
    fsync_parent "$target_file"
}

apply_root_env_to_backend() {
    for key in $ROOT_TO_BACKEND_KEYS; do
        has_key "$ROOT_ENV" "$key" || continue
        root_val=$(get_value "$ROOT_ENV" "$key")
        set_value "$BACKEND_ENV" "$key" "$root_val"
    done
}

apply_runtime_env_to_backend() {
    for key in $RUNTIME_TO_BACKEND_KEYS; do
        has_env_key "$key" || continue
        env_val=$(get_env_value "$key")
        set_value "$BACKEND_ENV" "$key" "$env_val"
    done
}

apply_redis_password_to_queue() {
    queue_password=$(get_value "$BACKEND_ENV" "QUEUE_REDIS_PASSWORD")
    redis_password=$(get_value "$BACKEND_ENV" "REDIS_PASSWORD")
    if [ "${QUEUE_REDIS_USE_CACHE_AUTH:-false}" = "true" ]; then
        set_value "$BACKEND_ENV" "QUEUE_REDIS_PASSWORD" "$redis_password"
        return 0
    fi
    if [ -z "$queue_password" ] && [ -n "$redis_password" ]; then
        set_value "$BACKEND_ENV" "QUEUE_REDIS_PASSWORD" "$redis_password"
    fi
}

restore_runtime_role_env() {
    if [ -n "$ROLE_CRON_ENABLE" ]; then
        CRON_ENABLE=$ROLE_CRON_ENABLE
        export CRON_ENABLE
    fi
    if [ -n "$ROLE_SWOOLE_QUEUE_ENABLE" ]; then
        SWOOLE_QUEUE_ENABLE=$ROLE_SWOOLE_QUEUE_ENABLE
        export SWOOLE_QUEUE_ENABLE
    fi
    if [ -n "$ROLE_SWOOLE_WORKER_NUM" ]; then
        SWOOLE_WORKER_NUM=$ROLE_SWOOLE_WORKER_NUM
        export SWOOLE_WORKER_NUM
    fi
}

randomize_jwt_secret_if_needed() {
    jwt_secret=$(get_value "$BACKEND_ENV" "JWT_SECRET")
    if [ -z "$jwt_secret" ] || [ "$jwt_secret" = "$PLACEHOLDER" ]; then
        set_value "$BACKEND_ENV" "JWT_SECRET" "$(rand64)"
    fi
}

derive_backend_env() {
    env_tmp=$(mktemp "$(dirname "$BACKEND_ENV")/.backend.env.init.XXXXXX")
    cp "$BACKEND_TPL" "$env_tmp"
    publish_backend_env "$env_tmp" "$BACKEND_ENV"

    if [ -f "$ROOT_ENV" ]; then
        apply_root_env_to_backend
    fi

    apply_runtime_env_to_backend
    apply_redis_password_to_queue
    randomize_jwt_secret_if_needed
}

ensure_backend_env() {
    if [ -e "$BACKEND_ENV" ] || [ -L "$BACKEND_ENV" ]; then
        [ -f "$BACKEND_ENV" ] && [ ! -L "$BACKEND_ENV" ] \
            || { echo "RUNTIME_ENV_FILE_INVALID" >&2; exit 1; }
        [ "$(stat -c '%a' "$BACKEND_ENV")" = 600 ] \
            || { echo "RUNTIME_ENV_MODE_INVALID" >&2; exit 1; }
        [ "$(stat -c '%u' "$BACKEND_ENV")" = "$(id -u)" ] \
            || { echo "RUNTIME_ENV_OWNER_INVALID" >&2; exit 1; }
    fi
    [ -s "$BACKEND_ENV" ] && return 0
    if [ ! -f "$BACKEND_TPL" ]; then
        echo "RUNTIME_ENV_TEMPLATE_MISSING" >&2
        exit 1
    fi
    env_lock=$(dirname "$BACKEND_ENV")/.backend-env.lock
    (
        if ! flock -w 30 -x 9; then
            echo "RUNTIME_ENV_LOCK_TIMEOUT" >&2
            exit 1
        fi
        [ -s "$BACKEND_ENV" ] && exit 0
        if [ -f "$ROOT_ENV" ]; then
            echo ">>> 未找到运行配置，正在根据项目根 .env 派生"
        else
            echo ">>> 未找到运行配置，正在根据容器环境变量派生"
        fi
        derive_backend_env
    ) 9>"$env_lock"
}

refresh_backend_env() {
    env_lock=$(dirname "$BACKEND_ENV")/.backend-env.lock
    (
        if ! flock -w 30 -x 9; then
            echo "RUNTIME_ENV_LOCK_TIMEOUT" >&2
            exit 1
        fi
        if [ -f "$ROOT_ENV" ]; then
            apply_root_env_to_backend
        fi
        apply_runtime_env_to_backend
        apply_redis_password_to_queue
        randomize_jwt_secret_if_needed
    ) 9>"$env_lock"
}

if [ "$MALLBASE_RUNTIME_MODE" = "production" ]; then
    for writable_path in /app/runtime /app/public/uploads "$(dirname "$BACKEND_ENV")"; do
        if [ ! -d "$writable_path" ] || [ ! -w "$writable_path" ]; then
            echo "RUNTIME_WRITABLE_PATH_INVALID: $writable_path" >&2
            exit 1
        fi
    done
fi

# The persisted file is initialized once and then refreshed atomically with
# known operator-controlled values. Installer-owned fields remain untouched.
ensure_backend_env
refresh_backend_env

if [ -f "$BACKEND_ENV" ]; then
    env_export=$(mktemp /tmp/mallbase-backend-env.XXXXXX)
    trap 'rm -f "$env_export"' 0
    trap 'exit 1' HUP INT TERM
    chmod 0600 "$env_export"
    php -d display_errors=stderr -d opcache.jit_buffer_size=0 \
        /usr/local/bin/export-backend-env.php "$BACKEND_ENV" > "$env_export"
    . "$env_export"
    rm -f "$env_export"
    trap - 0 HUP INT TERM
fi
restore_runtime_role_env

# A development bind mount hides the image's vendor directory.
if [ ! -f /app/vendor/autoload.php ] && [ -f /app/composer.json ]; then
    if [ "$MALLBASE_RUNTIME_MODE" != "development" ]; then
        echo "RUNTIME_DEPENDENCIES_MISSING" >&2
        exit 1
    fi
    echo ">>> 开发环境未找到 /app/vendor/autoload.php，正在执行 composer install"
    composer install --working-dir /app --no-interaction
fi

exec "$@"
