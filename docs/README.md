# greenpng 文档中心

> **项目**：greenpng —— WordPress 免费开源一体化运营套件（流量安全 · 营销归因 · 转化漏斗 · 行为与 CRM 评分 · 生态集成）
> **前缀**：`gr_` / `GR_` / `GreenPNG\`　**发布**：WordPress.org 官方目录　**版本线**：仅免费版（无 Pro、无云端、无 License）
> **使用方式**：开始任何任务前，先读本索引找到对应文档。规范类文档与代码冲突时，先停下并与维护者确认，不要自行其是。

---

## 阅读顺序

1. `00-project-charter.md` —— 项目章程：定位、范围、非目标、五条铁律的展开
2. `01-wp-plug-analysis-and-assessment.md` —— **立项依据**：参考项目审计结论（先读这个，理解为什么规范是现在这样）
3. `02-architecture-blueprint.md` —— 三层架构、模块划分、数据流
4. `04-naming-and-coding-standards.md` —— 命名与编码规范（写代码前必读）
5. 按任务查阅对应专项文档

## 文档索引

| 编号 | 文档 | 内容 | 状态 |
| :--- | :--- | :--- | :--- |
| 00 | [项目章程](00-project-charter.md) | 定位、范围、非目标、成功标准 | ✅ |
| 01 | [wp-plug 分析与评估](01-wp-plug-analysis-and-assessment.md) | 参考资产审计、缺陷清单、继承/废弃决议 | ✅ |
| 02 | [架构蓝图](02-architecture-blueprint.md) | 三层架构、模块、事件流、目录结构 | ✅ |
| 03 | [函数注册表](03-function-registry.md) | gr_ 函数全量规格、agy→gr 重映射 | ✅ |
| 04 | [命名与编码规范](04-naming-and-coding-standards.md) | gr/GR 前缀总表、PHP/JS/CSS/SQL 规范 | ✅ |
| 05 | [数据库设计](05-database-schema.md) | 表 DDL、索引、版本升级、瘦身策略 | ✅ |
| 06 | [管理后台与页面](06-admin-pages-and-ui.md) | 19 页信息架构、WP 原生 UI 规范 | ✅ |
| 07 | [第三方 API 集成](07-third-party-apis.md) | 收录/可选/放弃清单、许可与合规、统一 HTTP 客户端 | ✅ |
| 08 | [WordPress.org 合规清单](08-wporg-compliance-checklist.md) | 上架审核逐项自查表 | ✅ |
| 09 | [性能预算](09-performance-budgets.md) | 前台/后台/数据库量化预算与测量方法 | ✅ |
| 10 | [安全工程](10-security-engineering.md) | IP 解析、WAF 规则原则、密钥存储、nonce/能力模型 | ✅ |
| 11 | [测试策略](11-testing-strategy.md) | 测试金字塔、工具链、通过标准 | ✅ |
| 12 | [免费版路线图](12-roadmap-free-v1.md) | v1.0 → v1.3 分期交付计划 | ✅ |
| 13 | [v1.0 实施清单](13-v1-0-implementation-plan.md) | v1.0 任务分解、验收标准、进度真源 | 🔧 |
| ADR | [adr/](adr/) | 架构决策记录（编号递增，只增不改） | 持续 |

## ADR 索引

| 编号 | 决策 | 状态 |
| :--- | :--- | :--- |
| 0001 | [记录架构决策的方式](adr/0001-record-architecture-decisions.md) | 已接受 |
| 0002 | [仅免费版：无云端、无 Pro、无 License](adr/0002-free-only-no-cloud.md) | 已接受 |
| 0003 | [PHP 7.4+ / 零 Composer 运行时依赖](adr/0003-php74-no-composer-runtime.md) | 已接受 |
| 0004 | [WP 原生后台 UI：不用 Tailwind/React/CDN](adr/0004-wp-native-admin-ui.md) | 已接受 |
| 0005 | [追踪须同意门控，默认匿名化](adr/0005-consent-gated-tracking.md) | 已接受 |
| 0006 | [本地开发与验证环境：MariaDB 主验证站 + SQLite 兼容站](adr/0006-local-test-environment.md) | 已接受 |

## 规范速查（违反即评审驳回）

- 前缀只允许 `gr_` / `GR_` / `GreenPNG\` / `greenpng`，出现 `agy` 一律拒绝。
- PHP 7.4 语法红线：`str_contains`/`mixed`/`match`/`readonly` 等 PHP 8+ 特性禁用。
- 前台请求预算：≤5ms、≤2 条 SQL、零 DDL、零同步外呼。
- 写操作必须 `current_user_can()` + nonce 双校验；SQL 必须 `prepare()`；输出必须转义。
- 零默认外呼；第三方集成全部默认关闭；CDN 资源为零。
- 界面文案零硬编码，text domain 一律 `greenpng`。
