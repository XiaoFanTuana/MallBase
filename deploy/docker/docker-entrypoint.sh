#!/bin/sh
set -e

mkdir -p /app/runtime /app/public/uploads
chmod -R 777 /app/runtime

# backend/.env 是 ThinkPHP 运行时配置；
# Docker 开发全套模式通常由 ensure-env 预先派生，其他模式缺失时才从模板复制
if [ ! -f /app/.env ] && [ -f /app/.example.env ]; then
    echo ">>> 未找到 .env，正在从 .example.env 复制生成"
    cp /app/.example.env /app/.env
fi

if [ -f /app/.env ]; then
    set -a
    . /app/.env
    set +a
fi

# 开发模式下 /app 通常来自宿主机 bind mount，会覆盖镜像层里已经装好的 vendor。
# 因此首次启动前若宿主机 backend/vendor 不存在，这里自动补一次 composer install，
# 让方式二和方式三都能直接起服务。
if [ ! -f /app/vendor/autoload.php ] && [ -f /app/composer.json ]; then
    echo ">>> 未找到 /app/vendor/autoload.php，正在执行 composer install"
    composer install --working-dir /app --no-interaction
fi

exec "$@"
