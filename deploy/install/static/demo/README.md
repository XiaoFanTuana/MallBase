# 演示数据静态图（安装期素材源）

本目录是 MallBase 演示数据图片的**源仓库**，由仓库直接 commit。

## 安装时如何被使用

`InstallService::executeInstallation()` 在 `import_demo` 步骤之后会调用 `copyDemoStatics()`：

- 源：`deploy/install/static/demo/`（即本目录）
- 目标：`backend/public/static/demo/`
- 策略：目标已存在同名文件 → 跳过；不存在 → 拷贝并设 `0644`。
- 错误：单文件失败仅记录到步骤详情，不阻断安装。

四种安装方式都汇聚到 `InstallService::executeInstallation()`，因此本目录只维护一份即可：

| 安装方式 | 入口 | 是否走本目录 |
|---|---|---|
| Web 向导 | `InstallController::execute` | 是 |
| `php think install:auto` CLI | `app/command/InstallAuto.php` | 是 |
| Docker 仅后端 | 容器内进 Web 向导/命令 | 是（compose 已挂 `./deploy/install:/app/install`） |
| Docker 全套 | `install-auto` 容器 | 是 |
| Docker 生产 | 镜像中执行 install:auto | 仅当镜像 `COPY deploy/install` 时；当前生产 Dockerfile 不打包此目录，故生产场景默认不拷贝（与 SQL 演示数据一致）。 |

## SQL 引用对应关系

`deploy/install/data/demo/02_demo_goods.sql` 引用 `/static/demo/<file>`；
`deploy/install/data/schema/03_mb_setting.sql` 引用 `/static/demo/banner-*.png`。

| 文件 | 用途 | SQL 引用位置 |
|---|---|---|
| `cat-{phone,clothes,food,home,smartphone,tablet,menswear,womenswear,snacks,furniture}.png` | 分类卡 | `mb_goods_category.image` |
| `banner-{digital,fashion,home}.png` | 首页轮播 | `mb_setting.client_home_banners` |
| `iphone15pro{,-2,-3}.png` `mate60pro{,-2,-3}.png` `xiaomi14{,-2,-3}.png` `tshirt.png` `nuts.png` | 演示商品图 | `mb_goods.image` / `mb_goods_image.image` |

## 替换素材时

1. 在本目录覆盖同名文件。
2. 在 `backend/public/static/demo/` 同步覆盖（开源仓约定：`backend/public/static/demo/` 也 commit 一份方便 `git clone` 后立即可视）。
3. 或者：删除 `backend/public/static/demo/` 中对应文件 + 删除 `deploy/install/install.lock`，再跑一次 `php think install:auto`，由钩子重新拷贝。

## 命名规范

文件名直接被 SQL 引用，不要随意改名。如果一定要改名，需要同步修改：
- `deploy/install/data/demo/02_demo_goods.sql`
- `deploy/install/data/schema/03_mb_setting.sql`
