# Docker Hub 多架构镜像发布

本项目通过 GitHub Actions 自动构建并推送后端生产镜像到 Docker Hub。

## 触发方式

`.github/workflows/docker-publish.yml` 会在以下场景运行：

- 推送到 `main` 或 `master`
- 推送版本标签，例如 `v1.0.0`
- 在 GitHub Actions 页面手动运行 `Docker Publish`

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
| `DOCKERHUB_REPOSITORY` | `mallbase-backend` | Docker Hub 仓库名 |

默认镜像名为：

```text
docker.io/<DOCKERHUB_USERNAME>/mallbase-backend
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
```

然后拉取并启动：

```bash
docker compose pull backend
docker compose up -d
```

如果你要固定版本，使用版本标签：

```env
MALLBASE_BACKEND_IMAGE=<你的DockerHub用户名>/mallbase-backend:1.2.3
```

## 注意

- Docker Hub Token 应该使用 Access Token，权限至少需要能写入目标仓库。
- 后端镜像只包含 PHP/Swoole 后端和镜像构建时已有的静态文件；后台 Admin、UniApp H5 和微信小程序制品由 [frontend-release-artifacts.md](./frontend-release-artifacts.md) 单独构建。
- 首次推送前，Docker Hub 上可以先手动创建 `mallbase-backend` 仓库；如果账号策略允许，也可以由首次 push 自动创建。
