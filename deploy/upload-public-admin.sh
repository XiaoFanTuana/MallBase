#!/bin/sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname "$0")" && pwd)
ROOT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
ADMIN_SOURCE_DIR="$ROOT_DIR/backend/public/admin"
CLIENT_SOURCE_DIR="$ROOT_DIR/backend/public/client"

SSH_HOST=""
SSH_PORT="22"
SSH_IDENTITY=""
REMOTE_ROOT=""
REMOTE_ADMIN_DIR=""
REMOTE_CLIENT_DIR=""
KEEP_EXTRA="0"

usage() {
    cat <<'EOF'
用法：
  sh deploy/upload-public-admin.sh --host user@server --remote-dir /var/www/mallbase/admin
  sh deploy/upload-public-admin.sh --host user@server --remote-root /www/wwwroot/example.com/mall-base

参数：
  --host         必填，SSH 目标，例如 user@server
  --remote-dir   直接指定服务器上的后台最终目录
  --remote-root  指定服务器上的项目根目录，脚本会自动拼成 backend/public/admin 和 backend/public/client
  --client-dir   可选，直接指定服务器上的 H5 最终目录
  --port         可选，SSH 端口，默认 22
  --identity     可选，SSH 私钥文件路径，例如 ~/.ssh/id_ed25519
  --keep-extra   可选，保留服务器目标目录中多余旧文件；默认会先清空目标目录再解压

说明：
  1. 本地源目录固定为 backend/public/admin
  2. 如果 backend/public/client 存在完整 H5 产物，会一并上传
  3. --remote-dir 和 --remote-root 二选一
  4. 使用 --remote-dir 时，H5 默认上传到后台目录的同级 client 目录，也可以用 --client-dir 覆盖
  5. 默认行为是覆盖服务器目标目录内容，避免旧静态资源残留
EOF
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --host)
            SSH_HOST=${2:-}
            shift 2
            ;;
        --remote-root)
            REMOTE_ROOT=${2:-}
            shift 2
            ;;
        --remote-dir)
            REMOTE_ADMIN_DIR=${2:-}
            shift 2
            ;;
        --client-dir)
            REMOTE_CLIENT_DIR=${2:-}
            shift 2
            ;;
        --port)
            SSH_PORT=${2:-}
            shift 2
            ;;
        --identity)
            SSH_IDENTITY=${2:-}
            shift 2
            ;;
        --keep-extra)
            KEEP_EXTRA="1"
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "未知参数：$1"
            echo
            usage
            exit 1
            ;;
    esac
done

if [ -z "$SSH_HOST" ]; then
    echo "缺少必填参数：--host"
    echo
    usage
    exit 1
fi

if [ -n "$REMOTE_ROOT" ] && [ -n "$REMOTE_ADMIN_DIR" ]; then
    echo "--remote-root 和 --remote-dir 不能同时使用"
    exit 1
fi

if [ -z "$REMOTE_ROOT" ] && [ -z "$REMOTE_ADMIN_DIR" ]; then
    echo "--remote-root 和 --remote-dir 需要二选一"
    exit 1
fi

if [ -n "$REMOTE_ROOT" ]; then
    REMOTE_ADMIN_DIR="$REMOTE_ROOT/backend/public/admin"
    if [ -z "$REMOTE_CLIENT_DIR" ]; then
        REMOTE_CLIENT_DIR="$REMOTE_ROOT/backend/public/client"
    fi
elif [ -z "$REMOTE_CLIENT_DIR" ]; then
    REMOTE_CLIENT_DIR=$(dirname "$REMOTE_ADMIN_DIR")/client
fi

if [ ! -d "$ADMIN_SOURCE_DIR" ]; then
    echo "本地目录不存在：$ADMIN_SOURCE_DIR"
    echo "请先确认后台前端资源已经构建到 backend/public/admin"
    exit 1
fi

if [ ! -f "$ADMIN_SOURCE_DIR/index.html" ]; then
    echo "本地目录缺少 index.html：$ADMIN_SOURCE_DIR/index.html"
    echo "请先确认 backend/public/admin 是一份完整的前端构建产物"
    exit 1
fi

UPLOAD_CLIENT="0"
if [ -d "$CLIENT_SOURCE_DIR" ] && [ -f "$CLIENT_SOURCE_DIR/index.html" ]; then
    UPLOAD_CLIENT="1"
elif [ -d "$CLIENT_SOURCE_DIR" ]; then
    echo ">>> [upload-public-admin] 跳过 H5：$CLIENT_SOURCE_DIR 缺少 index.html"
fi

if [ -n "$SSH_IDENTITY" ] && [ ! -f "$SSH_IDENTITY" ]; then
    echo "SSH 私钥文件不存在：$SSH_IDENTITY"
    exit 1
fi

if ! command -v tar >/dev/null 2>&1; then
    echo "缺少命令：tar"
    exit 1
