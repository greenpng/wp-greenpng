# ISS-02: 高并发写入架构隐患与性能预算虚标

| 严重级别 | 缺陷分类 | 涉及文档 | 状态 |
| :--- : | :---: | :--- | :---: |
| **P0 致命** | 架构性能 / 并发安全 | `docs/05-database-schema.md` §3<br>`docs/09-performance-budgets.md` §1.1<br>`docs/13-v1-0-implementation-plan.md` §8 (OQ-2) | **已完成深度对标与纠偏** |

---

## 一、 缺陷背景与性能指标对照

同事在 `docs/09-performance-budgets.md` §1.1 中制定了极为苛刻的前台性能预算：
- **前台执行时间额外增加 ≤ 5ms（P95）**；
- **前台增加的 SQL 查询数 ≤ 2 条**；
- **声称在共享主机（Shared Hosting、无 Redis/Memcached 对象缓存）上必须达标**。

同时在 `docs/05` 中设计了 14 张自定义表，并为了兼顾 SQLite 兼容性，在 `docs/13` OQ-2 中建议将核心高频写入的浪涌折叠日志（Surge Folding）改为“先 UPDATE 后 INSERT”。

经高并发写入模型与 InnoDB 存储引擎机制深入审计，**该技术方案存在严重的并发竞态死锁隐患与不切实际的指标虚标**。

---

## 二、 核心技术隐患深度解剖与同类插件开源实现对标

### 1. 预算虚标：前台“≤2 条 SQL”在无对象缓存环境下纯属乌托邦

#### 真实前台链路执行流推演：
在一个典型的 WordPress 共享主机（无 Redis 对象缓存，每次请求结束后 PHP 进程销毁，内存变量清空）上，当一名新访客携带广告 UTM 参数访问前台页面时：
1. **安全检查**：读取访问控制规则（`SELECT * FROM wp_gr_access_rules`）检查 IP/URL 黑白名单 —— **SQL 1**；
2. **会话校验**：依据客户端 Cookie 查找是否已有活跃 Session（`SELECT * FROM wp_gr_sessions WHERE session_id = ...`） —— **SQL 2**；
3. **会话持久化**：若为新会话，插入 Session 记录（`INSERT INTO wp_gr_sessions ...`）；若为老会话，更新活跃时间（`UPDATE ...`） —— **SQL 3**；
4. **营销触点落盘**：检测到 `utm_source` 与 `gclid`，将触点信息写入触点表（`INSERT INTO wp_gr_touchpoints ...`） —— **SQL 4**；
5. **事件流记录**：若开启了页面浏览事件，写入统一事件表（`INSERT INTO wp_gr_events ...`） —— **SQL 5**；
6. **日大盘聚合**：将指标累加至日汇总表（`INSERT INTO wp_gr_daily_stats ...`） —— **SQL 6**。

#### 同类开源插件如何破局？
- **`Koko Analytics`**（源码路径：`/Users/macos/wp-plug/10-selfhosted-web-analytics-privacy/koko-analytics/src/Resources/functions/collect.php`）：
  - Koko Analytics 同样面向廉价共享主机，它是如何在 30,000+ 活跃站点上做到前台 **0 条 SQL、< 1ms 响应**的？
  - **核心秘诀：缓冲写入（Buffer File）机制**！
  - 访客请求到达时，数据仅以单行格式追加写入临时缓冲文件（`uploads/cache/koko-analytics/buffer.php`）或极简 transient；
  - 前台执行立即返回，**完全不碰 MySQL 数据库**！
  - 后台通过定时任务（`class-aggregator.php`）每隔数分钟批量读取缓冲文件，执行一条批处理 SQL 写入汇总表。
- **反观同事的方案**：
  - 在没有常驻内存与缓冲队列的情况下，同步向多个物理表写入，不仅突破了 2 条 SQL 预算，而且每次写入都触发 InnoDB 索引更新与行锁。

---

### 2. 致命隐患：浪涌折叠日志改为“先 UPDATE 后 INSERT”，高并发下必爆唯一键重复崩溃（Race Condition）

#### 机制缺陷分析：
- 在安全攻击拦截时（如暴力猜解登录、恶意爬虫爬取、扫描器探测），同一秒内可能有上百个请求触发同一条规则。
- 原型采用 MySQL 的原子写入：
  `INSERT INTO ... ON DUPLICATE KEY UPDATE hit_count = hit_count + 1`
