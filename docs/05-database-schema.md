# 05. 数据库设计 (Database Schema)

> **唯一真源**：greenpng 的全部数据表以本文件为准。参考项目的 5/13/14/15 张表互相矛盾（见 `01` §5.1），本文件终结这种不一致。
> **硬约束**：建表/改表只允许在激活与版本升级例程中通过 `dbDelta()` 执行；索引字符串列 ≤191 字符；一律 `$wpdb->get_charset_collate()`；运行时零 DDL。
> **修订记录**：2026-09-09 依据 ADR-0007——安全日志完整 IP；身份双轨（visitor_id 主 + 每日盐回退）；补三处索引；明确 MySQL-only 方言。2026-09-10 S5 实测——整数列补显示宽度、字段行逗号分隔（dbDelta 幂等纪律，见 §3 引注）。

---

## 1. 设计原则

1. **读不扫原始表**：管理后台的图表与 KPI 一律读 `gr_daily_stats` 汇总表；原始事件表只服务于明细列表与钻取。
2. **原始表可瘦身**：所有高增长表有保留期与 FIFO 行数上限（双轨，先到先执行），瘦身不破坏报表（因为报表读的是汇总表）。
3. **PII 双轨（ADR-0007）**：营销轨数据（sessions 营销字段、touchpoints）默认匿名化存储；**安全轨（gr_security_logs）存完整 IP**（合法利益），展示层脱敏，开关可转匿名。邮箱在 contacts 之外不落明文。
4. **幂等优先**：转化、归因绑定、日汇总等关键写入必须有 UNIQUE 键或幂等检查，重放不产生重复记录。

## 2. 表清单（14 张 + 1 汇总）

| # | 表（`{$wpdb->prefix}` 后部分） | 用途 | 增长性 | 默认保留 |
| :--- | :--- | :--- | :--- | :--- |
| 1 | `gr_security_logs` | 安全事件（浪涌折叠） | 低（折叠） | 30 天 |
| 2 | `gr_access_rules` | 允许/封禁规则统一表 | 静态 | 永久（用户数据） |
| 3 | `gr_sessions` | 访客会话 | 高 | 90 天 |
| 4 | `gr_events` | 统一事件流水（含 A/B 曝光与转化事件） | 高 | 30 天 |
| 5 | `gr_touchpoints` | 归因触点 | 中 | 90 天 |
| 6 | `gr_conversions` | 转化与归因绑定 | 中 | 永久（聚合后） |
| 7 | `gr_funnels` | 漏斗定义（含 flow_json） | 静态 | 永久 |
| 8 | `gr_funnel_sessions` | 漏斗状态机 | 高 | 30 天 |
| 9 | `gr_cart_abandonments` | 弃购捕获与挽回 | 中 | 90 天 |
| 10 | `gr_contacts` | CRM 联系人 | 中 | 永久（用户资产） |
| 11 | `gr_tags` | 标签字典 | 静态 | 永久 |
| 12 | `gr_contact_tags` | 联系人↔标签多对多 | 中 | 跟随联系人 |
| 13 | `gr_audit_logs` | 管理操作审计 | 中 | 365 天 |
| 14 | `gr_dynamic_events` | 站长自定义嗅探规则 | 静态 | 永久 |
| — | `gr_daily_stats` | 日维度汇总（所有报表数据源） | 低 | 365 天 |

共 15 个物理对象（14 业务表 + 1 汇总表）。

**不新增表的既定安排**：A/B 实验定义存于非 autoload option（`gr_ab_experiments`，有界配置数据），曝光/转化事件落 `gr_events`；`gr_user_quality`/`gr_ab_experiments`/`gr_traffic_hits` 的职责并入 `gr_events`/`gr_sessions`/`gr_daily_stats`（理由：原型为每个小功能单建表，导致表数膨胀而每张表又无完整 CRUD）。

