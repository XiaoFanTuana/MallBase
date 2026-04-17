# Claude Code 使用指南

> 本项目基于 `everything-claude-code` 插件 + `superpowers` 插件。
> 以下为当前环境的 Skills、MCP 工具、多 Agent 协作能力一览。

---

## 一、Skills（技能命令）

通过 `/skill-name` 调用，或在对话中自动触发。

### 开发流程

| 命令 | 用途 | 使用案例 |
|------|------|----------|
| `/plan` | 需求分析 + 实现计划（进入 Plan Mode） | "给用户模块加角色权限系统，先 /plan 分析方案" |
| `/tdd` | TDD 工作流：先写测试再实现 | "给 OrderService::create 写单元测试，/tdd 引导先写测试用例" |
| `/code-review` | 代码审查（支持本地 diff 或 GitHub PR） | "/code-review 审查刚写的 UserService" 或 "/code-review PR#42" |
| `/prp-plan` | 从需求到实现的全链路计划 | "用 /prp-plan 把'优惠券模块'需求拆成可执行的任务列表" |
| `/prp-implement` | 执行实现计划，带验证循环 | "按照 prp-plan 生成的计划，/prp-implement 开始逐步实现" |
| `/prp-pr` | 从当前分支创建 GitHub PR | "功能开发完成，/prp-pr 自动生成 PR 描述并提交" |
| `/prp-commit` | 自然语言描述要提交的内容 | "/prp-commit '新增了用户分组功能，包含 CRUD 接口'" |

### 代码审查

| 命令 | 用途 | 使用案例 |
|------|------|----------|
| `/typescript-review` | TypeScript 代码审查 | "/typescript-review 检查 api.ts 的类型定义是否完整" |
| `/python-review` | Python 代码审查 | "/python-review 审查 views.py 的 SQL 查询安全性" |

### 测试

| 命令 | 用途 | 使用案例 |
|------|------|----------|
| `/e2e` | E2E 测试（Playwright） | "/e2e 模拟用户完整下单流程：登录 → 加购 → 结算 → 支付" |

### 知识与文档

| 命令 | 用途 | 使用案例 |
|------|------|----------|
| `/docs` | 查询任意库/框架的最新文档 | "/docs ThinkPHP 8 的模型关联用法" 或 "/docs Vue 3 Composition API" |
| `/learn-eval` | 从会话中提取模式保存为 instinct | "踩了事务嵌套的坑，/learn-eval 把教训固化为 instinct" |
| `/instinct-status` | 查看已学习的 instincts | "/instinct-status 查看本项目积累了哪些规范" |
| `/skill-create` | 从 git 历史提取模式生成 SKILL.md | "/skill-create 分析最近 50 次提交，提取代码模式" |
| `/skill-health` | 查看 skill 健康度 | "/skill-health 检查哪些 skill 已过期需要更新" |
| `/save-session` | 保存当前会话状态 | "今天开发到一半要下班，/save-session 保存进度" |
| `/resume-session` | 恢复上次保存的会话 | "第二天继续，/resume-session 恢复昨天的上下文" |

### 多 Agent 协作

| 命令 | 用途 | 使用案例 |
|------|------|----------|
| `/team-builder` | 交互式选择 agent 组合并行执行 | "/team-builder 选择 code-reviewer + security-reviewer 同时审查认证模块" |
| `/orchestrate` | 多工作流 + 自主 agent 编排 | "/orchestrate 同时执行：文档更新 + 测试生成 + 代码审查" |
| `/devfleet` | 多 agent 并行开发（独立 worktree 隔离） | "/devfleet 让 3 个 agent 分别实现不同模块" |

### 其他

| 命令 | 用途 | 使用案例 |
|------|------|----------|
| `/security-review` | 安全审查（注入、XSS、OWASP Top 10） | "/security-review 审查用户登录和支付接口的安全漏洞" |
| `/verify` | 验证循环（自动检测并修复问题） | "/verify 运行后自动修复所有 lint 错误和类型错误" |
| `/aside` | 回答旁路问题，不影响当前任务上下文 | "/aside Redis SETNX 和 SET NX 有什么区别？" |

---

## 二、MCP 工具

MCP 通过外部服务扩展 Claude Code 的能力。

### 文档与搜索

| MCP 服务 | 工具 | 用途 | 使用案例 |
|----------|------|------|----------|
| **Context7** | `resolve-library-id` → `query-docs` | 查询任意库/框架的最新文档和代码示例 | "查询 ThinkPHP 8 的 `hasMany` 关联写法" / "查 Vue 3 的 `defineExpose` 用法" |

### GitHub 集成

| 工具 | 用途 | 使用案例 |
|------|------|----------|
| `create_pull_request` | 创建 PR | "自动创建 feat/user-group 分支的 PR，附带变更摘要" |
| `get_pull_request` / `list_pull_requests` | 查看 PR | "列出所有待 review 的 PR" |
| `get_pull_request_files` | 查看 PR 文件变更 | "获取 PR#42 修改的文件列表" |
| `create_pull_request_review` | PR Review | "对 PR#42 提交审查意见" |
| `merge_pull_request` | 合并 PR | "CI 通过且审查完成后，merge PR#42" |
| `create_issue` / `list_issues` | 管理 Issue | "创建 Bug 报告 Issue" |
| `search_code` | 搜索代码 | "在 GitHub 上搜索 ThinkPHP 最佳实践示例" |
| `create_branch` | 创建分支 | "从 main 创建 feat/payment 功能分支" |

