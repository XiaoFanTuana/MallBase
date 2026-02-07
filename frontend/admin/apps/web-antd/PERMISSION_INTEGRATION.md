# 权限模块对接说明

## 概述

本文档说明 Vue Vben Admin 与后端权限模块的对接情况，包括接口封装、按钮级权限控制等功能。

## 一、API 接口封装

### 1. 认证相关接口 (`api/core/auth.ts`)

```typescript
import { loginApi, getUserInfoApi, getAccessCodesApi, logoutApi } from '#/api/core';

// 登录
const { accessToken } = await loginApi({ username: 'admin', password: '123456' });

// 获取用户信息
const userInfo = await getUserInfoApi();

// 获取用户权限码（按钮级权限）
const permissions = await getAccessCodesApi();

// 退出登录
await logoutApi();
```

**后端接口说明：**
- 登录：`POST /admin/api/auth/admin/login`，参数：`{ username, password }`
- 获取用户信息：`GET /admin/api/auth/admin/info`，可选参数：`{ id }`
- 获取权限码：`GET /admin/api/auth/admin/permissions`
- 刷新token：`POST /admin/api/auth/refresh`
- 退出登录：`POST /admin/api/auth/logout`

### 2. 权限管理接口 (`api/core/permission.ts`)

```typescript
import {
  getPermissionTreeApi,
  getPermissionListApi,
  getPermissionInfoApi,
  createPermissionApi,
  updatePermissionApi,
  deletePermissionApi
} from '#/api/core';

// 获取权限树
const tree = await getPermissionTreeApi({ type: 'menu', status: 1 });

// 获取权限列表
const list = await getPermissionListApi({ page: 1, limit: 15 });

// 获取权限详情
const info = await getPermissionInfoApi(1);

// 创建权限
await createPermissionApi({
  name: '用户管理',
  code: 'user',
  type: 'menu',
  path: '/user',
  icon: 'lucide:users',
  component: 'views/user/index',
  parent_id: 0,
  status: 1,
  is_show: 1
});

// 更新权限
await updatePermissionApi({
  id: 1,
  name: '用户管理',
  code: 'user',
  type: 'menu',
  // ...
});

// 删除权限
await deletePermissionApi(1);
```

### 3. 角色管理接口 (`api/core/role.ts`)

```typescript
import {
  getRoleListApi,
  getAllRolesApi,
  getRoleInfoApi,
  createRoleApi,
  updateRoleApi,
  deleteRoleApi
} from '#/api/core';

// 获取角色列表
const list = await getRoleListApi({ page: 1, limit: 15 });

// 获取所有角色（用于下拉选择）
const allRoles = await getAllRolesApi();

// 获取角色详情
const info = await getRoleInfoApi(1);

// 创建角色
await createRoleApi({
  name: '管理员',
  code: 'admin',
  status: 1,
  sort: 1,
  remark: '系统管理员',
  permission_ids: [1, 2, 3]
});

// 更新角色
await updateRoleApi({
  id: 1,
  name: '超级管理员',
  // ...
});

// 删除角色
await deleteRoleApi(1);
```

### 4. 管理员管理接口 (`api/core/admin.ts`)

```typescript
import {
  getAdminListApi,
  getAdminInfoApi,
  createAdminApi,
  updateAdminApi,
  deleteAdminApi
} from '#/api/core';

// 获取管理员列表
const list = await getAdminListApi({ username: 'admin', status: 1 });

// 获取管理员详情
const info = await getAdminInfoApi(1);

// 创建管理员
await createAdminApi({
  username: 'test',
  password: '123456',
  password_confirm: '123456',
  nickname: '测试用户',
  email: 'test@example.com',
  mobile: '13800138000',
  avatar: 'https://example.com/avatar.jpg',
  status: 1,
  remark: '测试账号',
  role_ids: [1, 2]
});

// 更新管理员
await updateAdminApi({
  id: 1,
  nickname: '管理员',
  // ...
});

// 删除管理员
await deleteAdminApi(1);
```

### 5. 菜单接口 (`api/core/menu.ts`)

```typescript
import { getAllMenusApi } from '#/api/core';

// 获取用户所有菜单（自动从权限树转换为路由格式）
const menus = await getAllMenusApi();
```

**说明：** 菜单数据从后端权限树接口获取，系统会自动过滤出 `type='menu'` 且 `is_show=1` 的权限项，并转换为前端路由格式。

## 二、按钮级权限控制

### 1. 使用 v-auth 指令

```vue
<template>
  <!-- 单个权限控制 -->
  <a-button v-auth="'user:create'">创建用户</a-button>
  
  <!-- 多个权限控制（满足任一即可） -->
  <a-button v-auth="['user:create', 'user:update']">
    创建或更新用户
  </a-button>
  
  <!-- 表格操作列 -->
  <a-table :columns="columns" :data-source="data">
    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'action'">
        <a-space>
          <a-button 
            v-auth="'user:edit'" 
            type="link" 
            size="small"
            @click="handleEdit(record)"
          >
            编辑
          </a-button>
          <a-button 
            v-auth="'user:delete'" 
            type="link" 
            size="small" 
            danger
            @click="handleDelete(record)"
          >
            删除
          </a-button>
        </a-space>
      </template>
    </template>
  </a-table>
</template>
```

### 2. 权限码说明

后端权限表中的 `code` 字段即为权限码，格式通常为：`资源:操作`

示例：
- `user:create` - 创建用户权限
- `user:update` - 更新用户权限
- `user:delete` - 删除用户权限
- `user:view` - 查看用户权限
- `role:create` - 创建角色权限
- `permission:edit` - 编辑权限权限

