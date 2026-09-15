# 05. 数据库设计 (Database Schema)

> **唯一真源**：greenpng 的全部数据表以本文件为准。参考项目的 5/13/14/15 张表互相矛盾（见 `01` §5.1），本文件终结这种不一致。
> **硬约束**：建表/改表只允许在激活与版本升级例程中通过 `dbDelta()` 执行；索引字符串列 ≤191 字符；一律 `$wpdb->get_charset_collate()`；运行时零 DDL。
> **修订记录**：2026-09-09 依据 ADR-0007——安全日志完整 IP；身份双轨（visitor_id 主 + 每日盐回退）；补三处索引；明确 MySQL-only 方言。2026-09-10 S5 实测——整数列补显示宽度、字段行逗号分隔（dbDelta 幂等纪律，见 §3 引注）。2026-09-13 DB_VERSION 2（ADR-0010/0011/0013）——`gr_conversions` 增 `status`/`reversed_at`（冲销软标）；`gr_contacts` 增 `visitor_id`+KEY（cookie 轨联结）；`gr_sessions` 增 `ip_quality`（机房段类别词）；零新表。2026-09-15 DB_VERSION 3（ADR-0015）——`gr_cart_abandonments` 增 `consent`（捕获时点同意快照）；零新表；状态机词表见 §3.5。

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
  ip_quality VARCHAR(16) NOT NULL DEFAULT '',
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
- `bot_score`/`is_bot`：探针安全结论（仅分值档位与布尔，无指纹明细）。写入语义（2026-09-13，ADR-0009 D2 落地，修复 C1/C2 断链）：**两挂载互不覆写**——① REST collect 的 `signal` 事件经 `apply_probe_score()` 独立 UPDATE 落地（`bot_score = GREATEST(bot_score, %d)` 只升不降；`is_bot` 粘滞——未过阈值的信号永不清除既有定罪；阈值 `bot_verdict_threshold` 默认 70 = 双信号佐证）；② 检测侧高置信结论（扫描器 UA / 载荷 / 陷阱）经结论通道在请求结束的 PHP shutdown 标记 `mark_session_bot()`（只写 `is_bot`，不动探针分值）。`touch()` 的 upsert 两列保持默认值不动——会话行由 touch 落地、结论由两挂载写入；登录/注册/陷阱等从未建会话行的路径如实标记零行（无可定罪之行）。
- `ip_quality`（2026-09-13，DB_VERSION 2，ADR-0011 D4）：机房段类别词 `'hosting'` 或 `''`（未知/住宅）——归因监听器首触写入、重复 touch 不覆写（与 channel 同款落地纪律）；匹配在内存完成（随包 packed CIDR + `Gr_Ip_Matcher`），原始 IP **永不落营销轨表**（类别词与 `country_code` 同级，非标识符）。**仅展示与报表，绝不自动定罪**（bot 判定权仍属探针/检测结论通道）。

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
  status VARCHAR(16) NOT NULL DEFAULT 'active',
  reversed_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY source_unique (source_type, source_id),
  KEY created (created_at)
) {$charset_collate};
```

- `UNIQUE KEY source_unique (source_type, source_id)` 保证同一订单/表单提交重放不产生重复归因（HPOS 安全的 meta 幂等锁之外的第二道防线）。
- `status`/`reversed_at`（2026-09-13，DB_VERSION 2，ADR-0010）：退款/取消冲销 = 守卫 UPDATE 软标（`status: active→reversed` + `reversed_at`），**金额永不改动**（总额真相留存，毛/净两口径可复算）；部分退款按订单剩余额收敛 `amount`（以订单态为唯一真源 ⇒ 天然幂等）；在途单（pending/on-hold）不属冲销面。`revenue` 日聚合口径 = **净额**（`SUM(amount) WHERE status='active'`，见 §3.4 词表注）；`conversions` 口径计全部绑定行（转化率不因退款波动）。

> 落地形态（2026-09-10，C9 实装）：写入 = `Gr_Conversion_Repository::bind()` 的 `INSERT IGNORE` + insert_id>0 直返、否则按 (source_type, source_id) 回查既存 id（重放恒返同 id；被吞的插入仍消耗自增值，非缺陷）。组合 = `Gr_Attribution_Service`（30 天回看触点 → 五模型 `model_weights` JSON + first/last_touch_id 随行；直连访客 0/0 + 空模型）。实测：同一 order 双绑定 conversion_rows=1 且两次同 id。meta 锁（`_gr_attributed`）由 C10 适配器持有，与本表防线互补。
- `model_weights` 存 5 模型分配结果 JSON，由 `gr_calculate_attribution()` 产出。
- `visitor_id` 与触点表一致（cookie 主链路身份），保证跨天归因 join 成立。

### 3.4 其余表的必备索引（登记处）

| 表 | 索引 | 用途 |
| :--- | :--- | :--- |
| `gr_contact_tags` | `KEY (tag_id, contact_id)` | 反向查询（按标签拉联系人），iss-04 指出缺失，正确 |
| `gr_daily_stats` | `UNIQUE (stat_date, metric_type, metric_key)` | 聚合幂等（重复执行不翻倍），iss-04 指出缺失，正确 |
| `gr_sessions` | `KEY (last_active)` | 在线访客 COUNT 走索引范围扫描（见 §3.2） |
| `gr_contacts` | `KEY (visitor_id)` | 联系人↔访客联结（cookie 轨专属、最新优先，ADR-0013 D1）——行为评分/会话钻取/RFM 频次的共同基础，DB_VERSION 2 |

**`gr_daily_stats` 指标词表（2026-09-12 U1 实装登记）**：聚合任务 `Gr_Daily_Aggregator` 每日经 `Gr_Queue::DAILY_HOOK` 优先级 5 先于瘦身骑手运行；重算窗口 = 今日回看 7 天（**必须小于最小默认保留期 30 天**，否则重算会读到已瘦身数据），窗口外日期永不重访（报表稳定性即由此保证）。upsert 为替换语义（`ON DUPLICATE KEY UPDATE metric_value = VALUES(metric_value)`），重跑不翻倍。词表（`metric_type` / `metric_key`）：

| metric_type | metric_key | 来源 | 值 |
| :--- | :--- | :--- | :--- |
| `sessions` / `visitors` / `pageviews` / `conversions` / `revenue` | `''` | `gr_sessions`(started_at) / `gr_events`(name='pageview') / `gr_conversions` | COUNT / DISTINCT COUNT / SUM(amount)；**静默日也写 0 行**（趋势图稠密）。`revenue` 自 DB_VERSION 2 起为**净额口径**（`SUM(amount) WHERE status='active'`，ADR-0010 D3）；`conversions` 计全部绑定行；冲销经队列触发该转化 `created_at` 日期的 conversions/revenue **定向重算**（`gr_conversions` 永久保留 ⇒ 任意账龄安全；7 天回看窗规则与其"不读已瘦身数据"的存在理由两不相伤） |
| `sessions_by_country` | 国家码（`''`=未知） | `gr_sessions` GROUP BY country_code | COUNT |
| `sessions_by_channel` | 渠道名 | `gr_sessions` GROUP BY channel | COUNT |
| `sessions_by_device` | 设备类型 | `gr_sessions` GROUP BY device_type | COUNT |
| `sessions_by_bot` | `human`/`bot` | `gr_sessions` GROUP BY is_bot | COUNT |
| `security_hits` | rule_id | `gr_security_logs` SUM(hit_count)（折叠行困于单小时窗，last_seen 即命中日） | SUM |

### 3.5 `gr_cart_abandonments` 状态机（2026-09-15，DB_VERSION 3，ADR-0015）

`gr_cart_abandonments` 自 S5 落库，v1.1 起有读写方。行键 = `session_id`（upsert 折叠：车内容随最新快照、**首邮箱胜出**——邮箱列一经写入永不重写、令牌签发一次永不重写）；邮箱双轨（`email_hash` 供联结与退订比对 / `email_enc` 仅供发信单点解密）；`cart_json` 只存行项四元组（product_id/variation_id/quantity/name），无地址无支付细节。DB_VERSION 3 增 `consent TINYINT(1)`——捕获时点的同意快照，发信闸的唯一直接依据。

**状态词表与迁移纪律**（全部为守卫 UPDATE——WHERE 带现状，受影响行数即裁决）：

| 状态 | 语义 | 迁移 |
| :--- | :--- | :--- |
| `captured` | 邮箱（与车）在册，**未发信**——等待延迟检查，也是发信失败的诚实回退态 | 出生态；`revert_abandoned()`（发信失败）回退至此 |
| `abandoned` | 检查五闸全过、互斥翻转已赢——**发信权已取得** | `mark_abandoned()`：仅 captured 可翻（双检查竞速恰一胜者） |
| `attempted` | 恢复链接被点击、车已回填（点击≠挽回） | `mark_attempted()`：abandoned/attempted（幂等再点） |
| `recovered` | 绑定转化回写——挽回的是订单，不是信（信发没发、点没点都不问） | `mark_recovered()`：按 email_hash 关闭**全部开态**（captured/abandoned/attempted/failed） |
| `failed` | 两次投递拒绝（或信封不可解）——停机可见，状态页有信号 | `mark_failed()`：`IN (captured, abandoned)`——captured 入列是解密失败停机（pre-mutex 无可重试之事），abandoned 入列是第二次发信拒绝 |

互斥与重试的闭环（ADR-0015 D2 实装修正，:8091 实测揭出）：互斥翻转先于发信；发信失败 ⇒ **先回退 captured**（滞留 abandoned 会让「仍 captured」闸把 6 小时重试永远拒在门外、failed 不可达——行必须如实回「未发信」态），重试全闸重跑后再赢一次互斥；二次拒绝 ⇒ failed + 状态页警告。attempted/recovered/failed 永不被 failed 或 abandoned 覆写——人的结局不受投递层翻案。

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
- 其余全部 `autoload=no`：`gr_db_version`、`gr_delete_data_on_uninstall`、`gr_secret_*`（加密存储，见 `10`）、`gr_ab_experiments`（A/B 定义，有界）、`gr_webhooks`（出网端点，≤10 行有界、secret 为 `Gr_Secrets` 信封、ADR-0016）、各模块开关。
- **禁止**把计数器、在线状态、增长型数据放进 option/transient 做读-改-写。

## 7. 多站点（Multisite）行为

- 使用 `$wpdb->prefix`（随博客切换），激活钩子逐站点执行；不提供网络级共享表（v1 非目标）。