**SQL 方言（ADR-0007）**：MySQL / MariaDB only，不承诺 SQLite。浪涌折叠等高频写入使用 MySQL 原生 `INSERT ... ON DUPLICATE KEY UPDATE`（原子、无竞态；iss-02 对"先 UPDATE 后 INSERT"的竞态推演正确，已弃用该写法）。

## 3. 关键表 DDL（示例基线）

> 全部 DDL 经 `dbDelta()` 注册。**`PRIMARY KEY` 后写两个空格**（`PRIMARY KEY  (id)`）——WP 7.1 解析器已放宽为 `\s+`（`14` §1 实核），双空格为 WP 6.0 下界的保险写法，成本为零。其余表在实现时按同一模板补齐并在本文件登记。
>
> **整数显示宽度与逗号**（2026-09-10 S5 实测，MariaDB 12.3 / WP 7.1）：整数类型一律带核心同款显示宽度——`BIGINT(20)` / `INT(10)` / `TINYINT(3)`，显式 `TINYINT(1)` 布尔保持原样。dbDelta 逐字比对 `DESCRIBE` 输出：宽度失配在 MariaDB（全版本）与 MySQL <8.0.17 上每轮触发 `CHANGE COLUMN`；MySQL ≥8.0.17 由 dbDelta 显式忽略纯宽度差异（`wp-admin/includes/upgrade.php` 实核）。字段行以逗号分隔（末行除外）——dbDelta 对新表按原文执行 CREATE，无逗号即整表建表失败。

### 3.1 `gr_security_logs`（浪涌折叠日志）

```sql
CREATE TABLE {$wpdb->prefix}gr_security_logs (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  fold_key CHAR(32) NOT NULL,
  ip VARBINARY(16) NOT NULL,
  rule_id VARCHAR(64) NOT NULL,
  request_path VARCHAR(191) NOT NULL DEFAULT '',
  user_agent VARCHAR(191) NOT NULL DEFAULT '',
  reason VARCHAR(191) NOT NULL DEFAULT '',
  action_taken VARCHAR(16) NOT NULL DEFAULT 'logged',
  hit_count INT(10) UNSIGNED NOT NULL DEFAULT 1,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY fold_key (fold_key),
  KEY rule_seen (rule_id, last_seen),
  KEY ip_seen (ip, last_seen)
) {$charset_collate};
```

- `fold_key` = `md5(ip + rule_id + 小时窗口)`，写入用 `INSERT ... ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen = VALUES(last_seen)`（MySQL 原生原子 upsert）。
- **诚实声明**：折叠消除的是行数膨胀，不是写入次数——每次命中仍是一条原子写入。文档与 UI 中不得宣称"I/O 节省 99.9%"。
- **IP 存储双轨（ADR-0007）**：默认存**完整 IP**（`inet_pton`），支撑精准封禁；展示时 `inet_ntop` 并默认脱敏（末段遮蔽）；站长开启 `gr_security_log_anonymize` 后改为入库存前截断（IPv4 /24、IPv6 /48），UI 同步明示"封禁将降级为网段级"。

### 3.2 `gr_sessions`（会话，含渠道分类）

```sql
CREATE TABLE {$wpdb->prefix}gr_sessions (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  visitor_id CHAR(64) NOT NULL,
  session_id CHAR(36) NOT NULL,
  user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  channel VARCHAR(32) NOT NULL DEFAULT 'direct',
  utm_source VARCHAR(191) NOT NULL DEFAULT '',
  utm_medium VARCHAR(191) NOT NULL DEFAULT '',
  utm_campaign VARCHAR(191) NOT NULL DEFAULT '',
  click_id VARCHAR(191) NOT NULL DEFAULT '',
  landing_path VARCHAR(191) NOT NULL DEFAULT '',
  referrer_host VARCHAR(191) NOT NULL DEFAULT '',
  device_type VARCHAR(16) NOT NULL DEFAULT 'desktop',
  ua_family VARCHAR(64) NOT NULL DEFAULT '',
  country_code CHAR(2) NOT NULL DEFAULT '',
  is_bot TINYINT(1) NOT NULL DEFAULT 0,
  bot_score TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
  pageviews INT(10) UNSIGNED NOT NULL DEFAULT 1,
  started_at DATETIME NOT NULL,
  last_active DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY session_id (session_id),
  KEY visitor_time (visitor_id, started_at),
  KEY started (started_at),
  KEY last_active (last_active),
  KEY channel_time (channel, started_at)
) {$charset_collate};
```

