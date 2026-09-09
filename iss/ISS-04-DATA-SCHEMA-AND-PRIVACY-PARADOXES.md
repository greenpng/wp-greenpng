# ISS-04: 数据表设计漏洞与过度隐私治理悖论

| 严重级别 | 缺陷分类 | 涉及文档 | 状态 |
| :--- : | :---: | :--- | :---: |
| **P0 致命** | 数据库架构 / 业务闭环 | `docs/05-database-schema.md` §3<br>`docs/adr/0005-consent-gated-tracking.md`<br>`docs/10-security-engineering.md` §1 | **已完成深度对标与纠偏** |

---

## 一、 缺陷背景与设计矛盾对照

同事在 `docs/05-database-schema.md` 与 ADR-0005 中，为了在隐私合规（GDPR/ePrivacy）上做到“无可挑剔”，设计了以下机制：
1. **会话访客哈希（`visitor_hash`）使用每日旋转盐**：“*visitor_hash = 每日旋转盐 + 匿名化 IP + UA 的 SHA-256（cookieless 友好，且无跨天追踪能力，天然隐私收敛）*”；
2. **安全日志默认开启 IP 截断存储**：“*IP 存 VARBINARY(16)，匿名化开启时先截断（IPv4 /24、IPv6 /48）再存*”。

这两项看似“高尚”的隐私举措，在实际工程实现中**直接与插件的核心业务功能（多触点归因与安全防护）产生了剧烈的逻辑自杀式冲突**。

---

## 二、 核心矛盾深度解剖与同类插件开源实现对标

### 1. 逻辑自毁：每日旋转盐彻底腰斩了 7~30 天的跨天多触点归因

#### 归因模块的业务基石是什么？
- 在 `docs/03` §4 与 `docs/05` §3.3 中，插件承诺提供全球主流的 5 种归因模型：首次触点（First-Touch）、末次触点（Last-Touch）、线性归因（Linear）、位置归因（40/20/40）、时间衰减归因（Time-Decay 7 日半衰）。
- **营销归因的本质，就是跨越数天至数十天的用户决策旅程关联（Conversion Window 普遍为 7 到 30 天）**。

#### 每日旋转盐造成的灾难场景：
假设独立站投放了 Google 搜索广告与 Meta 社交广告：
- **周一（Day 1）**：访客在 Google 搜索点击广告（带有 `gclid=abc`）进入落地页。系统依据“周一盐值 + IP + UA”生成了哈希 `hash_mon`，并在触点表中记录了一条 Google 触点；
- **周三（Day 3）**：访客在 Facebook 看到重定向广告并再次进入站点。系统依据“周三盐值 + IP + UA”生成了全新的哈希 `hash_wed`；
- **周五（Day 5）**：访客最终直接打开网址（Direct），在 WooCommerce 下单支付 200 美元。系统生成了周五的哈希 `hash_fri`。

#### 同类顶流归因插件是如何合规实现的？
- **`HandL UTM Grabber`**（源码路径：`/Users/macos/wp-plug/05-utm-campaign-marketing-attribution/handl-utm-grabber/`）：
  - 核心逻辑位于 `handl-utm-grabber.php`（第 55~85 行）：
    通过第一方 Cookie 保存用户触点（生命周期 30 天）；
  - 结合 `wp-consent-api.php`：在访客允许“营销 Cookie”后写入，**完全不需要每日旋转盐来人为割裂会话**！
- **崩塌结果**：
  - 同事为了追求无 cookie 的隐私极端，引入每日旋转盐，导致周五下单的客户被系统强制识别为“纯直接访问的新访客”！
  - 周一的 Google 广告和周三的 Meta 广告在归因链条中彻底蒸发，**First-Touch 与 Linear 归因彻底失效**！

---

### 2. 机制瘫痪：安全日志 IP 匿名化存储导致封禁功能彻底失效

#### 漏洞执行流推演：
- 恶意黑客或爬虫从特定固定 IP `198.51.100.42` 对站点发起 SQL 注入、密码暴力破解攻击；
- 按照同事的设计：安全日志表 `gr_security_logs` 默认执行 IP 匿名化截断（/24），数据库中被写入的 IP 字段为 `198.51.100.0`；
- 管理员登录后台「安全事件」界面，看到警报后点击“**一键封禁此 IP**”：
  - **情形 A（精准匹配）**：系统在 `gr_access_rules` 中添加了一条封禁记录 `198.51.100.0`。当黑客下一次依然使用 `198.51.100.42` 发起攻击时，IP 匹配器（`gr_is_ip_blocked`）严格比对发现不一致，**拦截失败，黑客继续畅通无阻**！
  - **情形 B（子网粗暴封禁）**：若系统自动按 `/24` 封禁，将导致该网段下多达 **255 个无辜用户的正常访问被全站阻断**，引发大量客诉与订单流失！

#### 同类安全插件的行业合规共识：
- 翻阅 `/Users/macos/wp-plug/04-registration-login-form-security/limit-login-attempts-reloaded/` 与 `ninjafirewall/`：
  - 所有的专业安全防火墙在记录攻击源 IP 时，**一律存储完整物理 IP**；
  - **法律依据**：欧盟 GDPR 前言第 49 条（Recital 49）明确赋予了网站运营者为保障网络安全而处理完整 IP 的合法利益（Legitimate Interest）；
  - 只有在对外营销统计展示中才做脱敏。在内部安全拦截日志中粗暴截断，纯属过度防卫导致系统功能瘫痪。

---

## 三、 纠偏与落地整改方案（结合可用函数映射）

| 序号 | 纠偏项 | 生产级优化方案 | 对应可用函数与类 |
| :--- : | :--- | :--- | :--- |
| **1** | **第一方 30 天合规归因 Cookie** | 借鉴 `HandL UTM Grabber`，通过第一方 HttpOnly、SameSite=Lax 的签名 Cookie（`gr_attr`）实现 30 天触点持久化，解绑每日旋转盐，恢复跨天 5 种归因模型的完整计算。 | `gr_parse_attribution_params()`<br>`gr_record_touchpoint()`<br>`gr_calculate_attribution()` |
| **2** | **安全防御与营销展示双轨分离** | 安全日志表 `gr_security_logs` 依据 GDPR Recital 49 保存完整 IP（`VARBINARY(16)`），确保精准封禁即刻生效；对外报表展示时若开启隐私模式则按需脱敏显示。 | `gr_log_security_event()`<br>`gr_block_ip()`<br>`gr_is_ip_blocked()` |
| **3** | **补全关键性能索引** | `gr_contact_tags` 建立 `(tag_id, contact_id)` 反向复合索引；`gr_daily_stats` 建立 `(stat_date, metric_type, metric_key)` 唯一索引，防止批量统计重复翻倍。 | `GreenPNG\Core\Schema` |
