# MallBase

MallBase 是一个 **面向中小型商城业务的基础后端框架**，以 **ThinkPHP + think-swoole** 为核心，提供一套 **清晰、可扩展、适合长期维护** 的项目骨架。

项目目标不是做“功能最全的商城”，而是提供一个：

- 结构合理
- 技术选型稳定
- 易于二次开发
- 适合团队协作

的 **商城型应用基础底座**。

---

## ✨ 特性

- 🚀 **ThinkPHP + think-swoole**：高性能、长驻内存
- 🧱 **模块化设计**：用户 / 商品 / 订单 / 权限
- 🐳 **Docker 友好**：一键部署，环境一致
- 🎯 **前后端分离**：支持 Admin 管理端 + UniApp
- 🔒 **工程优先**：强调稳定、可维护，而非堆功能

---

## 📦 技术栈

### 后端

- PHP >= 8.1
- ThinkPHP
- think-swoole
- Swoole（官方扩展）
- MySQL / MariaDB
- Redis

### 前端

- Admin：Vue3 / Vite（推荐）
- 移动端：UniApp

### 部署

- Docker / Docker Compose
- Nginx

---

## 📁 项目结构

```text
mall-base/
├── backend/                    # 后端（ThinkPHP + think-swoole）
│   ├── app/
│   ├── config/
│   ├── route/
│   ├── public/
│   ├── runtime/
│   └── composer.json
│
├── frontend/
│   ├── admin/                  # 后台管理前端
│   └── uniapp/                 # UniApp 项目
│
├── deploy/
│   ├── docker/
│   │   ├── php/
│   │   ├── nginx/
│   │   └── mysql/
│   └── docker-compose.yml
│
├── docs/                       # 文档
├── .env.example
├── .gitignore
├── LICENSE
└── README.md
```

---

## 🧩 模块说明

### User（用户模块）

- 用户注册 / 登录
- 用户信息管理
- 状态控制

### Product（商品模块）

- 商品基础信息
- SKU / 库存
- 上下架控制

### Order（订单模块）

- 下单流程
- 订单状态流转
- 支付 / 取消（预留）

### Auth（权限模块）

- 登录认证
- Token 管理
- 后台权限控制

---

## 🔌 API 风格（示例）

```http
POST   /api/auth/login
GET    /api/users
GET    /api/products
POST   /api/orders
```

Admin API 与用户 API 分离，便于权限与限流控制。

---

## 🐳 Docker 运行（示例）

```bash
docker-compose up -d
```

后端容器默认运行：

```bash
php think swoole:start
```

---

## 🚧 开发约定

- 长驻内存环境，**禁止使用全局状态**
- Service 层无 IO 副作用
- 模块间禁止直接依赖 Model
- 严禁在 Controller 中写业务逻辑

---

## 📜 开源协议

本项目基于 **MIT License** 开源。

你可以自由地：

- 使用
- 修改
- 二次开发
- 商业使用

只需保留原作者版权声明。

---

## 🤝 适合人群

- 想做商城 / 业务系统的个人开发者
- 中小团队后端基础架构
- 希望有一个「不臃肿」商城底座的人

---

## 📌 项目定位声明

> MallBase 不是一个开箱即用的完整商城，
> 而是一个 **可控、可扩展、适合工程化演进的基础框架**。

如果你需要：
- 快速定制
- 自由裁剪
- 明确结构

那么 MallBase 就是为你准备的。

---

## 📬 交流与反馈

欢迎提交 Issue / PR，一起把 MallBase 打磨成一个 **干净、好用、不坑人的商城基础框架**。

