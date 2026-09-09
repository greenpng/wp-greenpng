# 09. 性能预算 (Performance Budgets)

> **原则**：预算是**可测量、可执行**的。任何 PR 使测量值超预算即为失败。
> **反例纪律**：参考项目宣称"0.34μs 函数耗时""0ms TBT""MMDB 2μs"等均为不可复现数字。本项目所有预算附测量方法，所有报告数字必须可由他人复现。

---

## 1. 预算表

### 1.1 前台（每个非管理请求）

| 指标 | 预算 | 说明 |
| :--- | :--- | :--- |
| 插件增加的执行时间 | ≤ 5ms（P95） | 本机 PHP 8.5 + OPcache 测；共享主机允许 ×3 |
| 插件增加的 SQL 查询数 | ≤ 2 条 | 读允许列表 + 会话/触点写入（合并批量） |
| 运行时 DDL | 0 条 | `CREATE/ALTER/OPTIMIZE/TRUNCATE` 严禁 |
| 同步外呼 | 0 次 | 出网全部异步（cron 单事件） |
| 前台 JS（gr-probe.js） | ≤ 8KB（gzip），`defer`，零依赖 | 不阻塞渲染；`sendBeacon` 发送 |
| 前台 CSS | 0（默认） | 探针无样式；蜜罐字段用内联隐藏属性 |
| autoload 选项总量 | ≤ 8KB | 仅 `gr_settings` 一个 autoload 项 |
| DNS 查询（FCrDNS） | 0 次同步执行于前台 | 验证结论读缓存；未命中走异步补验 |

### 1.2 管理后台（本插件页面）

| 指标 | 预算 |
| :--- | :--- |
| 页面额外查询 | ≤ 10 条（列表页以 `gr_daily_stats` 为主） |
| 资产 | 仅当前页所需；图表库仅在有图表页加载 |
| 单页 JS+CSS 增量 | ≤ 60KB（gzip，不含 WP 核心已有资源） |
| REST 读接口 | P95 ≤ 300ms（万行级数据集） |

### 1.3 数据库

| 指标 | 预算 |
| :--- | :--- |
| 表数量 | 15（见 `05`，增减需 ADR） |
| 日增体积（1 万 PV/日站点，默认保留期） | ≤ 1.5MB/日；稳态总量 ≤ 50MB |
| 单表无索引查询 | 0（EXPLAIN 抽查明细页查询） |
| 瘦身单轮锁表 | 分批 ≤2000 行/批，批间 100ms，单轮 ≤10s |

## 2. 测量方法（强制随 PR 附结果）

```bash
# 前台开销：WP 自带 + Query Monitor（开发环境），或以下微基准
php tests/benchmarks/front-request.php --requests=200 --warmup=20
# 报告：P50/P95 增加时间、查询数（SAVEQUERIES）

# 探针体积
gzip -c plugin/assets/js/gr-probe.js | wc -c

# autoload 体积
SELECT SUM(LENGTH(option_value)) FROM wp_options WHERE autoload IN ('yes','on','auto-on') AND option_name LIKE 'gr_%';
```

- 测量环境必须在报告中注明（PHP/WP/MySQL 版本、是否对象缓存、并发模型）。
- **禁止**报告单次极值；一律 P50/P95 + 样本量。
- **禁止**与不存在的运行环境对比（参考项目用"WordPress 7.1"做基准环境，属虚构）。

## 3. 共享主机特别约束（无对象缓存场景）

约八成目标用户无持久对象缓存。因此：
- `wp_cache_*` 不得假设跨请求命中；热点计数不得依赖"读-改-写单 transient"（并发丢更新 + `wp_options` 写热点）。
- 在线访客数等高并发读：走 `gr_sessions` 的时间窗 COUNT（有索引），而非共享 transient。
- 限流计数：per-key transient（短 TTL），键分散，不共享数组。

## 4. 退化预案

| 场景 | 行为 |
| :--- | :--- |
| 表体积超阈值 2 倍 | 工具页黄色提示 + 加大瘦身批量；不自动 OPTIMIZE |
| 第三方 API 连续失败 | 熔断 300s；期间事件入本地待重试上限（100 条，超出丢弃并记审计） |
| WP-Cron 长期不触发（低流量站） | 状态页提示配置系统 cron + 提供 WP-CLI 命令 |