### 3. 指令实现原理

权限指令会检查当前用户的权限码列表（存储在 `accessStore.accessCodes` 中），如果用户拥有所需的权限码，则显示元素；否则移除元素。

```typescript
// 指令位置：src/directives/permission.ts
const permission: Directive = {
  mounted(el, binding) {
    const accessStore = useAccessStore();
    const accessCodes = accessStore.accessCodes || [];
    const requiredPermissions = Array.isArray(binding.value) 
      ? binding.value 
      : [binding.value];
    
    const hasPermission = requiredPermissions.some(permission => 
      accessCodes.includes(permission)
    );
    
    if (!hasPermission) {
      el.parentNode?.removeChild(el);
    }
  }
};
```

## 三、请求配置

### 1. 响应数据格式处理

后端返回的数据格式：
```json
{
  "code": 0,
  "message": "成功",
  "data": {
    "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "token_type": "Bearer",
    "expires_in": 7200
  }
}
```

前端会自动处理：
1. 将 `access_token` 转换为 `accessToken`（符合 Vben 规范）
2. 将 `code: 0` 识别为成功状态
3. 提取 `data` 字段作为响应数据

### 2. 请求头配置

所有请求会自动添加以下请求头：
```typescript
{
  Authorization: 'Bearer {access_token}',
  'Accept-Language': 'zh-CN'
}
```

### 3. Token 刷新机制

当 token 过期时，系统会自动调用刷新接口获取新 token，无需手动处理。

## 四、类型定义

所有 API 都有完整的 TypeScript 类型定义，使用时可以获得良好的类型提示。

### 示例：使用类型定义

```typescript
import type { PermissionApi, RoleApi, AdminApi } from '#/api/core';

// PermissionApi.PermissionItem
const permission: PermissionApi.PermissionItem = {
  id: 1,
  name: '用户管理',
  code: 'user',
  type: 'menu',
  path: '/user',
  // ...
};

// RoleApi.RoleItem
const role: RoleApi.RoleItem = {
  id: 1,
  name: '管理员',
  code: 'admin',
  // ...
};

// AdminApi.AdminItem
const admin: AdminApi.AdminItem = {
  id: 1,
  username: 'admin',
  nickname: '管理员',
  // ...
};
```

## 五、注意事项

1. **权限码必须一致**：后端权限表的 `code` 字段必须与前端 `v-auth` 指令中使用的权限码一致。

2. **按钮权限类型**：按钮级权限在数据库中的 `type` 字段应设置为 `button`，菜单权限设置为 `menu`。

3. **菜单组件路径**：后端权限表的 `component` 字段应为前端组件的路径，例如：`views/user/index`。

4. **权限树结构**：后端返回的权限树应包含父子关系，前端会根据 `parent_id` 自动构建树形结构。

5. **权限缓存**：用户权限码在登录后获取并缓存，退出登录时会清除。

## 六、快速开始

1. 在组件中使用 API：
```typescript
import { getAdminListApi } from '#/api/core';

const loadData = async () => {
  const data = await getAdminListApi({ page: 1, limit: 10 });
  console.log(data);
};
```

2. 在模板中使用权限指令：
```vue
<a-button v-auth="'user:create'">创建用户</a-button>
```

3. 获取当前用户权限：
```typescript
import { useAccessStore } from '@vben/stores';

const accessStore = useAccessStore();
const permissions = accessStore.accessCodes; // 权限码数组
```

## 七、后端接口要求

确保后端实现以下接口：

| 接口路径 | 方法 | 说明 |
|---------|------|------|
| `/admin/api/auth/admin/login` | POST | 管理员登录 |
| `/admin/api/auth/admin/info` | GET | 获取管理员信息 |
| `/admin/api/auth/admin/permissions` | GET | 获取管理员权限码 |
| `/admin/api/auth/admin/list` | GET | 管理员列表 |
| `/admin/api/auth/admin/create` | POST | 创建管理员 |
| `/admin/api/auth/admin/update` | POST | 更新管理员 |
| `/admin/api/auth/admin/delete` | POST | 删除管理员 |
| `/admin/api/auth/permission/tree` | GET | 权限树 |
| `/admin/api/auth/permission/list` | GET | 权限列表 |
| `/admin/api/auth/permission/create` | POST | 创建权限 |
| `/admin/api/auth/permission/update` | POST | 更新权限 |
| `/admin/api/auth/permission/delete` | POST | 删除权限 |
| `/admin/api/auth/role/list` | GET | 角色列表 |
| `/admin/api/auth/role/all` | GET | 所有角色 |
| `/admin/api/auth/role/create` | POST | 创建角色 |
| `/admin/api/auth/role/update` | POST | 更新角色 |
| `/admin/api/auth/role/delete` | POST | 删除角色 |

## 八、常见问题

**Q: 为什么按钮不显示？**
A: 检查以下几点：
1. 用户是否拥有该权限码
2. 后端权限表的 `code` 字段是否正确
3. 权限码是否在 `accessStore.accessCodes` 中
4. 控制台是否有错误信息

**Q: 菜单不显示？**
A: 检查以下几点：
1. 权限的 `type` 是否为 `menu`
2. 权限的 `is_show` 是否为 `1`
3. 组件路径 `component` 是否正确
4. 用户是否拥有该菜单权限

**Q: 登录后获取不到权限？**
A: 检查：
1. 登录接口是否返回了 token
2. `getAccessCodesApi` 接口是否正常
3. 后端是否正确返回用户权限列表
