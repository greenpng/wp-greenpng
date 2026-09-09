# AGENTS.md — greenpng 项目 AI 代理工作规范

> 本文件是 **AI 代理（Droid / Claude / Copilot 等）在本仓库工作的最高优先级指令**。
> 人类开发者也应遵循同一套规范。更具体的子目录 `AGENTS.md` 覆盖本文件。
>
> 阅读顺序：本文件 → `docs/README.md` → 与当前任务相关的专项规范文档。

---

## 1. 项目一句话定位

**greenpng** 是一款面向 WordPress 独立站长的**免费、开源、纯本地闭环**的一体化运营套件，覆盖
**流量安全风控 · 营销归因 · 转化漏斗 · 用户行为与 CRM 评分 · 第三方生态集成** 五大业务域。

- 插件 slug：`greenpng`
- 代码前缀：`gr_` / `GR_` / `GreenPNG\`（详见 `docs/04-naming-and-coding-standards.md`）
- 发布渠道：**WordPress.org 官方插件目录**
- 商业模式：**只有免费版**。没有 Pro 版、没有 License Key、没有自建云端服务器。
- 版本基线：**统一基线**（ADR-0007）——最低支持 PHP 7.4 / WP 6.0 / MySQL 5.7，向上兼容 PHP 8.5+ / WP 7.1+，不针对特定版本写分支，只做能力检测。

---

## 2. 六条不可违背的铁律（Hard Rules）

任何代码提交、任何文档、任何建议都必须满足以下六条。违反其中任何一条的产出**必须拒绝**。

### 铁律 1：零外呼、零遥测、零云端
- 插件**不得**向 greenpng 项目自己的任何服务器发送任何请求。没有心跳、没有统计、没有版本检查、没有 License 校验。
- 唯一允许的出网请求是：**站长在后台自行填入第三方凭据并显式启用后**，向该第三方官方端点发出的请求（如 GA4、Meta CAPI）；以及**站长显式点击**的数据更新按钮（如 DB-IP 库更新）。
- 所有第三方集成**默认关闭**（opt-in），且必须在 `readme.txt` 的 `== External services ==` 段落中逐项披露。

### 铁律 2：不做付费版残留
- 代码中**不得**出现 `pro`、`premium`、`license`、`upgrade`、`cloud`、`enterprise` 语义的功能开关、锁定判断、灰化 UI 或"升级提示"。
- 不得埋"付费版占位"页面或按钮。
- 参考项目 `/Users/macos/wp-plug` 里所有 Pro / Rust 云端相关设计**一律不移植**。

### 铁律 3：前台请求零阻塞、零额外 DDL、零同步外呼
- 前台每个请求（`wp` / `template_redirect` 之前）的插件额外开销预算：**≤ 5ms、分层 SQL 预算见 `docs/09` §1.1**（稳态 ≤2 条、归因落地 ≤4 条、REST collect 独立口径）。
- **严禁**在运行时执行 `CREATE TABLE` / `ALTER TABLE` / `OPTIMIZE TABLE`。建表只允许发生在激活钩子与版本升级例程中，且必须走 `dbDelta()`。
- **严禁**在前台请求生命周期内同步调用 `wp_remote_*`。所有出网走 `Gr_Queue`（自适应 Action Scheduler / WP-Cron，ADR-0007）。
- **严禁**用 `autoload=yes` 的 option 存储会持续增长的数据（访客表、指纹表、日志计数）。

### 铁律 4：安全与隐私默认双轨最严（ADR-0007）
- 客户端 IP **默认只信任 `REMOTE_ADDR`**。代理头（`X-Forwarded-For`、`CF-Connecting-IP` 等）只有在站长显式配置了可信代理后才读取。详见 `docs/10-security-engineering.md`。
- 所有写操作必须有 `current_user_can()` 能力校验 **且** nonce 校验，两者缺一不可。
- 所有 SQL 必须 `$wpdb->prepare()`；表名用 `{$wpdb->prefix}` 拼接后不得再拼接任何用户输入。
- 所有输出必须转义（`esc_html` / `esc_attr` / `esc_url` / `wp_kses_post`）。
- **隐私双轨**：营销数据（归因/行为/CRM）IP 默认匿名化、同意门控（ADR-0005）；安全日志依合法利益存**完整 IP**，展示默认脱敏 + 匿名化开关（ADR-0007）。默认保留期短，必须实现 WP 核心隐私 API（导出/擦除）。

### 铁律 5：国际化与可访问性不是可选项
- 所有面向用户的字符串必须走 `__()` / `esc_html__()` 等，text domain 固定为 `greenpng`。
- **严禁**在 PHP / JS / HTML 中硬编码中文或英文界面文案。
- 后台界面必须使用 WordPress 原生 admin 组件与类名，不得引入自绘设计系统。
- 不得从 CDN 加载任何 JS / CSS / 字体。所有静态资源本地打包。

### 铁律 6：通用化集成，不做插件硬编码与样本库（ADR-0007）
- 生态适配**只通过目标插件的公开 Hook / 公开 API** 挂接，禁止版本号锁定分支，禁止读取目标插件私有结构。
- **禁止**以"代码样本/签名库"方式检测或适配插件——目标插件升级即失效，站长更新插件会导致本插件失灵。
- 每个主 Hook 配回退 Hook；Hook 漂移时状态页告警，**绝不静默失效**。
- 优先 WordPress 核心层机制（REST、钩子、meta API）而非目标插件内部实现。
- 功能对**所有**装了目标插件的站点生效，不允许"只对开发期样本插件生效"的隐性假设。

---

## 3. 代理工作流程

### 3.1 接到任务时
1. 先读 `docs/README.md` 找到对应的专项规范文档，按文档执行。
2. 若任务与现有规范冲突：**停下来问人，不要自行决定**。
3. 若任务涉及新的架构决策：在 `docs/adr/` 下新增一份 ADR，编号递增，先记录决策再写代码。
4. v1.0 开发按 `docs/13` 任务清单执行；进度以该清单状态列为准。

### 3.2 写代码时
- 严格遵循 `docs/04-naming-and-coding-standards.md` 的命名与编码规范（含 §3.11 双向兼容规范）。
- 目标运行环境：**PHP 7.4+ / WordPress 6.0+，向上兼容 PHP 8.5+ / WP 7.1+**。禁用 PHP 8.0+ 语法（见 §4）；向上防御见 `04` §3.11（动态属性零容忍、null 防御、JIT i18n）。
- 数据库：**MySQL / MariaDB only**（ADR-0007），不做 SQLite 方言兼容；高频写入用 MySQL 原子 upsert。
- 不使用 Composer 运行时依赖。类加载走手写 autoloader。
- 每个新文件顶部必须有 `if (!defined('ABSPATH')) { exit; }`。
- 注释只写"为什么"，不写"做什么"。不要在注释里引用任务编号、PR 编号或对话上下文。

### 3.3 改完代码后（必须执行，不得跳过）
```bash
# 1. 语法检查（全部 PHP 文件）
#    本机 php 为 FrankenPHP 构建，不支持 -l 旗标；插件文件首屏 ABSPATH 守卫使直接执行等价于 lint（全量编译 + 静默退出，语法错误即非零退出；详见 greenpng-dev/NOTES.md）
find plugin -name '*.php' -print0 | xargs -0 -n1 php