- 同事在 `docs/13` §8 OQ-2 中提出：“*ON DUPLICATE KEY UPDATE 为 MySQL 方言，折叠 upsert 用可移植「先 UPDATE 后 INSERT」*”。

#### 并发穿透灾难（Race Condition）：
假设攻击者并发发起 2 个请求 A 与 B（同一 IP、同一规则，`fold_key` 均为 `hash123`）：
1. **时序 T1**：请求 A 执行 `UPDATE gr_security_logs SET hit_count=hit_count+1 WHERE fold_key='hash123'`，数据库中尚无此记录，返回 `0 rows affected`；
2. **时序 T2**：请求 B 紧随其后执行同一个 `UPDATE`，同样返回 `0 rows affected`；
3. **时序 T3**：请求 A 判定记录不存在，执行 `INSERT INTO gr_security_logs (fold_key, hit_count, ...) VALUES ('hash123', 1, ...)`，**插入成功**；
4. **时序 T4**：请求 B 同样判定记录不存在，执行 `INSERT INTO gr_security_logs (fold_key, hit_count, ...) VALUES ('hash123', 1, ...)`；
5. **灾难发生**：由于 `fold_key` 存在 UNIQUE 唯一索引，请求 B 触发 MySQL 致命错误：
   `ERROR 1062 (23000): Duplicate entry 'hash123' for key 'fold_key'`！
- 在 WordPress 环境下，这会导致页面直接抛出数据库未捕获异常或显示空白 500 错误！**所谓“折叠防护”，反而成了制造全站崩溃的推手！**

---

### 3. 性能灾难：实时在线访客采用全表 `SELECT COUNT(*)`

#### 现状分析：
- 同事在 `docs/05-database-schema.md` §3.2 中指出：
  “*在线访客数 = SELECT COUNT(*) WHERE last_active > NOW() - 300，不写共享 transient（修复原型缺陷 P3）*”。

#### 为什么这是饮鸩止渴？同类插件的优秀解法：
1. 原型的缺陷在于把所有 IP 塞进一个长字符串的 transient，并发时存在读写覆盖；
2. 但同事直接对物理大表执行 `COUNT(*)`，当 `gr_sessions` 表积累到数十万行时，每次大盘刷新都引发昂贵的全表范围扫描；
3. **`Burst Statistics` 与 `Independent Analytics` 的做法**：
   - 采用**时间分片（Time-Bucket）滑动窗口**：维护 5 个 1 分钟颗粒度的高速轻量 transient（如 `gr_live_{min}`），访客心跳通过原子自增写入对应分片；
   - 仪表盘仅做 5 个整数相加（耗时 < 0.05ms），完全不扫描物理大表！

---

## 三、 纠偏与落地整改方案（结合可用函数映射）

| 序号 | 问题模块 | 生产级优化方案 | 对应可用函数与类 |
| :--- : | :--- | :--- | :--- |
| **1** | **浪涌折叠原子写入** | **坚决保留 MySQL 原生原子的 `INSERT ... ON DUPLICATE KEY UPDATE`**：<br>严格遵循 MySQL/MariaDB 主环境标准，保证千万级并发下无死锁、无重复键报错。对于 SQLite 测试环境，使用 SQLite 3.24+ 原生支持的 `INSERT ... ON CONFLICT(fold_key) DO UPDATE` 进行语法适配。 | `gr_log_security_event()`<br>`Gr_Security_Repository` |
| **2** | **在线访客轻量化计数** | 摒弃大表 `COUNT(*)`，采用**时间分片滑动窗口**：5 个轻量键原子自增，大盘读取 5 个整数求和，彻底避开物理表高频查询。 | `gr_get_live_visitor_count()`<br>`Gr_Session_Tracker` |
| **3** | **轻量写入缓冲管道** | 借鉴 `Koko Analytics` 的无锁追加设计，前台会话与事件日志优先快速写入临时轻量缓冲，通过日常维护或微任务异步合并落盘，真正达成前台 ≤5ms 执行预算。 | `gr_dispatch_event()`<br>`Gr_Buffer_Manager` |
