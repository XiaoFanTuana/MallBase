# Docker Hub 多架构镜像发布

本项目通过 GitHub Actions 自动构建并推送两个生产镜像到 Docker Hub：

- `mallbase-backend`：PHP 8.2、Swoole 和后端代码
- `mallbase-web`：Nginx、Admin 和 UniApp H5 静态产物

## 触发方式

`.github/workflows/docker-publish.yml` 会在以下场景运行：

- 推送到 `main` 或 `master`
- 推送版本标签，例如 `v1.0.0`
- 在 GitHub Actions 页面手动运行 `Docker Publish`

如果仓库尚未配置 Docker Hub Secrets，后端验证仍会运行，但镜像构建和上传步骤会显示警告并跳过。

构建平台：

```text
linux/amd64
linux/arm64
```

## GitHub 配置

在你的 GitHub fork 仓库里进入：

```text
Settings -> Secrets and variables -> Actions
```

新增 Repository secrets：

| 名称 | 用途 |
|------|------|
| `DOCKERHUB_USERNAME` | Docker Hub 用户名 |
| `DOCKERHUB_TOKEN` | Docker Hub Access Token，不要使用登录密码 |

可选新增 Repository variables：

| 名称 | 默认值 | 用途 |
|------|--------|------|
| `DOCKERHUB_NAMESPACE` | `DOCKERHUB_USERNAME` | Docker Hub 命名空间，通常就是用户名或组织名 |
| `DOCKERHUB_REPOSITORY` | `mallbase-backend` | 后端 Docker Hub 仓库名 |
| `DOCKERHUB_WEB_REPOSITORY` | `mallbase-web` | Web Docker Hub 仓库名 |

默认镜像名为：

```text
docker.io/<DOCKERHUB_USERNAME>/mallbase-backend
docker.io/<DOCKERHUB_USERNAME>/mallbase-web
```

## 标签规则

常见标签：

| 触发 | 推送标签 |
|------|----------|
| 推送到默认分支 | `latest`、分支名、短提交 SHA |
| 推送到 `main` | `main`、短提交 SHA；如果 `main` 是默认分支，也会推 `latest` |
| 推送 `v1.2.3` | `v1.2.3`、`1.2.3`、`1.2`、`latest`、短提交 SHA |

## 服务器使用已发布镜像

生产服务器的 `.env` 中设置：

```env
MALLBASE_BACKEND_IMAGE=<你的DockerHub用户名>/mallbase-backend:latest
MALLBASE_WEB_IMAGE=<你的DockerHub用户名>/mallbase-web:latest
```

然后拉取并启动：

```bash
docker compose -f docker-compose.full.yml pull
docker compose -f docker-compose.full.yml up -d
```

如果你要固定版本，使用版本标签：

```env
MALLBASE_BACKEND_IMAGE=<你的DockerHub用户名>/mallbase-backend:1.2.3
MALLBASE_WEB_IMAGE=<你的DockerHub用户名>/mallbase-web:1.2.3
```

## 注意

- Docker Hub Token 应该使用 Access Token，权限至少需要能写入目标仓库。
- Web 镜像包含 Admin 和 UniApp H5；微信小程序仍由 [frontend-release-artifacts.md](./frontend-release-artifacts.md) 单独构建和发布。
- 首次推送前，可以在 Docker Hub 上创建 `mallbase-backend` 和 `mallbase-web` 两个仓库；如果账号策略允许，也可以由首次 push 自动创建。