- **身份双轨（ADR-0007）**：`visitor_id` 的取值——
  - 主链路（同意门控）：`gr_attr` cookie 中的**签名 visitor_id**（30 天），跨天多触点归因据此计算；
  - 回退链路（无 cookie/无同意）：**每日旋转盐 + 匿名化 IP + UA 的 SHA-256**，无跨天关联（隐私收敛，文档与 UI 如实标注该口径差异）。
- 在线访客数 = `SELECT COUNT(*) WHERE last_active > NOW() - 300`，走 `last_active` 索引范围扫描（iss-02 指出原设计缺该索引，正确，已补），**不写共享 transient**。

> 落地形态（2026-09-10，C5 实装）：会话载体为 `gr_session` cookie（UUID、30 分钟滑窗，命名遵循 docs/04 §1 `gr_<用途>`）；回退轨身份 = `sha256(wp_salt | 当日 | 域分隔符 | 匿名化IP | UA)`；无 Consent API 宿主的营销同意回落 `marketing_consent_fallback` 设置开关（默认关，ADR-0005 §1）；在线数 cutoff 以显式 UTC `DateTime` 计算（对运行时时区变化免疫），实测 EXPLAIN type=range key=last_active。
- `bot_score`/`is_bot`：探针安全结论（仅分值档位与布尔，无指纹明细）。

### 3.3 `gr_conversions`（转化与归因绑定）

```sql
CREATE TABLE {$wpdb->prefix}gr_conversions (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  source_type VARCHAR(16) NOT NULL,
  source_id BIGINT(20) UNSIGNED NOT NULL,
  session_id CHAR(36) NOT NULL DEFAULT '',
  visitor_id CHAR(64) NOT NULL DEFAULT '',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  first_touch_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  last_touch_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
  model_weights TEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_unique (source_type, source_id),
  KEY created (created_at)
) {$charset_collate};
```

- `UNIQUE KEY source_unique (source_type, source_id)` 保证同一订单/表单提交重放不产生重复归因（HPOS 安全的 meta 幂等锁之外的第二道防线）。

> 落地形态（2026-09-10，C9 实装）：写入 = `Gr_Conversion_Repository::bind()` 的 `INSERT IGNORE` + insert_id>0 直返、否则按 (source_type, source_id) 回查既存 id（重放恒返同 id；被吞的插入仍消耗自增值，非缺陷）。组合 = `Gr_Attribution_Service`（30 天回看触点 → 五模型 `model_weights` JSON + first/last_touch_id 随行；直连访客 0/0 + 空模型）。实测：同一 order 双绑定 conversion_rows=1 且两次同 id。meta 锁（`_gr_attributed`）由 C10 适配器持有，与本表防线互补。
- `model_weights` 存 5 模型分配结果 JSON，由 `gr_calculate_attribution()` 产出。
- `visitor_id` 与触点表一致（cookie 主链路身份），保证跨天归因 join 成立。

### 3.4 其余表的必备索引（登记处）

| 表 | 索引 | 用途 |
| :--- | :--- | :--- |
| `gr_contact_tags` | `KEY (tag_id, contact_id)` | 反向查询（按标签拉联系人），iss-04 指出缺失，正确 |
| `gr_daily_stats` | `UNIQUE (stat_date, metric_type, metric_key)` | 聚合幂等（重复执行不翻倍），iss-04 指出缺失，正确 |
| `gr_sessions` | `KEY (last_active)` | 在线访客 COUNT 走索引范围扫描（见 §3.2） |