# 2. 编码规范检查
vendor/bin/phpcs --standard=phpcs.xml.dist

# 3. PHP 7.4 兼容性检查（经 phpcs-compat.xml.dist 包装 PHPCompatibilityWP，标准解析不依赖 CodeSniffer.conf——本机 dealerdirect 插件写入会失败，见 NOTES.md）
vendor/bin/phpcs --standard=phpcs-compat.xml.dist --runtime-set testVersion 7.4- plugin/

# 4. 单元测试
vendor/bin/phpunit
```
未跑上述检查、或检查未通过就宣称"完成"，视为任务失败。

### 3.4 报告结果时
- 如实说明跑了哪些检查、哪些通过、哪些跳过。
- 不要夸大。**严禁**写出"100% PASS""188/188 通过""微秒级"这类无实测支撑的表述——参考项目 `wp-plug` 的文档正是因为这类表述而不可信，本项目不得重复该错误。

---

## 4. PHP 7.4 兼容性红线

目标最低版本是 PHP 7.4，因此以下 PHP 8.0+ 特性**禁止使用**：

| 禁止使用 | PHP 7.4 替代写法 |
| :--- | :--- |
| `mixed` 类型声明 | 省略类型声明 |
| 构造器属性提升 | 显式声明属性 + 赋值 |
| `match` 表达式 | `switch` |
| `enum` | 带常量的 final class |
| 命名参数 | 位置参数 |
| 联合类型 `A\|B` | 省略类型声明 |
| `?->` nullsafe | 显式 null 判断 |
| `readonly` | private + getter |
| 尾随逗号（函数参数列表中） | 去掉尾随逗号 |
| 未声明属性的动态赋值 | 显式声明属性（PHP 8.2+ 废弃，`04` §3.11.1） |
| 向内部函数传 null | `gr_safe_*` 门面或先强转（`04` §3.11.2） |

**允许使用**（`04` §3.11.4，2026-09-09 修正）：`str_contains()` / `str_starts_with()` / `str_ends_with()` —— WP 6.0+ 核心自带 polyfill（`wp-includes/compat.php`，`docs/14` §1 实核），PHPCompatibilityWP 认可；**无需 strpos 等价写法，也无需自建门面**。

允许使用：`declare(strict_types=1)`、箭头函数 `fn()`、typed properties、null 合并赋值 `??=`、展开运算符。

---

## 5. 关键路径文件

| 路径 | 说明 |
| :--- | :--- |
| `AGENTS.md` | 本文件，代理最高指令 |
| `docs/README.md` | 文档总索引，**任务开始前必读** |
| `docs/00-project-charter.md` | 项目章程：范围与非目标 |
| `docs/01-wp-plug-analysis-and-assessment.md` | 参考项目 wp-plug 的分析与评估结论（含不可移植清单） |
| `docs/04-naming-and-coding-standards.md` | gr/GR 命名规范、编码规范与双向兼容规范 |
| `docs/08-wporg-compliance-checklist.md` | WordPress.org 提交合规清单 |
| `docs/10-security-engineering.md` | 安全工程规范（含 wp-plug 缺陷的正确做法） |
| `docs/13-v1-0-implementation-plan.md` | **v1.0 实施清单：任务与进度的唯一真源** |
| `docs/14-iss-review-verdicts.md` | iss/ 同事审计报告的评审结论与核验记录（引用 iss/ 时以此为准） |
| `plugin/greenpng.php` | 插件主入口 |
| `plugin/readme.txt` | WordPress.org readme（含 External services 披露） |

---

## 6. 参考项目的正确用法

`/Users/macos/wp-plug` 是**只读参考资料**，包含 226 个同类开源插件源码 + 46 份前期分析文档 + 一个代号 Agy 的原型插件。

**可以做的**：
- 读第三方插件源码学习 Hook 用法、DDL 设计、UI 交互模式。
- 参考 46 份文档里的业务需求梳理、竞品功能矩阵、生态插件 Hook 清单。

**不可以做的**：
- 直接复制 `wp-plug/wp-site/wordpress/wp-content/plugins/agy-suite` 的代码。该原型存在已确认的安全缺陷（IP 伪造绕过、公开测试端点、WAF 误杀、每请求 DDL），详见 `docs/01`。
- 直接复制 `wp-plug/preview/wp-admin-ui/` 的 40 个静态页面。它们是自绘设计系统 + 硬编码假数据，不符合 WP 原生后台规范。
- 把 46 份文档中的性能数字、测试结论、Pro/云端架构当作事实或需求。
- 修改 `/Users/macos/wp-plug` 下的任何文件。

**`iss/` 目录**（同事整理的审计与对标报告，2026-09-09 评审完毕）：
- 用途：只读参考。引用其中结论时**必须**以 `docs/14` 的核验结果为准（含两处不实引用与多处过时论断的更正），不直接引用原文。
- 其代码样本可借鉴思路，但实现仍受本文件与 `04`/`07` 约束（BSL 源码零复制、许可证归属等）。

---

## 7. 禁止事项速查

- ❌ 不要在未获批准的情况下引入任何 Composer 运行时依赖
- ❌ 不要引入 React / Vue / Tailwind / 任何 CDN 资源
- ❌ 不要捆绑 MaxMind GeoLite2 等许可受限的二进制数据库文件（DB-IP Lite CC BY 4.0 文本数据允许随包，须归属披露）
- ❌ 不要新增超出 `docs/05-database-schema.md` 定义的数据表
- ❌ 不要用 `admin-ajax.php` 承载新接口（统一走 REST API）
- ❌ 不要在插件中写入 `wp-content` 之外的任何路径
- ❌ 不要提交 `.zip`、`node_modules`、`vendor`（运行时）、构建产物
- ❌ 不要做 SQLite 方言兼容（ADR-0007：MySQL/MariaDB only）
- ❌ 不要对目标插件做版本号锁定或样本库式检测（铁律 6）
- ❌ 不要在提交信息或代码注释中出现 "Agy"、"antigravity" 等参考项目品牌
- ❌ 不要 `git push`、不要改 git 作者身份、不要 `--no-verify`
