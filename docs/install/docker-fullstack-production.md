# Docker 生产全功能部署

这套方式用一份 Compose 同时运行以下服务：

- `web`：Nginx、Admin 后台和 UniApp H5
- `backend`：PHP 8.2、Swoole、定时任务和 Redis 队列 Worker
- `mysql`：MySQL 8.0，数据使用命名卷持久化
- `redis`：Redis 7，AOF 数据使用命名卷持久化

只有 Web 端口对宿主机开放，MySQL 和 Redis 只在 Docker 内部网络可访问。微信小程序不能运行在 Docker 中，仍需使用 GitHub Actions 生成的制品在微信开发者工具中上传发布。

## 1. 准备配置

可以直接零配置启动。首次启动时，`ensure-env` 会创建根目录 `.env`，并自动生成数据库密码、MySQL root 密码和 JWT 密钥。

需要固定域名、端口或 Redis 密码时，先执行：

```bash
cp deploy/docker/.example.env .env
```

至少检查这些值：

```env
MALLBASE_HTTP_PORT=8080
MALLBASE_BIND_HOST=127.0.0.1
SITE_URL=https://mall.example.com
MALLBASE_BACKEND_IMAGE=xiaofantuan/mallbase-backend:latest
MALLBASE_WEB_IMAGE=xiaofantuan/mallbase-web:latest
REDIS_PASSWORD=
```

设置 `REDIS_PASSWORD` 后，缓存和队列会自动使用同一密码。默认只监听宿主机 `127.0.0.1`，建议始终由宿主机 Nginx/Caddy 终止 TLS，再转发到 `127.0.0.1:8080`。确实需要直接监听公网网卡时，安装完成后再把 `MALLBASE_BIND_HOST` 改为 `0.0.0.0`，并通过防火墙限制端口；MySQL 和 Redis 不应暴露。

## 2. 拉取并启动

```bash
docker compose -f docker-compose.full.yml pull
docker compose -f docker-compose.full.yml up -d
```

如果不用 Docker Hub 镜像而要在服务器本地构建：

```bash
docker compose -f docker-compose.full.yml up -d --build
```

查看状态：

```bash
docker compose -f docker-compose.full.yml ps
docker compose -f docker-compose.full.yml logs -f backend web
```

`mysql`、`redis`、`backend` 和 `web` 最终都应显示为 `healthy`。backend 健康检查会验证 Swoole、业务数据库、缓存 Redis 和队列 Redis；安装完成后还会检查关键表。`ensure-env` 和 `check-db-auth` 正常完成后显示 `Exited (0)`。

## 3. 完成首次安装

安装完成前，安装接口不要求管理员登录，并会读取数据库和 Redis 配置。不要把未安装实例直接暴露到公网。默认本机绑定下，先从开发电脑建立 SSH 隧道：

```bash
ssh -L 8080:127.0.0.1:8080 user@服务器地址
```

保持隧道连接，再在开发电脑浏览器打开：

```text
http://127.0.0.1:8080/install
```

如果本地 `8080` 已占用，可以把命令第一个端口改为其他空闲端口，例如 `ssh -L 18080:127.0.0.1:8080 ...`，然后访问 `http://127.0.0.1:18080/install`。如果修改过 `MALLBASE_HTTP_PORT`，隧道目标端口也要使用对应值。安装页中的 Docker 内部地址应保持：

```text
数据库主机: mysql
数据库端口: 3306
Redis 主机: redis
Redis 端口: 6379
```

确认“定时任务”和“Swoole 队列 Worker”保持开启，再设置管理员账号并完成安装。数据库必须由安装器面对空库导入，不能提前只导入部分 SQL。

安装完成后重启后端，让常驻 Swoole 进程加载最终配置：

```bash
docker compose -f docker-compose.full.yml restart backend
```

确认安装锁已经生效、Admin 可以登录后，再启用公网 TLS 反向代理。不要把仅有 HTTP 的 `0.0.0.0:8080` 当作最终生产入口。

访问入口：

- H5：`http://服务器地址:8080/client/`
- Admin：`http://服务器地址:8080/admin/`
- 安装状态：`http://服务器地址:8080/install`

## 4. 数据持久化

| Volume | 内容 |
|--------|------|
| `mysql_data` | 业务数据库 |
| `redis_data` | Redis AOF 数据 |
| `backend_runtime` | 安装锁、日志和运行时文件 |
| `backend_uploads` | 用户上传文件 |
| `backend_certs` | 微信支付等商户证书 |
| `backend_config` | 安装器写入的后端运行配置和运行标记 |
| `backend_bootstrap` | 不含 MySQL root 密码的后端启动配置 |
| `app_config` | MySQL、Redis 和工具容器读取的基础设施配置副本 |

`docker compose down` 不会删除这些数据。不要在生产环境执行 `docker compose down -v`，该命令会删除数据库、Redis、上传文件、商户证书、后端配置和安装状态。

## 5. 更新版本

代码合并到 fork 的 `main` 且镜像发布成功后，在服务器执行：

```bash
git pull --ff-only
docker compose -f docker-compose.full.yml pull
docker compose -f docker-compose.full.yml up -d --remove-orphans
```

如果只修改了 `.env`，使用以下命令重新导出配置并重建容器：

```bash
docker compose -f docker-compose.full.yml up -d --force-recreate
```

当前完整模式把 Cron 和队列 Worker 放在同一个后端容器内，因此不要横向扩容 `backend`；多副本会导致定时任务重复执行。

## 6. 备份

数据库备份示例：

```bash
docker compose -f docker-compose.full.yml exec -T mysql sh -c '. /workdir/.env; exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction "$DB_NAME"' > mallbase.sql
```

还需要定期备份 `backend_uploads`、`backend_certs` 和 `backend_config`，并安全保管根目录 `.env`。商户证书和配置备份应加密，备份文件应复制到另一台服务器或对象存储，不能只留在同一块磁盘。

## 7. Docker 不会自动完成的配置

完整镜像包含项目代码和运行依赖，但以下外部能力仍需在 Admin 和对应平台配置：

- 微信支付、退款回调和商户证书
- 微信小程序 AppID、合法 HTTPS 域名及审核发布
- 短信、邮件、OSS/COS 等第三方密钥
- 公网域名、TLS 证书、防火墙、监控和异机备份

镜像功能与源码一致；外部平台账号、资质和密钥不会随镜像提供。
