# greenpng 纯免费版架构可行性、目标达成度与同类插件对标综合审计报告

> **定位基准**：本项目坚定定位于 **WordPress.org 官方插件目录 100% 纯免费、开源、无 Pro、无付费锁定的旗舰运营套件**。专注打造卓越的免费版体验，无需考虑商业化变现。  
> **核心使命**：为广大独立站长解决**「访客分析监控、识别机器人/RPA与水军用户、无效访客拦截、营销推广分析、落地页 A/B 测试、站内行为统计、高质量用户标记/CRM打标、打通第三方 API 与 WP 生态插件」**一体化核心需求。  
> **双向兼容硬要求**：必须向下兼容老旧环境（PHP 7.4、WordPress 6.0），同时无缝向前兼容现代与未来环境（PHP 8.2 ~ 8.5+、WordPress 7.1+）。  
> **资产对标库**：全面依托 `/Users/macos/wp-plug/` 目录下 **226 个真实同类开源插件源码**，提取其工业级成熟代码与最佳实践。

---

## 一、 总体评估结论与可行性打分卡

同事撰写的 `greenpng` 规划文档在**消除原型高危安全漏洞（S1~S5）、规范 WordPress.org 合规审核底线（零流氓外呼、隐私门控）**方面展现了高水准。

然而，立足于**“纯免费版如何把功能做扎实、把老少版本兼容做稳、真正兑现站长业务价值”**的标准审视，文档存在**核心功能过度阉割、版本兼容顾此失彼、数据流逻辑死结**等严重短板：

| 评估维度 | 评分 (1-10) | 现状判定 | 核心症结与纠偏方向 |
| :--- | :---: | :---: | :--- |
| **项目目标达成度** | **5.0** | **遭遇过度阉割** | 一刀切废弃设备硬件环境探针导致**无法识别现代 RPA 与水军**；强行切断安全与 CRM 画像导致机器人被当成高意向客户；落地页 A/B 测试被推迟。需参考 `PEER-01` 引入合法利益客户端探针。 |
| **高并发与性能架构** | **6.0** | **局部存在硬伤** | 前台“≤2 条 SQL”在无缓存主机上不切实际；在线访客使用大表 `COUNT(*)` 存在扫表灾难；浪涌折叠改用“先Update后Insert”在高并发下必报唯一键冲突。需参考 `Koko Analytics` 缓冲机制。 |
| **数据模型与归因逻辑** | **4.5** | **出现逻辑死结** | 采用每日旋转盐（Daily Salt）导致访客 Hash 每日重置，**彻底摧毁了 7~30 天跨天多触点归因**；安全日志 IP 匿名化导致封禁功能瘫痪。需参考 `HandL UTM` 30 天加密第一方 Cookie 方案。 |
| **版本双向兼容性** | **5.5** | **顾此失彼** | 强推过时的 PHP 7.4 语法底线，却**完全忽略了 PHP 8.2+ 最致命的动态属性废弃（Dynamic Properties Deprecated）**；本地开发机运行在 PHP 8.5 与 WP 7.1，缺乏低版本真实容器验证。需执行 `COMPAT-01` 双向兼容规范。 |
| **后台交互与易用性** | **5.5** | **关键入口缺失** | 全局 19 页竟然缺少独立的“设置（Settings）”菜单；漏斗流失退化为枯燥纯文本；GeoIP 零分发导致开箱大盘国家全空。需恢复独立配置中心并预置 CC 协议的 DB-IP 离线库。 |
| **免费版路线图合理性** | **5.0** | **分期倒置** | 原版路线图将核心价值（探针、CAPI、A/B测试）过度延宕至 v1.2/v1.3，首发版空心化。需按 `ISS-07` 重构为四大支柱首发闭环的三期递进路线。 |

---

## 二、 完整审计报告与技术参考文档索引

所有问题分析与落地方案均已整理在 `/Users/macos/greenpng/iss/` 目录下，包含完整的代码文件、类名、函数名与实现范式：

### 1. 专项缺陷与问题诊断报告（ISS 系列）

