# ThinkPHP 规则：mall_base 框架边界

## 适用范围

涉及 `backend/mall_base/` 目录的任何新增或修改。

## 定位

`mall_base/` 是项目的**框架底层**，提供通用基础设施，不包含任何业务逻辑。  
业务逻辑统一放在 `backend/app/` 层。

## 目录职责

| 目录 | 定位 | 允许放什么 | 禁止放什么 |
|------|------|-----------|-----------|
| `mall_base/base/` | 基类 | BaseDriver、BaseModel、BaseException 等抽象基类 | 业务服务、具体实现 |
| `mall_base/drivers/` | 驱动 | 通过 DriverManager 管理的驱动实现（SMS、Upload 等） | 业务编排、频控、缓存策略 |
| `mall_base/exception/` | 异常 | 统一异常类（BusinessException、SmsException 等） | 业务逻辑 |
| `mall_base/enum/` | 枚举 | 框架级枚举与常量 | 业务场景枚举 |

## 业务逻辑归属

| 组件类型 | 正确位置 | 示例 |
|----------|---------|------|
| 业务服务 | `app/service/` | SmsService、UploadService |
| 业务编排 | `app/service/client/` 或 `app/service/admin/` | SmsAuthService、UserService |
| 频控/缓存策略 | `app/service/<domain>/` | SmsRateLimiter、SmsCache |
| 控制器 | `app/controller/` | UserController |
| 场景常量 | `app/service/<domain>/` 或 `app/enum/` | SmsScene |

## 驱动开发规范

新增驱动时遵循 Upload 驱动的已有模式：

1. 在 `mall_base/drivers/<type>/` 下新建 `Base<Type>Driver`（继承 BaseDriver）和具体驱动类。
2. 在 `app/AppService.php` 的 `register()` 中注册驱动并设置默认值。
3. 在 `app/provider.php` 中通过 `DriverManager::driver()` 获取实例注入业务服务。
4. 驱动只负责底层调用（SDK、API），返回 `bool` + `getError()`，不做业务判断。
5. 业务服务（在 `app/service/`）负责编排逻辑、频控、缓存、异常转换。

## 禁止项

- ❌ 在 `mall_base/` 下新建业务模块目录（如 ~~`mall_base/sms/`~~、~~`mall_base/order/`~~）。
- ❌ 在 `mall_base/` 中编写业务编排、频控策略、缓存策略等业务逻辑。
- ❌ 绕过 DriverManager 自行在 `mall_base/` 中定义 Adapter/Interface 做驱动选择。
- ❌ 把异常类散放在业务目录，不归入 `mall_base/exception/`。

## 自检清单

- [ ] 新增的类是否属于框架基础设施？不是则放 `app/`。
- [ ] 新增的驱动是否在 AppService 中注册？
- [ ] 异常类是否放在 `mall_base/exception/` 下？
- [ ] 驱动类是否只做底层调用，不含业务判断？
- [ ] 业务服务是否在 `app/service/` 下？

## 事故参考

SMS 子系统曾在 `mall_base/sms/` 下放置了 SmsService、SmsRateLimiter、SmsAdapter 等业务组件，同时与 `mall_base/drivers/sms/` 的已有驱动形成重复架构。此 skill 确保不再出现类似的分层混乱。
