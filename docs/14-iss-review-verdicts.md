# 14. iss/ 同事审计报告评审结论 (Review Verdicts)

> **评审对象**：`iss/` 目录 11 份文档（同事整理的可行性审计与同类插件对标报告）。
> **评审日期**：2026-09-09。**评审方法**：逐份通读 + 关键论断实地核验（引用路径存在性、WP/WooCommerce 源码、许可证文件、核心解析器行为）。
> **采纳决议**：已全部落入 ADR-0007 与各规范文档修订。本文是 iss/ 报告在本仓库的**唯一引用口径**：引用其结论时以本文核验结果为准，不直接引用其原文表述。

---

## 1. 事实核验记录（评审依据）

| 核验项 | 结果 |
| :--- | :--- |
| WP 核心 polyfill | ✅ 属实：`wp-includes/compat.php` 第 499/521/543 行定义 `str_contains` / `str_starts_with` / `str_ends_with`（WP 5.9 引入，6.0 基线起恒可用）。据此修正 AGENTS.md §4 与 `04` §8 的红线表 |
| WooCommerce Store API 钩子 | ✅ 属实：`woocommerce_store_api_checkout_update_order_from_request` 在 WC 11.1 `CheckoutTrait.php` 经 `do_action` 触发 |
| Action Scheduler 许可证 | ✅ GPLv3（实读 `woocommerce/packages/action-scheduler/license.txt`）。不打包、仅运行时适配则无分发/许可问题 |
| dbDelta PRIMARY KEY 双空格 | ⚠️ 过时：WP 7.1 解析器为 `\s+`（`upgrade.php` 索引解析正则，L3082 区域）。双空格写法保留为 WP 6.0 下界保险，非硬性要求 |
| iss/ 引用源码路径 | 抽查 16 处，15 处真实存在（JayBizzle、antispam-bee、clientjs、fingerprintjs、handl-utm-grabber、koko-analytics、LLAR、blackhole、pixelyoursite、funnel-builder、independent-analytics 等）。**不实 1 处**：ISS-03 引用的 `automatorwp/includes/action-scheduler.php` 不存在，且 automatorwp 源码中无任何 AS 调用 |
| CrawlerDetect 规模与许可 | ✅ `src/Fixtures/Crawlers.php` 1492 行规则、MIT（composer.json 实读） |
| Koko Analytics 缓冲机制 | ✅ `collect.php` 实有 `buffer-<hex>.csv` 文件缓冲 + 聚合器 |
| 安全插件存完整 IP 惯例 | ✅ LLAR 等安全插件以完整 IP 记录攻击源（行业惯例，GDPR Recital 49 合法利益） |
| WP-Cron 并发保护 | ❌ ISS-03 称"没有任何数据库行锁或分布式锁保护"不成立：WP-Cron 核心自带 cron lock（transient 互斥） |
| FingerprintJS 许可 | ⚠️ PEER-01 未标注：FingerprintJS v3 为 BSL 1.1，不可复制源码（`07` §3 既定决议维持）；ClientJS 为 MIT |
| UPDATE-then-INSERT 竞态 | ✅ 推演正确：并发下第二个 INSERT 撞 UNIQUE 键。已改 MySQL 原生 `ON DUPLICATE KEY UPDATE`（ADR-0007） |
| 每日盐摧毁跨天归因 | ⚠️ 半误读：忽略了我们主链路本就是 30 天同意门控 cookie（`02` §4）；但暴露了身份建模不一致，已理清为双轨（ADR-0007） |
| "≤2 SQL 乌托邦" | ⚠️ 部分误读：把 cron 聚合与 REST 采集计入页面请求。已改为分层预算定义（`09`） |

## 2. 逐份裁定

