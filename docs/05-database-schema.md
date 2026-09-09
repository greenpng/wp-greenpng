# 05. 数据库设计 (Database Schema)

> **唯一真源**：greenpng 的全部数据表以本文件为准。参考项目的 5/13/14/15 张表互相矛盾（见 `01` §5.1），本文件终结这种不一致。
> **硬约束**：建表/改表只允许在激活与版本升级例程中通过 `dbDelta()` 执行；索引字符串列 ≤191 字符；一律 `$wpdb->get_charset_collate()`；运行时零 DDL。

---

## 1. 设计原则

1. **读不扫原始表**：管理后台的图表与 KPI 一律读 `gr_daily_stats` 汇总表；原始事件表只服务于明细列表与钻取。
2. **原始表可瘦身**：所有高增长表有保留期与 FIFO 行数上限（双轨，先到先执行），瘦身不破坏报表（因为报表读的是汇总表）。
3. **PII 最小化**：IP 默认匿名化存储（/24、/48 截断或盐哈希，由 `gr_anonymize_ip` 设置控制）；邮箱在 contacts 之外不落明文。
4. **幂等优先**：转化、归因绑定等关键写入必须有 UNIQUE 键或幂等检查，重放不产生重复记录。

## 2. 表清单（14 张）

| # | 表（`{$wpdb->prefix}` 后部分） | 用途 | 增长性 | 默认保留 |
| :--- | :--- | :--- | :--- | :--- |
| 1 | `gr_security_logs` | 安全事件（浪涌折叠） | 低（折叠） | 30 天 |
| 2 | `gr_access_rules` | 允许/封禁规则统一表 | 静态 | 永久（用户数据） |
| 3 | `gr_sessions` | 访客会话 | 高 | 90 天 |
| 4 | `gr_events` | 统一事件流水 | 高 | 30 天 |
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

共 15 个物理对象（14 业务表 + 1 汇总表）。相比原型：合并 allowlist+blocked_cidrs 为 `gr_access_rules`；新增原型缺失的 `gr_funnels`、`gr_funnel_sessions`、`gr_tags`、`gr_contact_tags`、`gr_daily_stats`；删除云端游标表；`gr_user_quality`/`gr_ab_experiments`/`gr_traffic_hits` 的职责并入 `gr_events`/`gr_sessions`/`gr_daily_stats`（理由：原型为每个小功能单建表，导致表数膨胀而每张表又无完整 CRUD）。

## 3. 关键表 DDL（示例基线）

> 全部 DDL 经 `dbDelta()` 注册。以下给出三张最关键表；其余表在实现时按同一模板补齐并在本文件登记。

### 3.1 `gr_security_logs`（浪涌折叠日志）

```sql
CREATE TABLE {$wpdb->prefix}gr_security_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fold_key CHAR(32) NOT NULL,
  ip VARBINARY(16) NOT NULL,
  rule_id VARCHAR(64) NOT NULL,
  request_path VARCHAR(191) NOT NULL DEFAULT '',
  user_agent VARCHAR(191) NOT NULL DEFAULT '',
  reason VARCHAR(191) NOT NULL DEFAULT '',
  action_taken VARCHAR(16) NOT NULL DEFAULT 'logged',
  hit_count INT UNSIGNED NOT NULL DEFAULT 1,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY fold_key (fold_key),
  KEY rule_seen (rule_id, last_seen),
  KEY ip_seen (ip, last_seen)
) {$charset_collate};
```

- `fold_key` = `md5(ip + rule_id + 小时窗口)`，写入用 `INSERT ... ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen = VALUES(last_seen)`。
- **诚实声明**：折叠消除的是行数膨胀，不是写入次数——每次命中仍是一条原子 UPDATE。文档与 UI 中不得宣称"I/O 节省 99.9%"。
- IP 存 `VARBINARY(16)`（`inet_pton`），展示时 `inet_ntop`；匿名化开启时先截断再存。

### 3.2 `gr_sessions`（会话，含渠道分类）

```sql
CREATE TABLE {$wpdb->prefix}gr_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visitor_hash CHAR(64) NOT NULL,
  session_id CHAR(36) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
  pageviews INT UNSIGNED NOT NULL DEFAULT 1,
  started_at DATETIME NOT NULL,
  last_active DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY session_id (session_id),
  KEY visitor_time (visitor_hash, started_at),
  KEY started (started_at),
  KEY channel_time (channel, started_at)
) {$charset_collate};
```

- `visitor_hash` = 每日旋转盐 + 匿名化 IP + UA 的 SHA-256（cookieless 友好，且无跨天追踪能力，天然隐私收敛）。
- 在线访客数 = `SELECT COUNT(*) WHERE last_active > NOW() - 300`，**不写共享 transient**（修复原型缺陷 P3）。

### 3.3 `gr_conversions`（转化与归因绑定）

```sql
CREATE TABLE {$wpdb->prefix}gr_conversions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_type VARCHAR(16) NOT NULL,
  source_id BIGINT UNSIGNED NOT NULL,
  session_id CHAR(36) NOT NULL DEFAULT '',
  visitor_hash CHAR(64) NOT NULL DEFAULT '',
  amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'USD',
  first_touch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_touch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  model_weights TEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY source_unique (source_type, source_id),
  KEY created (created_at)
) {$charset_collate};
```

- `UNIQUE KEY source_unique (source_type, source_id)` 保证同一订单/表单提交重放不产生重复归因（HPOS 安全的 meta 幂等锁之外的第二道防线）。
- `model_weights` 存 5 模型分配结果 JSON，由 `gr_calculate_attribution()` 产出。

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
| 调度 | 每日 `gr_cron_daily_maintenance`：先汇总（原始表 → `gr_daily_stats`）后瘦身 |
| 批量删除 | `DELETE ... ORDER BY id ASC LIMIT 2000`，批间 `usleep(100000)`；单轮有时间预算（默认 10 秒），到点下轮再续 |
| 双轨上限 | 每表 `max_days` + `max_rows`，先到先执行；站长可在设置页调整，可设 0 = 仅天数 |
| OPTIMIZE TABLE | **只手动触发**（工具页按钮），cron 绝不自动执行（InnoDB 重建锁表风险） |
| 卸载 | `uninstall.php` 默认保留数据，仅当设置项 `gr_delete_data_on_uninstall=1` 时删除全部表与 option（WP 惯例：数据属于站长） |

## 6. Option 设计

- **唯一 autoload=yes 选项**：`gr_settings`（数组，≤8KB）。
- 其余全部 `autoload=no`：`gr_db_version`、`gr_delete_data_on_uninstall`、`gr_secret_*`（加密存储，见 `10`）、各模块开关。
- **禁止**把计数器、在线状态、增长型数据放进 option/transient 做读-改-写。

## 7. 多站点（Multisite）行为

- 使用 `$wpdb->prefix`（随博客切换），激活钩子逐站点执行；不提供网络级共享表（v1 非目标）。
