# 演示数据静态图（运行时目录）

本目录承载演示数据所引用的图片，会被 `mb_goods_category.image`、`mb_goods.image`、`mb_setting.client_home_banners` 等字段引用为 `/static/demo/<file>`。

## 来源

源仓库在 `deploy/install/static/demo/`。

`InstallService::copyDemoStatics()` 会在 `import_demo` 完成后把缺失的文件从源拷贝到这里：
- 已存在同名文件 → 不覆盖（保护用户已替换的图）
- 缺失文件 → 拷贝并设 `0644`

## 替换

直接覆盖同名文件即可。建议同时更新 `deploy/install/static/demo/` 下的源文件，否则下次重装会因「跳过已有」而不再同步新版本到此处。

## 命名

文件名被 SQL 演示数据直接引用，不要随意改名。详见 `deploy/install/static/demo/README.md`。
