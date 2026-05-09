# 前端静态资源上传脚本

## 适用场景

- 本地已经生成 `backend/public/admin`
- 如需发布 H5，本地已经生成 `backend/public/client`
- 需要把后台和 H5 静态资源上传到服务器
- 希望避免手工 `scp` 多个文件或遗漏旧文件

## 脚本位置

```bash
deploy/upload-public-admin.sh
```

脚本会：

1. 打包本地 `backend/public/admin`
2. 如果 `backend/public/client/index.html` 存在，则一并打包 H5
3. 上传到服务器临时目录
4. 解压到服务器目标目录
5. 默认清空目标目录旧文件，避免静态资源残留

## 使用前准备

### 1. 本地已经有构建产物

确认本地目录存在：

```bash
ls backend/public/admin/index.html
```

如果不存在，先生成后台前端资源，例如：

```bash
docker compose -f docker-compose.frontend-build.yml up frontend-build
```

如果需要同时发布 H5，确认本地 H5 产物存在：

```bash
ls backend/public/client/index.html
```

如果不存在，先生成 UniApp H5：

```bash
docker compose -f docker-compose.uniapp-build.yml up uniapp-build
```

### 2. 本机可通过 SSH 登录服务器

脚本依赖以下命令：

- `tar`
- `scp`
- `ssh`

## 用法

### 方式一：直接指定服务器目标目录

适合服务器用 Nginx 直接托管静态文件，例如：

- 后台：`/var/www/mallbase/admin`
- H5：`/var/www/mallbase/client`

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

使用 `--remote-dir` 时，如果本地存在 `backend/public/client/index.html`，脚本默认会把 H5 上传到后台目录的同级 `client` 目录，即 `/var/www/mallbase/client`。

### 方式二：指定服务器项目根目录

适合服务器上也有 MallBase 项目目录，希望自动上传到对应的 `backend/public/admin` 和 `backend/public/client`。

```bash
sh deploy/upload-public-admin.sh \
  --host root@server \
  --remote-root /www/wwwroot/example.com/mall-base
```

上面这条命令最终会上传到：

```bash
/www/wwwroot/example.com/mall-base/backend/public/admin
/www/wwwroot/example.com/mall-base/backend/public/client
```

### 可选参数

```bash
--port 22
```

自定义 SSH 端口，例如：

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --port 2222 \
  --remote-dir /var/www/mallbase/admin
```

```bash
--identity ~/.ssh/your_key
```

如果服务器只允许私钥登录，可以显式指定 SSH 私钥文件：

```bash
sh deploy/upload-public-admin.sh \
  --host root@165.154.60.251 \
  --identity ~/.ssh/id_ed25519 \
  --remote-root /www/wwwroot/mallbase.gosowong.cn/mall-base
```

```bash
--keep-extra
```

默认脚本会先清空服务器目标目录，再解压新文件。如果你想保留服务器目录里额外文件，可以加这个参数：

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin \
  --keep-extra
```

```bash
--client-dir /var/www/mallbase/client
```

如果 H5 目录不是后台目录的同级 `client`，可以显式指定：

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin \
  --client-dir /data/www/mallbase-h5
```

## 推荐用法

### Nginx 直接托管静态文件

```bash
sh deploy/upload-public-admin.sh \
  --host user@server \
  --remote-dir /var/www/mallbase/admin
```

对应现有部署文档中的 Nginx 配置，后台静态目录通常是 `/var/www/mallbase/admin`，H5 静态目录通常是 `/var/www/mallbase/client`。

### Docker 生产环境，代码目录里托管后台静态文件

```bash
sh deploy/upload-public-admin.sh \
  --host root@server \
  --remote-root /www/wwwroot/mallbase.gosowong.cn/mall-base
```

如果这台服务器只接受密钥登录，补上 `--identity`：

```bash
sh deploy/upload-public-admin.sh \
  --host root@165.154.60.251 \
  --identity ~/.ssh/id_ed25519 \
  --remote-root /www/wwwroot/mallbase.gosowong.cn/mall-base
```

## 注意事项

- 本地源目录固定是 `backend/public/admin`
- 如果 `backend/public/client/index.html` 存在，脚本会同时上传 H5
- `--remote-dir` 和 `--remote-root` 必须二选一
- `--client-dir` 可覆盖 H5 的远端目录
- 默认会清空服务器目标目录内容，避免旧版本资源残留
- 如果你确认服务器目录里还有其他需要保留的文件，再使用 `--keep-extra`
- 如果你没有 root 密码、只持有私钥，请使用 `--identity`

## 常见问题

### 服务器目录上传后还是旧页面

先确认脚本上传到了你实际在用的目录，再检查：

- Nginx 静态目录是否就是这个路径
- 浏览器是否缓存了旧资源
- `_app.config.js` 是否还是旧配置

### 域名打开不是 H5 页面

按当前 Nginx 示例，域名根路径应该直接进入 H5，`/admin` 才进入后台。请检查：

- H5 目录是否存在 `index.html`
- Nginx 的 `root` 是否指向 H5 目录
- `/client/api/` 是否仍然代理到后端，而不是被当成静态文件

### 为什么脚本默认先清空目标目录

前端静态资源通常带 hash 文件名。如果只做追加上传，旧版本文件可能残留，出现资源引用混用或页面异常。默认清空再解压更稳妥。
