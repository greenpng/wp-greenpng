# 09. 性能预算 (Performance Budgets)

> **原则**：预算是**可测量、可执行**的。任何 PR 使测量值超预算即为失败。
> **反例纪律**：参考项目宣称"0.34μs 函数耗时""0ms TBT""MMDB 2μs"等均为不可复现数字。本项目所有预算附测量方法，所有报告数字必须可由他人复现。
> **修订记录**：2026-09-09 依据 ADR-0007 与 iss-02 评审修正（`14` §1）——前台预算改**分层口径**（原单一"≤2 条 SQL"把不同请求形态混为一谈，且未定义边界）；在线访客 COUNT 明确走 `last_active` 索引。

---

## 1. 预算表

### 1.1 前台（分层口径，报告必须分列三行）

| 层 | 指标 | 预算 | 说明 |
| :--- | :--- | :--- | :--- |
| **稳态页面请求**（无 UTM、无转化、规则 L1 已缓存） | 插件增加的 SQL | **≤ 2 条** | 规则 L1 命中 0~1 条 + 会话 upsert 1 条 |
| | 插件增加的执行时间 | ≤ 5ms（P95） | 本机 PHP 8.5 + OPcache 测；共享主机允许 ×3 |
| **归因落地请求**（带 UTM/点击 ID 且有同意） | 插件增加的 SQL | **≤ 4 条**（如实分列） | 规则 + 会话 + 触点 +（cookie 写 0 条） |
| **REST collect**（sendBeacon 异步上报，不计入页面渲染） | 每请求 SQL | **≤ 3 条** | 事件落库 + 限流计数 + 会话更新 |
| 所有前台形态 | 运行时 DDL | 0 条 | `CREATE/ALTER/OPTIMIZE/TRUNCATE` 严禁 |
| | 同步外呼 | 0 次 | 出网全部异步（`Gr_Queue`） |
| | 前台 JS（gr-probe.js，安全+行为模块合计） | ≤ 8KB（gzip），`defer`，零依赖 | 不阻塞渲染；`sendBeacon` 发送 |
| | 前台 CSS | 0（默认） | 探针无样式；蜜罐字段用内联隐藏属性 |
| | autoload 选项总量 | ≤ 8KB | 仅 `gr_settings` 一个 autoload 项 |
| | DNS 查询（FCrDNS） | 0 次同步执行于前台 | 验证结论读缓存；未命中经队列异步补验 |

> 分层不是放宽：三层各自设上限，报告必须三行分列，任何一层超限即失败。总纪律不变——前台零阻塞、零 DDL、零同步外呼。

### 1.2 管理后台（本插件页面）

| 指标 | 预算 |
| :--- | :--- |
| 页面额外查询 | ≤ 10 条（列表页以 `gr_daily_stats` 为主；Settings 页走 Settings API） |
| 资产 | 仅当前页所需；图表库仅在有图表页加载 |
| 单页 JS+CSS 增量 | ≤ 60KB（gzip，不含 WP 核心已有资源） |
| REST 读接口 | P95 ≤ 300ms（万行级数据集） |

### 1.3 数据库

| 指标 | 预算 |
| :--- | :--- |
| 表数量 | 15（见 `05`，增减需 ADR） |
| 日增体积（1 万 PV/日站点，默认保留期） | ≤ 1.5MB/日；稳态总量 ≤ 50MB |
| 单表无索引查询 | 0（EXPLAIN 抽查明细页查询；在线访客 COUNT 必须走 `gr_sessions(last_active)` 索引范围扫描） |
| 瘦身单轮锁表 | 分批 ≤2000 行/批，批间 100ms，单轮 ≤10s |

## 2. 测量方法（强制随 PR 附结果）

```bash
# 前台开销：WP 自带 + Query Monitor（开发环境），或以下微基准
php tests/benchmarks/front-request.php --requests=200 --warmup=20
# 报告：P50/P95 增加时间、查询数（SAVEQUERIES），按 §1.1 三层分列

# 探针体积
gzip -c plugin/assets/js/gr-probe.js | wc -c

# autoload 体积
SELECT SUM(LENGTH(option_value)) FROM wp_options WHERE autoload IN ('yes','on','auto-on') AND option_name LIKE 'gr_%';
```

- 测量环境必须在报告中注明（PHP/WP/MySQL 版本、是否对象缓存、并发模型、队列后端 AS/WP-Cron）。
- **禁止**报告单次极值；一律 P50/P95 + 样本量。
- **禁止**与不存在的运行环境对比。

## 3. 共享主机特别约束（无对象缓存场景）

约八成目标用户无持久对象缓存。因此：
- `wp_cache_*` 不得假设跨请求命中；热点计数不得依赖"读-改-写单 transient"（并发丢更新 + `wp_options` 写热点）。
- 在线访客数等高并发读：走 `gr_sessions` 的 `last_active` 时间窗 COUNT（**索引已就位**，`05` §3.4），而非共享 transient 或时间分片 transient（后者把写热点转移回 `wp_options`，iss-02 提议已被 `14` §4 否决）。
- 限流计数：per-key transient（短 TTL），键分散，不共享数组。

## 4. 退化预案

| 场景 | 行为 |
| :--- | :--- |
| 表体积超阈值 2 倍 | 工具页黄色提示 + 加大瘦身批量；不自动 OPTIMIZE |
| 第三方 API 连续失败 | 熔断 300s；期间事件入本地待重试上限（100 条，超出丢弃并记审计） |
| WP-Cron 长期不触发（低流量站） | 状态页显示当前队列后端（AS / WP-Cron），提示配置系统 cron + 提供 WP-CLI 命令 |
