# 升级 Agent

升级 Agent 不是常驻服务。它只在管理员创建升级或回滚任务后启动一次，执行完并短暂展示只读状态页后退出。

## 实际流程

1. MallBase 后台选择目标版本并创建任务。
2. PHP 写入 `upgrade/run/requests/<job-id>.json`；文件中没有 Platform token。
3. `systemd.path` 发现请求，启动一次 `mallbase-agent run-job`。
4. Agent 从 `upgrade/config/instance.json` 读取凭据，校验升级包并执行升级或回滚。
5. Agent 把结果写入 `upgrade/jobs/<job-id>/record.json`；MallBase 后台负责长期展示记录。

`/upgrade/` 只代理当前任务的临时状态页。没有任务时端口 `18081` 不监听，这是正常状态。

## 代码和部署文件

```text
mall-base/
├── backend/
│   ├── app/controller/admin/upgrade/UpgradeController.php  # 后台升级接口
│   ├── app/service/admin/upgrade/UpgradeAdminService.php   # 创建任务和读取记录
│   ├── app/model/upgrade/UpgradeRecord.php                 # record.json 文件模型
│   └── route/api/admin/upgrade.php                         # 权限和路由
├── frontend/admin/apps/web-antd/
│   ├── src/api/system/upgrade.ts                           # PHP 升级 API 客户端
│   ├── src/views/system/upgrade/index.vue                  # 版本、操作和历史页面
│   └── src/views/_core/maintenance/index.vue               # 跳转当前临时状态页
├── deploy/
│   ├── docker/host-preflight.sh                            # 创建目录并校验权限/二进制
│   ├── systemd/mallbase-agent@.path                        # 监听一次性请求
│   ├── systemd/mallbase-agent@.service                     # 执行一次 run-job
│   └── nginx/mallbase.conf                                 # 代理 /upgrade/
└── upgrade/bin/                                            # 两种架构的 Agent 和 checksum
```

职责边界：

- 后台 PHP 是权限、目标版本、任务创建和长期历史的事实来源。
- Agent 只处理宿主机文件、发布包验签、备份和最近备份回滚。
- Platform token 只保存在 `upgrade/config/instance.json`，请求文件只保存临时页面票据的 SHA-256。
- `/upgrade/` 页面只能查看一个正在执行或刚完成的任务，不能选择版本或再次发起操作。

## 运行目录

宿主机预检脚本会创建下面的目录；源码仓库不需要保留这些空目录：

| 路径 | 写入方 | 用途 |
| --- | --- | --- |
| `upgrade/config/instance.json` | PHP | Platform 地址、token 和激活状态 |
| `upgrade/run/requests/<job-id>.json` | PHP | 等待 Agent 原子消费的一次性请求 |
| `upgrade/run/simple-upgrade.lock` | Agent | 防止两个升级进程并发执行 |
| `upgrade/jobs/<job-id>/record.json` | PHP 创建、Agent 更新 | 后台长期任务记录 |
| `upgrade/backups/` | Agent | 按任务保存的代码备份 |
| `upgrade/packages/` | Agent | 已下载的发布包 |
| `upgrade/staging/` | Agent | 验证后的独占解压目录 |
| `upgrade/agent-private/` | Agent | 不与 PHP 共享的恢复检查点 |

不要手工创建 `0777` 目录或修改任务 JSON。目录所有者、共享组和权限不符合约定时，Agent 会拒绝运行。

## 安装 systemd 单元

下面命令假设项目绝对路径是 `/srv/mallbase`。Agent 用户和共享组只需创建一次：

```bash
sudo groupadd --system mallbase-upgrade
sudo useradd --system --gid mallbase-upgrade --home-dir /nonexistent --shell /usr/sbin/nologin mallbase-agent

cd /srv/mallbase
sudo MALLBASE_AGENT_USER=mallbase-agent sh deploy/docker/host-preflight.sh
sudo install -m 0644 deploy/systemd/mallbase-agent@.service /etc/systemd/system/
sudo install -m 0644 deploy/systemd/mallbase-agent@.path /etc/systemd/system/

INSTANCE=$(systemd-escape --path /srv/mallbase)
sudo systemctl daemon-reload
sudo systemctl enable --now "mallbase-agent@${INSTANCE}.path"
```

如果用户或组已经存在，跳过对应的创建命令。Docker 后端通过根目录 `.env` 中的 `MALLBASE_UPGRADE_SHARED_GID` 与 Agent 共享任务目录。

## 验证

```bash
INSTANCE=$(systemd-escape --path /srv/mallbase)
sudo systemctl status "mallbase-agent@${INSTANCE}.path"
sudo journalctl -u "mallbase-agent@${INSTANCE}.service" -n 100 --no-pager
```

常见情况：

- 后台提示 Agent 未及时启动：先检查 `.path` 是否启用，再看 `.service` 日志。
- `HOST_PREFLIGHT_*`：目录所有者、共享组或权限不符合约定，重新执行预检，不要手工放宽到 `0777`。
- `/upgrade/` 返回 `502`：当前没有任务，或任务进程已结束；长期记录请在 MallBase 后台查看。