| 报告文件 | 严重级别 | 核心问题摘要与整改要点 |
| :--- | :---: | :--- |
| [`ISS-01-PRODUCT-GOAL-DEVIATION-AND-OVER-CUTS.md`](./ISS-01-PRODUCT-GOAL-DEVIATION-AND-OVER-CUTS.md) | **P0 致命** | **产品核心定位偏离与关键能力过度阉割**：详细剖析为何仅靠静态 UA 无法抓取现代 RPA 机器人；论证安全信号必须与 CRM 用户质量打标联动；纠偏落地页 A/B 测试。 |
| [`ISS-02-CONCURRENCY-WRITE-AND-PERFORMANCE-BOTTLENECKS.md`](./ISS-02-CONCURRENCY-WRITE-AND-PERFORMANCE-BOTTLENECKS.md) | **P0 致命** | **高并发写入架构隐患与性能预算虚标**：推演浪涌折叠“先Update后Insert”在高并发下的死锁崩溃；论证在线访客大表 `COUNT(*)` 性能隐患；提出原子 Upsert 与时间分片方案。 |
| [`ISS-03-WP-CRON-VS-ACTION-SCHEDULER-RELIABILITY.md`](./ISS-03-WP-CRON-VS-ACTION-SCHEDULER-RELIABILITY.md) | **P1 严重** | **排斥 Action Scheduler 导致后台作业极度不可靠**：揭示 WP-Cron 依赖偶发流量导致的严重延宕与并发重复发信；提出无需捆绑、运行时自适应挂载 Action Scheduler 的无依赖双赢架构。 |
| [`ISS-04-DATA-SCHEMA-AND-PRIVACY-PARADOXES.md`](./ISS-04-DATA-SCHEMA-AND-PRIVACY-PARADOXES.md) | **P0 致命** | **数据表设计漏洞与过度隐私治理悖论**：数学推导为何每日旋转盐会彻底斩断 7~30 天跨天多触点归因；指出安全日志 IP 匿名化导致一键封禁失效；补齐复合索引设计。 |
| [`ISS-05-PHP-BASELINE-AND-ENVIRONMENT-DISCREPANCIES.md`](./ISS-05-PHP-BASELINE-AND-ENVIRONMENT-DISCREPANCIES.md) | **P1 严重** | **PHP 7.4 语法倒退与本地验证环境严重割裂**：深入剖析死守 7.4 语法导致的样板代码，重点补齐对 PHP 8.2+ 动态属性废弃与内部函数传 null 报错的防御机制。 |
| [`ISS-06-USER-EXPERIENCE-AND-ADMIN-UI-DEFICIENCIES.md`](./ISS-06-USER-EXPERIENCE-AND-ADMIN-UI-DEFICIENCIES.md) | **P1 严重** | **管理后台交互缺陷与站长关键入口缺失**：纠正缺失独立 Settings 菜单的重大失误；恢复纯 CSS/SVG 原生漏斗流失图；预置开源免费 DB-IP 离线库实现首发开箱即用。 |
| [`ISS-07-FREE-ONLY-EXCELLENCE-AND-ROADMAP-RECTIFICATION.md`](./ISS-07-FREE-ONLY-EXCELLENCE-AND-ROADMAP-RECTIFICATION.md) | **P1 严重** | **纯免费版极致体验与路线图重构落地方案**：摒弃商业化纠结，专注打造 WordPress.org 顶流免费插件，重构 v1.0（立足闭环）→ v1.1（增长赋能）→ v1.2（开放互通）演进时间表。 |

---

### 2. 深度工程规范与同类插件对标白皮书（COMPAT & PEER 系列）

| 规范与对标文件 | 核心技术内容与参考资产 |
| :--- | :--- |
| [`COMPAT-01-PHP-AND-WP-DUAL-COMPATIBILITY-ENGINEERING.md`](./COMPAT-01-PHP-AND-WP-DUAL-COMPATIBILITY-ENGINEERING.md) | **PHP 7.4~8.5+ 与 WP 6.0~7.1+ 全面双向兼容工程白皮书**：<br>• `#[\AllowDynamicProperties]` 跨版本属性防御<br>• 安全门面函数库（`gr_str_contains` / `gr_safe_strlen` / `gr_safe_trim`）<br>• WooCommerce HPOS 与古腾堡 Blocks 结账双挂载标准<br>• `dbDelta` 跨 MySQL 5.7/8.0/MariaDB/SQLite 严格语法规范 |
| [`PEER-01-ANTI-BOT-RPA-AND-SECURITY-CODE-REFERENCE.md`](./PEER-01-ANTI-BOT-RPA-AND-SECURITY-CODE-REFERENCE.md) | **同类安全防爬与 RPA 识别插件源码级对标与免费实现方案**：<br>• `JayBizzle/CrawlerDetect`：1,000+ 爬虫正则引擎本地极速比对<br>• `Antispam Bee`：动态混淆隐形蜜罐与提交时间差零验证码反自动化<br>• `Blackhole for Bad Bots`：基于 robots.txt 的虚拟黑洞陷阱<br>• `Limit Login Attempts Reloaded`：梯度递增登录暴力破解防护<br>• `ClientJS / FingerprintJS`：纯客户端无外呼硬件探针（WebDriver/WebGL）判定 RPA |
| [`PEER-02-ATTRIBUTION-FUNNELS-AND-ANALYTICS-CODE-REFERENCE.md`](./PEER-02-ATTRIBUTION-FUNNELS-AND-ANALYTICS-CODE-REFERENCE.md) | **同类营销归因、转化漏斗与网站分析插件源码级对标与免费实现方案**：<br>• `HandL UTM Grabber`：30 天加密第一方 Cookie 持久化与全表单/HPOS 自动注入<br>• `PixelYourSite`：PII SHA-256 规范化与双端 `event_id` 去重中枢<br>• `Koko Analytics`：前台零写锁缓冲文件写入与 Cron 批量异步聚合<br>• `CartFlows / FunnelKit`：漏斗步骤状态机与原生 CSS/SVG 步骤流失柱状图<br>• 双比例 Z 检验显著性分析纯 PHP 本地数学引擎实现 |

---

## 三、 总结与执行路线指引

通过对 226 个开源插件的实际源码比对可以确认：**所有用户的核心功能诉求（识别 RPA、跨天归因、落地页 A/B 测试、转化漏斗、全表单打通），在纯免费、零云端外呼、GPL 兼容的前提下完全能够高质量实现！**

建议团队以本套报告为评审依据，对 `greenpng` 的 `docs/00`、`docs/02`、`docs/05`、`docs/06`、`docs/12` 等核心规划文档进行针对性修订，确保代码正式编写前扫清所有技术死结，打造一款真正让全球 WordPress 独立站长爱不释手的五星级免费开源神作！