**`gr_daily_stats` 指标词表（2026-09-12 U1 实装登记）**：聚合任务 `Gr_Daily_Aggregator` 每日经 `Gr_Queue::DAILY_HOOK` 优先级 5 先于瘦身骑手运行；重算窗口 = 今日回看 7 天（**必须小于最小默认保留期 30 天**，否则重算会读到已瘦身数据），窗口外日期永不重访（报表稳定性即由此保证）。upsert 为替换语义（`ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value)`），重跑不翻倍。词表（`metric_type` / `metric_key`）：

| metric_type | metric_key | 来源 | 值 |
| :--- | :--- | :--- | :--- |
| `sessions` / `visitors` / `pageviews` / `conversions` / `revenue` | `''` | `gr_sessions`(started_at) / `gr_events`(name='pageview') / `gr_conversions` | COUNT / DISTINCT COUNT / SUM(amount)；**静默日也写 0 行**（趋势图稠密） |
| `sessions_by_country` | 国家码（`''`=未知） | `gr_sessions` GROUP BY country_code | COUNT |
| `sessions_by_channel` | 渠道名 | `gr_sessions` GROUP BY channel | COUNT |
| `sessions_by_device` | 设备类型 | `gr_sessions` GROUP BY device_type | COUNT |
| `sessions_by_bot` | `human`/`bot` | `gr_sessions` GROUP BY is_bot | COUNT |
| `security_hits` | rule_id | `gr_security_logs` SUM(hit_count)（折叠行困于单小时窗，last_seen 即命中日） | SUM |

## 4. 版本升级机制

```php
// includes/storage/class-gr-schema.php
const DB_VERSION = 1;  // 每次改表 +1

public static function maybe_upgrade(): void {
    $installed = (int) get_option('gr_db_version', 0);
    if ($installed >= self::DB_VERSION) { return; }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    // dbDelta(每个 DDL) ...
    update_option('gr_db_version', self::DB_VERSION, '', 'no');
}
// 挂载：admin_init（不在前台跑）；激活时立即执行一次。
```

- `gr_db_version` 为 `autoload=no`。
- 每个版本步进一个迁移方法：`migrate_1_to_2()` 等，禁止跳步。

## 5. 瘦身与归档策略

| 机制 | 规格 |
| :--- | :--- |
| 调度 | 每日 `gr_cron_daily_maintenance`（经 Gr_Queue）：先汇总（原始表 → `gr_daily_stats`）后瘦身 |
| 批量删除 | `DELETE ... ORDER BY id ASC LIMIT 2000`，批间 `usleep(100000)`；单轮有时间预算（默认 10 秒），到点下轮再续 |
| 双轨上限 | 每表 `max_days` + `max_rows`，先到先执行；站长可在设置页调整，可设 0 = 仅天数 |
| OPTIMIZE TABLE | **只手动触发**（工具页按钮），cron 绝不自动执行（InnoDB 重建锁表风险） |
| 卸载 | `uninstall.php` 默认保留数据，仅当设置项 `gr_delete_data_on_uninstall=1` 时删除全部表与 option（WP 惯例：数据属于站长） |

## 6. Option 设计

- **唯一 autoload=yes 选项**：`gr_settings`（数组，≤8KB）。
- 其余全部 `autoload=no`：`gr_db_version`、`gr_delete_data_on_uninstall`、`gr_secret_*`（加密存储，见 `10`）、`gr_ab_experiments`（A/B 定义，有界）、各模块开关。
- **禁止**把计数器、在线状态、增长型数据放进 option/transient 做读-改-写。

## 7. 多站点（Multisite）行为

- 使用 `$wpdb->prefix`（随博客切换），激活钩子逐站点执行；不提供网络级共享表（v1 非目标）。