### 浏览器自动化

| MCP 服务 | 用途 | 使用案例 |
|----------|------|----------|
| **Playwright** | 完整浏览器自动化（导航、点击、截图、网络请求分析） | "自动登录后台 → 进入商品列表 → 截图对比 UI" |
| **Chrome DevTools** | Chrome 开发者工具（DOM 快照、控制台、网络、Lighthouse、内存快照） | "运行 Lighthouse 审计检查页面性能评分" |

### 知识图谱

| MCP 服务 | 工具 | 用途 | 使用案例 |
|----------|------|------|----------|
| **Memory** | `create_entities` / `create_relations` / `search_nodes` | 跨会话持久化知识图谱 | "记录模块间依赖关系，下次会话直接查询" |

### 推理

| MCP 服务 | 用途 | 使用案例 |
|----------|------|----------|
| **Sequential Thinking** | 链式思维推理（分步分析、回溯、假设验证） | "分析并发下单时库存超卖的原因，逐步推导" |

---

## 三、多 Agent 协作

### 可用 Agent 类型

#### 开发类

| Agent | 用途 | 何时使用 |
|-------|------|----------|
| **planner** | 实现计划 | 复杂功能、重构前 |
| **architect** | 架构设计 | 技术选型、系统设计 |
| **tdd-guide** | TDD 引导 | 新功能、Bug 修复 |
| **Explore** | 代码探索 | 搜索代码、理解结构 |
| **general-purpose** | 通用任务 | 研究、搜索、多步骤任务 |

#### 审查类

| Agent | 用途 |
|-------|------|
| **code-reviewer** | 通用代码审查 |
| **typescript-reviewer** | TypeScript 专项审查 |
| **python-reviewer** | Python 专项审查 |
| **security-reviewer** | 安全漏洞检测 |

#### 运维类

| Agent | 用途 |
|-------|------|
| **build-error-resolver** | 构建错误修复 |
| **e2e-runner** | E2E 测试执行 |
| **refactor-cleaner** | 死代码清理 |
| **doc-updater** | 文档更新 |

### 并行调度示例

```
# 场景：上线前质量把关
同时启动 3 个 Agent：
1. security-reviewer → 审查 auth 模块安全性
2. code-reviewer    → 审查业务逻辑代码质量
3. e2e-runner       → 执行完整用户流程 E2E 测试
```

---

## 四、项目团队命令（t1-t6）

本项目定义了团队模式短命令，通过多 Agent 并行协作完成任务。详见 `CLAUDE.md` 第 6 节。

| 命令 | 角色组合 | 适用场景 |
|------|----------|----------|
| `t1` | 程序员 + 测试 | 单模块修复、局部接口修正 |
| `t2` | 程序员 + 测试 + 运维 | 涉及环境、部署、配置 |
| `t3` | UI设计师 + 程序员 + 测试 | 后台页面视觉、表单交互 |
| `t4` | 架构师 + 程序员 + 测试 | 跨层设计、模型结构、接口契约 |
| `t5` | 架构师 + UI设计师 + 程序员 + 测试 | 同时涉及架构和 UI |
| `t6` | 架构师 + UI设计师 + 程序员 + 测试 + 运维 | 发布前全链路改造 |

使用格式：`t1: <需求描述>`

---

## 五、常用工作流

### 新功能开发

```
1. /plan        → 分析需求、设计 Controller/Service/Validate/Model 结构
2. /tdd         → 先写测试用例（RED）
3. 实现代码      → 让测试通过（GREEN）
4. /code-review → 审查代码质量和架构规范
5. /prp-commit  → 提交代码
```

### Bug 修复

```
1. 分析问题     → 定位根因（使用 Explore agent 搜索相关代码）
2. /tdd        → 先写一个能复现 Bug 的失败测试
3. 实现修复     → 让测试通过
4. /code-review → 审查修复是否引入新问题
5. /prp-commit  → 提交修复
```

### PR 审查

```
/code-review PR#42
→ 自动获取变更文件列表
→ 逐文件分析：命名/逻辑/安全/性能
→ 输出 CRITICAL/HIGH/MEDIUM/LOW 分级审查报告
```

---

## 六、注意事项

1. **MCP 数量控制**：同时启用的 MCP 不超过 10 个，避免占用过多上下文窗口
2. **并行优先**：独立任务使用并行 Agent，提升效率
3. **Plan Mode**：复杂需求先 `/plan`，确认后再动手，避免返工
4. **Instinct 持续积累**：每次踩坑后用 `/learn-eval` 提取教训
5. **上下文预算**：长会话注意用 `/context-budget` 监控，接近上限时及时 `/save-session`
