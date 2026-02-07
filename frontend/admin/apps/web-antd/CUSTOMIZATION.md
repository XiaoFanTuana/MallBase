# 自定义修改说明

本文档记录了应用层对框架 packages 的自定义修改，方便后续维护。

## 修改概述

为了能够自由修改核心业务逻辑，而不影响框架的 packages 包，我们在应用层创建了本地的类型定义和组件。

## 文件结构

```
apps/web-antd/
├── components/
│   └── authentication/
│       └── login.vue          # 本地登录组件（替代 packages 中的组件）
├── types/
│   └── adminInfo.ts                # 本地用户类型定义
├── store/
│   └── auth.ts               # 使用本地类型
└── views/
    └── _core/
        └── authentication/
            └── login.vue      # 使用本地登录组件
```

## 修改详情

### 1. 类型定义

**文件**: `src/types/adminInfo.ts`

- 创建了本地 `UserInfo` 接口
- 包含了业务所需的字段：`realName`、`homePath`、`permissions` 等
- 可以根据业务需求自由扩展

**原引用**:
```typescript
import type { UserInfo } from '@vben/types';
```

**新引用**:
```typescript
import type { UserInfo } from '#/types/user';
```

### 2. 登录组件

**文件**: `src/components/authentication/login.vue`

- 创建了本地登录组件 `AppAuthenticationLogin`
- 使用 Ant Design Vue 的按钮样式
- 可以自由修改登录页面的布局和交互逻辑

**原引用**:
```typescript
import { AuthenticationLogin } from '@vben/common-ui';
```

**新引用**:
```typescript
import AppAuthenticationLogin from '#/components/authentication/login.vue';
```

### 3. 认证 Store

**文件**: `src/store/auth.ts`

- 使用本地的 `UserInfo` 类型
- 调用 `getAdminInfoApi()` 获取管理员信息和权限
- 完整的登录、登出流程

### 4. 登录页面

**文件**: `src/views/_core/authentication/login.vue`

- 使用本地的 `AppAuthenticationLogin` 组件
- 定义表单 schema
- 连接 auth store

## 好处

1. **独立修改**: 可以自由修改应用层的代码，不影响 packages
2. **易于维护**: 所有自定义修改都在应用层，清晰明了
3. **升级安全**: 框架升级时不会覆盖自定义修改
4. **类型安全**: 本地类型定义确保类型安全

## 注意事项

1. 不要修改 `packages/` 目录下的文件
2. 如需修改 packages 中的组件或类型，请在应用层创建本地版本
3. 保持本地命名与 packages 中的命名有区分（如 `AppAuthenticationLogin`）
4. 定期检查 packages 的更新，评估是否需要同步修改

## 依赖关系

```
apps/web-antd (应用层)
    ↓ 使用
packages/*  (共享包 - 只读)
```

- 应用层依赖 packages 中的共享组件和工具
- 应用层创建自己的业务类型和组件
- packages 保持不变，可以安全升级