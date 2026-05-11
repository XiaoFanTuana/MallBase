# MallBase UniApp H5 打包说明

本文档说明 MallBase UniApp 项目的 H5 一键打包方式，以及根目录打包配置和产物位置。

## 打包文件位置

本项目的 H5 打包配置放在仓库根目录下：

本项目对应文件为：

- [`docker-compose.uniapp-build.yml`](../../docker-compose.uniapp-build.yml)

配套脚本：

- [`deploy/docker/uniapp-build.sh`](../../deploy/docker/uniapp-build.sh)

## 手动触发

在仓库根目录执行：

```bash
docker compose -f docker-compose.uniapp-build.yml up uniapp-build
```

## 构建流程

打包容器会在 `frontend/uniapp` 下执行以下步骤：

1. 安装依赖：`npm ci`
2. 执行 H5 构建：`npm run build:h5`

## 产物位置

构建完成后会生成以下内容：

- UniApp 原始 H5 目录：`frontend/uniapp/dist/build/h5`
- 对外发布目录：`backend/public/client`

`docker-compose.uniapp-build.yml` 会在构建完成后自动把 `frontend/uniapp/dist/build/h5` 同步到 `backend/public/client`。如果 `backend/public/client` 不存在，Docker 会自动创建该目录。

## 本地构建

本地也可以直接执行：

```bash
cd frontend/uniapp
npm ci
npm run build:h5
```

本地构建完成后，H5 产物同样会输出到 `dist/build/h5`。

如果本地不通过 Docker Compose 打包，需要手动同步到发布目录：

```bash
mkdir -p backend/public/client
rm -rf backend/public/client/*
cp -r frontend/uniapp/dist/build/h5/. backend/public/client/
```

## 域名与接口地址

生产环境 H5 默认使用同源相对接口：

```ini
VITE_UNIAPP_BASE_URL=
VITE_UNIAPP_API_PREFIX=/client/api
```

因此访问 `https://mall.example.com` 时，H5 会请求当前域名下的 `/client/api/...`，再由 Nginx 反向代理到后端 Swoole。

`frontend/uniapp/vite.config.js` 里的 `base` 应保持 `/client/`，这样构建产物里的 JS/CSS 引用前缀是 `/client/assets/...`，与 Nginx 的 `/client/` 静态托管路径一致。

## 相关文档

- 部署到服务器：[upload-frontend.md](./upload-frontend.md)（`deploy/upload-frontend.sh` 会在存在 `backend/public/client` 时一并上传 H5）
- Nginx 路径规则：[nginx-reverse-proxy.md](./nginx-reverse-proxy.md)（`/client/` 静态托管、`/client/api/` 反向代理）