| 文档 | 裁定 | 要点 |
| :--- | :--- | :--- |
| README | 索引可用；打分卡与 P0 定级有夸大，不作为事实引用 | — |
| ISS-01 | 部分采纳 | 真问题：仅静态 UA 抓不住现代 RPA、安全与 CRM 完全绝缘过度。误读：章程禁的是指纹**营销画像**用途。采纳受限联动（结论可传、原始信号不可）；探针进 v1.0（站长决策） |
| ISS-02 | 大部分采纳 | 采纳：原生 upsert、补 last_active 索引。修正：预算分层而非否定。不采纳：transient 时间分片（有索引后 COUNT 走范围扫描，无需 wp_options 写热点）、Koko 式 uploads 缓冲文件为主路径 |
| ISS-03 | 方案采纳、证据有瑕 | 自适应队列采纳（ADR-0007）；其 AutomatorWP 证据不实、WP-Cron 无锁说法错误；WooCommerce 自带 AS 事实成立 |
| ISS-04 | 大部分采纳 | 采纳：安全日志完整 IP+展示脱敏+开关；索引补全；身份双轨理清。修正：跨天归因主链路本就存在（cookie） |
| ISS-05 | 全部采纳 | 双向兼容与站长"统一基线"口径一致；落入 `04` 修订 |
| ISS-06 | 采纳 | Settings 页（站长已拍板，第 20 页）；CSS/SVG 漏斗图（`06` 规范，v1.1 交付）；DB-IP Lite 预置采纳但**静默更新方案否决**（违反铁律 1，改显式按钮） |
| ISS-07 | 框架采纳 | 四支柱首发为站长决策（ADR-0007）；三期演进结构落入 `12` |
| COMPAT-01 | 大部分采纳 | 采纳：动态属性纪律、null 防御门面、JIT i18n、HPOS/Store API 双挂载、dbDelta 纪律。修正：`gr_str_contains` 门面不必要（WP polyfill）；`update_post_meta` 经典分支多余（`wc_get_order` + `update_meta_data` 双轨通用）；PRIMARY KEY 双空格说法对 WP 7.1 过时 |
| PEER-01 | 参考 | 引用真实；蜜罐时间差/动态字段、Blackhole、梯度锁定机制纳入 W 任务；探针代码样本仅作信号思路参考（FingerprintJS BSL 禁复制） |
| PEER-02 | 参考 | 引用真实；HandL cookie 模式、event_id 双端去重、Z 检验实现样本与 `03` 注册表规格一致 |

## 3. 采纳清单（已落入规范）

1. 通用化集成原则（AGENTS.md 铁律 6 / ADR-0007）
2. MySQL 原生 upsert、三处索引补全（`05`）
3. 独立 Settings 页、页面上限解除（`06`，19→20 页）
4. WooCommerce 三挂载 + HPOS 通用写法（`02`/`13`）
5. 双向兼容规范：动态属性纪律、null 防御、JIT i18n、dbDelta 双空格保险写法（`04`）
6. `str_*` 红线修正（WP 6.0+ polyfill，S9 以 PHPCompatibilityWP 实测收口）（AGENTS.md/`04`）
7. 性能预算分层定义（`09`）
8. 自适应任务队列 `Gr_Queue`（`02` §2.4/ADR-0007）
9. 安全日志完整 IP + 展示脱敏 + 匿名化开关（`05`/`10`/ADR-0007）
10. 身份双轨：cookie visitor_id 主 + 无 cookie 每日盐回退（`05`/ADR-0007）
11. 客户端安全探针进 v1.0（默认开、合法利益、可关、披露）（`12`/`13`/`08`）
12. A/B 测试进 v1.0（`12`/`13`）
13. Meta CAPI + GA4 MP 进 v1.0（`07`/`12`/`13`）
14. DB-IP Lite 预置 + 显式更新按钮（`07`/`12`/`13`）
15. 蜜罐时间差 + 动态字段名（`13` W8）；Blackhole 陷阱 opt-in（W12）
16. UA 引擎 CrawlerDetect 数据种子本地化（MIT 版权头 + NOTICE + 自维护）（`07`/`13` W2）
17. 漏斗 CSS/SVG 阶梯流失图规范（`06`，v1.1 交付）
18. 常用插件测试矩阵（`11`/`13` E9/T2/T7）

## 4. 不采纳清单（附理由）

| 项 | 理由 |
| :--- | :--- |
| Koko 式 uploads/ 缓冲文件作为主写入路径 | 与 `02` §6"uploads 运行时状态"禁令冲突（主机兼容性）；保留直接写 + 分层预算 |
| transient 时间分片在线访客计数 | `last_active` 索引后 COUNT 为索引范围扫描，无需引入 wp_options 写热点 |
| 任何激活时/静默的 GeoIP 自动更新 | 违反铁律 1（零默认外呼）；改为站长显式点击的更新按钮（opt-in 披露） |
| `gr_str_contains` 等门面函数 | WP 6.0+ 核心已 polyfill，直接用核心函数；PHPCompatibilityWP 认可 |
| HPOS 场景保留 `update_post_meta` 分支 | `wc_get_order` + `update_meta_data` 双轨通用，分支多余且易漂移 |
| "Recital 49 → 一切客户端信号无同意默认开"的外推 | 仅限安全用途、无持久标识符、可关、披露；营销用途维持同意门控（ADR-0005/0007 双轨） |
| iss/ 打分卡分数与"P0 致命"定级 | 部分基于误读（见 §1），不作为事实或规范引用 |
| FingerprintJS/ClientJS 源码级整合 | BSL/MIT 混杂；只借鉴信号思路，探针独立实现（`07` §3） |

## 5. 后续开放项（非 v1.0 阻塞）

- DB-IP Lite 数据文件的月度更新与构建流程（`tools/` 脚本，T8 前定稿）。
- Turnstile 渐进验证阈值（v1.2）。
- "设备信号"标签的独立隐私评审（v1.3）。
