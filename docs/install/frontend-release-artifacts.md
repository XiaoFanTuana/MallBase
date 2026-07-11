# 前端发布制品自动构建

本项目通过 GitHub Actions 自动构建后台 Admin、UniApp H5 和微信小程序三类前端制品。后端 Docker 镜像仍保持单一职责，不包含这三类运行时构建产物。

## 触发方式

`.github/workflows/frontend-release.yml` 会在以下场景运行：

- 推送版本标签，例如 `v1.0.0`
- 在 GitHub Actions 页面手动运行 `Frontend Release Artifacts`

持续集成工作流 `.github/workflows/ci.yml` 也会在分支推送和 Pull Request 中实际构建三类前端，用于提前发现生产构建失败。

## GitHub Variables

在 fork 仓库中进入：

```text
Settings -> Secrets and variables -> Actions -> Variables
```

新增以下 Repository variables：

| 名称 | 示例 | 用途 |
|------|------|------|
| `UNIAPP_WEIXIN_APPID` | `wx1234567890abcdef` | 写入微信小程序制品的 `project.config.json` |
| `UNIAPP_MINIAPP_BASE_URL` | `https://mall.example.com` | 编译进小程序的后端 HTTPS Origin |

`UNIAPP_MINIAPP_BASE_URL` 必须满足以下要求：

- 使用 `https://`
- 只填写 Origin，不带路径、查询参数、片段或末尾 `/`
- 对应域名已在微信公众平台配置为合法 request 域名

微信 AppSecret 只应配置在后端系统设置中，不能放进 GitHub Variables、前端环境变量或小程序制品。

手动运行工作流时，也可以通过输入框临时覆盖这两个 Repository variables。

## 构建制品

工作流完成后，在对应 Actions Run 的 `Artifacts` 区域下载：

| 制品 | 内容 | 发布位置 |
|------|------|----------|
| `mallbase-admin-<run_number>` | Admin 静态文件 | Nginx 的 `/admin/` 目录 |
| `mallbase-h5-<run_number>` | UniApp H5 静态文件 | Nginx 的 `/client/` 目录 |
| `mallbase-mp-weixin-<run_number>` | 微信小程序项目 | 微信开发者工具导入并上传审核 |

制品默认保留 30 天。正式版本应同时保留对应 Git 标签，确保可以从同一提交重新构建。

## 推荐发布顺序

1. 确认 CI 的 Backend、Admin 和 UniApp 作业全部通过。
2. 创建并推送版本标签，例如 `v1.0.0`。
3. 等待 `Docker Publish` 和 `Frontend Release Artifacts` 完成。
4. 服务器使用固定版本的后端镜像，不直接依赖 `latest`。
5. 将 Admin 和 H5 制品发布到 Nginx 对应目录。
6. 用微信开发者工具导入小程序制品，完成真机测试、隐私配置、上传和审核。

后端镜像发布见 [docker-image-publish.md](./docker-image-publish.md)，生产部署见 [docker-production.md](./docker-production.md)。

## 小程序发布前检查

- 微信小程序 AppID 与后台 `WechatMiniProgram` 配置属于同一个主体和应用。
- request、uploadFile、downloadFile 等合法域名均使用有效 HTTPS 证书。
- `UNIAPP_MINIAPP_BASE_URL` 与生产站点实际域名一致。
- 微信登录、支付回调、退款、图片上传和订单状态已在真机环境验证。
- 微信公众平台的用户隐私保护指引与代码实际使用的能力一致。

GitHub Actions 只负责生成可发布项目，不会代替微信公众平台的上传、审核和发布流程。
