# MallBase 安装与部署导航

本页是 MallBase 安装与部署的唯一导航入口：先按需求选一种安装方式，再进入对应的完整步骤文档，遇到问题查排障文档，需要单独执行某条命令查命令集合。

## 安装方式

| 方式 | 适合场景 | 完整步骤 |
|------|---------|----------|
| 方式一：手动安装（无 Docker） | 低配服务器、需要完全控制 PHP / MySQL / Redis / Nginx | [manual.md](./manual.md) |
| 方式二：Docker 开发（仅后端） | 本地开发，宿主机已有 MySQL / Redis | [docker-backend-only.md](./docker-backend-only.md) |
| 方式三：Docker 开发（全套） | 本地一键起后端 + MySQL + Redis，前端打包单独执行 | [docker-fullstack.md](./docker-fullstack.md) |
| 方式四：Docker 生产 | 单后端容器 + 宿主机 Nginx 的生产部署 | [docker-production.md](./docker-production.md) |

四种方式都配合 [commands.md](./commands.md)（零散命令）和 [troubleshooting.md](./troubleshooting.md)（排障）使用。

## 安装专题文档

| 文档 | 说明 |
|------|------|
| [commands.md](./commands.md) | 可独立执行的安装与部署命令集合（构建、上传、删除清理、验证等），不替代完整安装教程 |
| [troubleshooting.md](./troubleshooting.md) | 安装、Docker、前端静态资源与运行时的故障排查 |
| [env-files.md](./env-files.md) | 根 `.env`、`backend/.env` 与 Docker 全套模式的配置职责与主从关系 |
| [nginx-reverse-proxy.md](./nginx-reverse-proxy.md) | `/`、`/client/`、`/admin/`、`/client/api/`、`/admin/api/` 等路径的代理与静态托管规则 |
| [issues/docker-fullstack-first-run.md](./issues/docker-fullstack-first-run.md) | 方式三首次启动的密码错位、时序问题专题记录 |

## 前端构建与发布

| 文档 | 说明 |
|------|------|
| [admin-build.md](./admin-build.md) | 后台前端（Vben Admin）打包到 `backend/public/admin`（Docker 一键打包 / 本地打包） |
| [uniapp-build.md](./uniapp-build.md) | UniApp H5 打包到 `backend/public/client` |
| [upload-frontend.md](./upload-frontend.md) | 用 `deploy/upload-frontend.sh` 把 `backend/public/admin`（及 `client`）上传到服务器 |
| [cleanup-dev.md](./cleanup-dev.md) | `deploy/docker/cleanup-dev.sh`：清理方式三产生的容器、镜像与本地生成文件 |

## 环境要求

| 依赖 | 最低版本 | 用途 |
|------|---------|------|
| PHP | 8.2+ | 后端运行 |
| Swoole 扩展 | 5.0+（兼容 4.2.9+） | 高性能 HTTP 服务 |
| Redis 扩展 (phpredis) | 5.3.4+（推荐 6.0+） | 缓存 / 会话 |
| MySQL | 8.0+ | 数据库 |
| Redis | 6.0+ | 缓存 |
| Composer | 2.0+ | PHP 依赖管理 |
| Node.js | 20.19.0+（仅构建前端） | 前端打包 |
| pnpm | 10.0.0+（仅构建前端） | 前端包管理 |

### PHP 扩展清单

| 扩展 | 用途 | 必须 |
|------|------|------|
| swoole | HTTP 服务器 | 是 |
| pdo_mysql | 数据库驱动 | 是 |
| redis | 缓存驱动 | 是 |
| mbstring | 多字节字符串 | 是 |
| gd | 图片处理 | 是 |
| zip | 压缩包处理 | 是 |
| intl | 国际化 | 是 |
| bcmath | 高精度数学（价格计算） | 是 |
| opcache | PHP 性能优化 | 推荐 |

## 推荐阅读顺序

1. 按上面的「安装方式」表选一种。
2. 进入对应的完整步骤文档，从头按顺序执行，不要只看命令集合拼装流程。
3. 用方式三时，先读 [env-files.md](./env-files.md) 理清 `.env` 的主从关系。
4. 涉及 Nginx 或前端静态文件发布时，配合 [nginx-reverse-proxy.md](./nginx-reverse-proxy.md)、[admin-build.md](./admin-build.md)、[uniapp-build.md](./uniapp-build.md)、[upload-frontend.md](./upload-frontend.md)。
5. 遇到报错先查 [troubleshooting.md](./troubleshooting.md)；如果是方式三首装时序问题，再看 [issues/docker-fullstack-first-run.md](./issues/docker-fullstack-first-run.md)。

## 说明

- `commands.md` 里的命令可以单独执行，但它不是完整安装教程的替代品。
- 每种安装方式的完整闭环都在各自独立文档里，执行时请优先跟随对应方式文档。
