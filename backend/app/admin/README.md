# 后台管理权限模块

## 目录结构

```
backend/app/admin/
├── controller/auth/          # 控制器层
│   ├── AdminController.php   # 管理员控制器
│   ├── RoleController.php    # 角色控制器
│   └── PermissionController.php  # 权限控制器
├── service/auth/           # 服务层
│   ├── AdminService.php    # 管理员服务
│   ├── RoleService.php     # 角色服务
│   └── PermissionService.php # 权限服务
├── model/auth/            # 模型层
│   ├── Admin.php          # 管理员模型
│   ├── Role.php           # 角色模型
│   ├── Permission.php      # 权限模型
│   ├── AdminRole.php      # 管理员角色关联模型
│   ├── RolePermission.php  # 角色权限关联模型
│   └── AdminLog.php      # 管理员日志模型
└── README.md

backend/route/admin/
├── auth/                 # 权限模块路由
│   ├── admin.php         # 管理员路由
│   ├── role.php          # 角色路由
│   └── permission.php    # 权限路由
└── database/            # 数据库脚本
    └── mb_auth.sql      # 权限模块表结构
```

## 数据库表

### 1. mb_admin - 管理员表
- 用户名、密码、昵称、头像、邮箱、手机号
- 状态管理（启用/禁用）
- 登录信息记录（最后登录时间、IP）
- 密码使用 password_hash 加密

### 2. mb_role - 角色表
- 角色名称、编码
- 状态管理
- 排序支持

### 3. mb_permission - 权限表
- 树形结构（parent_id）
- 权限类型：1=菜单 2=按钮 3=接口
- 路由路径、图标、组件名称
- 显示/隐藏控制

### 4. mb_admin_role - 管理员角色关联表
- 多对多关系

### 5. mb_role_permission - 角色权限关联表
- 多对多关系

### 6. mb_admin_log - 管理员操作日志表
- 记录管理员的操作行为
- 记录请求信息（URL、方法、参数）
- 记录客户端信息（IP、User-Agent）

## API 接口

### 管理员接口 (admin/auth/admin)

| 接口 | 方法 | 说明 |
|------|------|------|
| /admin/auth/admin/login | POST | 登录 |
| /admin/auth/admin/list | GET | 获取管理员列表 |
| /admin/auth/admin/info | GET | 获取管理员详情 |
| /admin/auth/admin/create | POST | 创建管理员 |
| /admin/auth/admin/update | POST | 更新管理员 |
| /admin/auth/admin/delete | POST | 删除管理员 |

### 角色接口 (admin/auth/role)

| 接口 | 方法 | 说明 |
|------|------|------|
| /admin/auth/role/list | GET | 获取角色列表 |
| /admin/auth/role/all | GET | 获取所有角色 |
| /admin/auth/role/info | GET | 获取角色详情 |
| /admin/auth/role/create | POST | 创建角色 |
| /admin/auth/role/update | POST | 更新角色 |
| /admin/auth/role/delete | POST | 删除角色 |

### 权限接口 (admin/auth/permission)

| 接口 | 方法 | 说明 |
|------|------|------|
| /admin/auth/permission/tree | GET | 获取权限树 |
| /admin/auth/permission/list | GET | 获取权限列表 |
| /admin/auth/permission/info | GET | 获取权限详情 |
| /admin/auth/permission/create | POST | 创建权限 |
| /admin/auth/permission/update | POST | 更新权限 |
| /admin/auth/permission/delete | POST | 删除权限 |

## 安装步骤

### 1. 导入数据库表结构

```bash
mysql -u root -p your_database < route/admin/database/mb_auth.sql
```

### 2. 加载路由文件

在 `backend/route/app.php` 中添加：

```php
require_once __DIR__ . '/admin/auth/admin.php';
require_once __DIR__ . '/admin/auth/role.php';
require_once __DIR__ . '/admin/auth/permission.php';
```

### 3. 默认账号

- 用户名：admin
- 密码：admin123
- **注意**：生产环境请务必修改默认密码

## 使用示例

### 管理员登录

```bash
curl -X POST http://localhost/admin/auth/admin/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}'
```

### 获取管理员列表

```bash
curl http://localhost/admin/auth/admin/list?page=1&limit=10
```

### 创建角色

```bash
curl -X POST http://localhost/admin/auth/role/create \
  -H "Content-Type: application/json" \
  -d '{
    "name": "编辑",
    "code": "editor",
    "remark": "编辑权限",
    "permission_ids": [1, 2, 3]
  }'
```

### 获取权限树

```bash
curl http://localhost/admin/auth/permission/tree
```

## 响应格式

所有接口统一返回格式：

```json
{
  "code": 200,
  "msg": "成功",
  "data": {
    // 具体数据
  }
}
```

错误响应：

```json
{
  "code": 400,
  "msg": "错误信息"
}
```

## 注意事项

1. **密码安全**：密码使用 PHP 的 password_hash 和 password_verify 进行加密和验证
2. **Token**：当前使用简单的 base64 编码，生产环境建议使用 JWT
3. **权限验证**：当前代码未实现权限验证中间件，需要根据实际需求添加
4. **跨域**：路由已配置跨域支持
5. **数据验证**：建议添加请求参数验证（Validator）

## 后续优化建议

1. 添加 JWT Token 认证中间件
2. 添加权限验证中间件
3. 添加请求参数验证
4. 添加操作日志中间件
5. 完善管理员信息修改接口
6. 添加密码修改接口
7. 添加角色分配权限的独立接口
8. 添加管理员分配角色的独立接口
9. 添加权限批量操作接口