fi

if ! command -v scp >/dev/null 2>&1; then
    echo "缺少命令：scp"
    exit 1
fi

if ! command -v ssh >/dev/null 2>&1; then
    echo "缺少命令：ssh"
    exit 1
fi

TIMESTAMP=$(date +%Y%m%d%H%M%S)
ADMIN_ARCHIVE_NAME="mallbase-public-admin-${TIMESTAMP}.tar.gz"
CLIENT_ARCHIVE_NAME="mallbase-public-client-${TIMESTAMP}.tar.gz"
LOCAL_ADMIN_ARCHIVE="/tmp/${ADMIN_ARCHIVE_NAME}"
LOCAL_CLIENT_ARCHIVE="/tmp/${CLIENT_ARCHIVE_NAME}"
REMOTE_ADMIN_ARCHIVE="/tmp/${ADMIN_ARCHIVE_NAME}"
REMOTE_CLIENT_ARCHIVE="/tmp/${CLIENT_ARCHIVE_NAME}"

cleanup_local() {
    rm -f "$LOCAL_ADMIN_ARCHIVE" "$LOCAL_CLIENT_ARCHIVE"
}

trap cleanup_local EXIT INT TERM

SCP_ARGS="-P $SSH_PORT"
SSH_ARGS="-p $SSH_PORT"

if [ -n "$SSH_IDENTITY" ]; then
    SCP_ARGS="$SCP_ARGS -i $SSH_IDENTITY"
    SSH_ARGS="$SSH_ARGS -i $SSH_IDENTITY"
fi

echo ">>> [upload-public-admin] 本地后台目录：$ADMIN_SOURCE_DIR"
echo ">>> [upload-public-admin] 服务器后台目标：$SSH_HOST:$REMOTE_ADMIN_DIR"
if [ "$UPLOAD_CLIENT" = "1" ]; then
    echo ">>> [upload-public-admin] 本地 H5 目录：$CLIENT_SOURCE_DIR"
    echo ">>> [upload-public-admin] 服务器 H5 目标：$SSH_HOST:$REMOTE_CLIENT_DIR"
fi
echo ">>> [upload-public-admin] 打包后台资源"

tar -C "$ADMIN_SOURCE_DIR" -czf "$LOCAL_ADMIN_ARCHIVE" .

if [ "$UPLOAD_CLIENT" = "1" ]; then
    echo ">>> [upload-public-admin] 打包 H5 资源"
    tar -C "$CLIENT_SOURCE_DIR" -czf "$LOCAL_CLIENT_ARCHIVE" .
fi

echo ">>> [upload-public-admin] 上传后台归档文件"
# shellcheck disable=SC2086
scp $SCP_ARGS "$LOCAL_ADMIN_ARCHIVE" "$SSH_HOST:$REMOTE_ADMIN_ARCHIVE"

if [ "$UPLOAD_CLIENT" = "1" ]; then
    echo ">>> [upload-public-admin] 上传 H5 归档文件"
    # shellcheck disable=SC2086
    scp $SCP_ARGS "$LOCAL_CLIENT_ARCHIVE" "$SSH_HOST:$REMOTE_CLIENT_ARCHIVE"
fi

REMOTE_SHELL=$(cat <<EOF
set -eu
mkdir -p '$REMOTE_ADMIN_DIR'
if [ '$KEEP_EXTRA' != '1' ]; then
    find '$REMOTE_ADMIN_DIR' -mindepth 1 -maxdepth 1 -exec rm -rf {} +
fi
tar -xzf '$REMOTE_ADMIN_ARCHIVE' -C '$REMOTE_ADMIN_DIR'
rm -f '$REMOTE_ADMIN_ARCHIVE'
EOF
)

if [ "$UPLOAD_CLIENT" = "1" ]; then
    REMOTE_SHELL="$REMOTE_SHELL
mkdir -p '$REMOTE_CLIENT_DIR'
if [ '$KEEP_EXTRA' != '1' ]; then
    find '$REMOTE_CLIENT_DIR' -mindepth 1 -maxdepth 1 -exec rm -rf {} +
fi
tar -xzf '$REMOTE_CLIENT_ARCHIVE' -C '$REMOTE_CLIENT_DIR'
rm -f '$REMOTE_CLIENT_ARCHIVE'"
fi

echo ">>> [upload-public-admin] 解压到服务器目录"
# shellcheck disable=SC2086
ssh $SSH_ARGS "$SSH_HOST" "$REMOTE_SHELL"

echo ">>> [upload-public-admin] 完成"
echo ">>> [upload-public-admin] 后台已同步到：$SSH_HOST:$REMOTE_ADMIN_DIR"
if [ "$UPLOAD_CLIENT" = "1" ]; then
    echo ">>> [upload-public-admin] H5 已同步到：$SSH_HOST:$REMOTE_CLIENT_DIR"
fi
