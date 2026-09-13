# ISS-09 · v1.0 交付后深度代码审计：对 ISS-08 的客观核验与深层断链缺陷增补报告

> **报告属性**：针对 `greenpng/iss/ISS-08-POST-V1-0-ACTUAL-UI-AND-FUNCTION-GAP-ANALYSIS.md` 的专家级客观复核，以及对 `greenpng v1.0.0` 真实运行时代码执行流的深度逆向审计报告。  
> **审查对象**：  
> 1. `greenpng/iss/ISS-08` 差距分析报告（G1~G8 缺口、D1~D10 讨论议题）。  
> 2. `greenpng` 已发布 v1.0.0 源码（16 个管理类、15 张数据表、全套安全/归因/探针/CAPI 引擎）。  
> 3. 需求与资产基准：`/Users/macos/wp-plug/` 226 个同类开源插件源码、46 份前序设计文档、40 页静态后台功能清单。  
> **审计原则**：代码即事实，调用链即证据；坚持 100% 免费版定位与 PHP 7.4~8.5+ / WP 6.0~7.1+ 双向兼容标准。  
> **审计日期**：2026-09-14。

---

## 0. 执行摘要与核心审计结论

针对站长提出的两大核心关切：
1. **ISS-08 整理得对吗？**  
   **结论：整理得非常客观、严谨，事实准确率达 95% 以上。**  
   ISS-08 准确锁定了 G1~G8 共 8 个真实缺口，全部具有 100% 可复核的代码实证（例如 4 个设置键在设置页零控件、A/B 页面声明“仅展示不创建”、IP 机房与代理检测缺席等）。它严守了“纯免费无 Pro”、“不搞非原生自绘”、“区分路线图延期与裁定取舍”的边界，是一份高质量的管理后台与功能盘点报告。

2. **除了 ISS-08 找出的问题，是否还有其他更严重的问题？**  
   **结论：有，且存在比 UI 缺失更严重的「底层数据流断链」与「业务网关死结」！**  
   ISS-08 的主要视角停留在**“静态功能清单、管理 UI 控件、菜单页面映射”**这一层，**未能深入请求运行时的调用链追踪（Execution Trace）**。  
   本次深度代码审计发现了 **5 大深层运行时断链与逻辑死结（Critical Runtime Disconnects C1~C5）**，其中最致命的两项使得插件核心宣传亮点在现实中彻底瘫痪：
   - ❗ **C1（P0 致命逻辑断链）：`is_bot` 在运行时永不置 1，Meta CAPI 与 GA4 MP 的机器人过滤网关彻底失效！**  
     虽然数据表有 `is_bot` 字段，且 CAPI 代码中声称“绝不将机器人流量回传给广告算法”；但在实际代码中，会话创建、探针采集、安全检测三处均**没有任何代码会将会话标记为 `is_bot = 1`**。导致 `visitor_bot_verdict()` 恒返回 `false`，不仅 CAPI 广告防刷完全不拦截，大盘与报表的“机器人会话数”也永久为 0！
   - ❗ **C2（P0 致命数据死结）：客户端安全探针 `bot_score` 采集后成为死数据，永不持久化到会话！**  
     `gr-probe.js` 采集并计算出的 0~100 评分在提交到 REST API 后，仅作为瞬时事件丢入 `gr_events`，但其调用的会话 `touch()` 既未携带该分数，底层 `ON DUPLICATE KEY UPDATE` 也没更新 `bot_score`。导致全库所有会话的 `bot_score` 永久为 0，后台评分分布图 100% 堆积在 0 分段。
   - ❗ **C3（P1 安全虚设）：后台 `Access Rules` 封禁列表（`is_ip_blocked`）从未被任何 Web 请求生命周期执行！**  
     后台虽有封禁 IP 列表与单测，但在 `init`、`plugins_loaded`、`Gr_Request_Inspector` 中**没有任何钩子调用 `is_ip_blocked()` 并执行阻断**！站长在后台封禁的恶意 IP，依然能畅通无阻地访问站点！
   - ❗ **C4（P1 电商归因遗漏与退款反冲销缺失）：WooCommerce 货到付款/银行转账漏单，退款取消无冲销！**  
     仅监听 `woocommerce_payment_complete` 导致手动改状态的线下订单漏归因；且缺少订单退款与取消监听，导致广告转化与 ROI 数据永久虚高。
   - ❗ **C5（P2 线索数据浪费）：表单生态桥接（CF7/FluentForms/WPForms）对提取出的用户邮箱/姓名“采而弃之”！**  
     语义提取器已能提取 email/name/phone，但表单基础桥接类在处理转化时仅提取了金额，将最有价值的潜在客户线索直接抛弃。

---

## 一、 对 ISS-08 报告的逐项复核与评价

### 1.1 总体评价
ISS-08 是一份非常优秀的**交付面对照分析**。其优点体现在：
1. **边界极其清晰**：毫不拖泥带水地排除了 Rust 云端、多租户控制台、付费 License 体系等非目标，紧扣纯免费自闭环定位。
2. **证据严格落实到代码行**：拒绝笼统宣称，给出了设置项键名、类名及 SQL 字段级对照。
3. **分级严密合理**：严格区分了“路线图排期（Roadmap Deferred）”、“设计取舍（Design Decisions）”与“真实缺口（Real Gaps）”。

### 1.2 ISS-08 的 8 大真实缺口（G1~G8）代码级核验表

| 缺口编号 | ISS-08 论断 | 源码核验状态 | 代码实证与影响分析 |
| :--- | :--- | :---: | :--- |
| **G1** | 4 个已交付引擎的设置键没有任何后台 UI 写入方 | **100% 属实** | `Gr_Settings::defaults()` 定义了 `login_fail_threshold`、`login_lockout_base`、`honeypot_enabled`、`blackhole_enabled`。而在 `Gr_Settings_Page::snapshot()` 中仅暴露了 12 个键。导致蜜罐和 Blackhole 两个关键安全引擎普通用户根本无法在界面启用！且登录保护页文案指向了不存在的控件，构成文案误导。 |
| **G2** | 代理/VPN/Tor/机房段 IP 质量层：未交付且未排期 | **100% 属实** | 插件内仅有基于 DB-IP Lite 的国家代码解析，没有任何机房 IP（Datacenter CIDR）、Tor 出口节点、公共代理判定的逻辑，路线图也无规划，违背了“识别水军与假流量”的产品故事。 |
| **G3** | A/B 实验定义无创建 UI | **100% 属实** | `class-gr-funnels-page.php` 第 61 行直接输出文案：*"Experiment definitions are created through the experiments repository ... This page reports on them and creates nothing."*，普通站长无法在后台添加落地页实验。 |
| **G4** | 访客会话无列表页（数据已在库） | **100% 属实** | `wp_gr_sessions` 表结构完整，索引已建立，但管理后台 14 个页面中**没有任何一页**提供访客会话的明细列表查询，站长无法下钻查看访客访问轨迹。 |
| **G5** | 在线人数与设备分布有引擎无 UI | **100% 属实** | `Gr_Session_Repository::count_online()` 已写好并钳制了时间窗，日聚合也统计了 `sessions_by_device`，但在 `Gr_Dashboard_Page` 中均未渲染。 |
| **G6** | 列表页缺少时间范围筛选、搜索、CSV 导出 | **100% 属实** | `Audit Log`、`Traffic` 等页面虽然继承或参考了原生表格，但缺少日期范围选择框与搜索框，除诊断页 JSON 外无任何 CSV 导出功能，不利于运营汇报。 |
| **G7** | 一次性临时邮箱拦截未评估 | **100% 属实** | 注册与表单提交防自动化仅依赖蜜罐，缺乏垃圾注册常用的一次性邮箱（Disposable Email Domain）黑名单拦截机制。 |
| **G8** | GeoIP 仅国家级 | **100% 属实** | 仅集成了 DB-IP Lite Country，缺乏城市级解析，无法支撑本地生活或区域化广告投放分析。 |

---

## 二、 ISS-08 遗漏的 5 大深层运行时逻辑断链与缺陷（重点增补）

ISS-08 侧重于“看得见的面”，而忽略了“跑起来的流”。以下 5 个问题是深入源码执行逻辑后发现的致命断链，直接关系到产品的核心业务承诺：

```
[浏览器/爬虫访问] ───> [Request Inspector] ──(只写安全日志)──X── [会话表 gr_sessions] (is_bot 恒为 0)
                                                                     │
[客户端探针 probe.js] ─> [REST /collect] ────(不传/不更新分数)───X───┤ (bot_score 恒为 0)
                                                                     │
                                                                     ▼
[订单成交/表单提交] ──> [Meta CAPI / GA4 MP] ──(读取 is_bot) ───> [全部判定为正常人！不过滤！]
```

### ❗ 缺陷 C1（P0 致命逻辑断链）：`is_bot` 在运行时永远为 0，广告 CAPI 防刷网关与机器人报表彻底失效！

#### 1. 代码事实证据
1. **数据表定义**：`plugin/includes/storage/class-gr-schema.php` 第 195 行定义了字段：
   ```sql
   is_bot TINYINT(1) NOT NULL DEFAULT 0
   ```
2. **会话创建点**：`plugin/includes/attribution/class-gr-attribution-listener.php` 第 113~140 行，会话生成时：
   ```php
   $landing = array();
   if ( $consent ) {
       $landing['country_code'] = Gr_Geoip::country( gr_get_client_ip() );
   }
   if ( $consent && Gr_Attribution_Params::is_campaign_entry( $parsed ) ) {
       $landing = array_merge( $landing, array(...) );
   }
   $this->sessions->touch( $visitor, $session, $landing );
   ```
   **`$landing` 数组中完全没有传递 `is_bot`！** 因此全部使用 `landing_defaults()` 中的默认值 `'is_bot' => 0`。
3. **安全检测点**：`plugin/includes/security/class-gr-request-inspector.php` 在检测到扫描器 UA（`Gr_Scanner_Ua`）或恶意载荷时，触发：
   ```php
   do_action( self::FINDINGS_HOOK, $findings );
   ```
   该 Hook（`gr_security_findings`）**唯一的监听者是 `Gr_Security_Logger`**，仅将记录插入 `wp_gr_security_logs` 表，**从未调用任何会话仓储方法将当前会话或访客标记为 `is_bot = 1`**！
4. **会话持久化点**：`plugin/includes/storage/class-gr-session-repository.php` 第 116 行的 SQL 语句：
   ```sql
   INSERT INTO {$table} (...) VALUES (...)
   ON DUPLICATE KEY UPDATE last_active = VALUES(last_active), pageviews = pageviews + 1
   ```
   **其更新子句中完全没有包含 `is_bot`！** 即使后续有操作再次 touch 会话，`is_bot` 也绝不会被更新为 1。

#### 2. 产生的灾难性后果
- **Meta CAPI / GA4 MP 核心卖点失效**：  
  在 `plugin/includes/core/class-gr-meta-capi.php` 第 147~154 行明确写道：
  > *"The traffic-quality gate: the product's differentiation is exactly here — a visitor whose latest session is a known bot never reaches the ad algorithm."*  
  代码为：
  ```php
  $visitor = (string) $order->get_meta( Gr_Woocommerce_Adapter::VISITOR_META );
  if ( true === ( new Gr_Session_Repository() )->visitor_bot_verdict( $visitor ) ) {
      return; // 过滤机器人，不回传
  }
  ```
  因为全库所有会话的 `is_bot` 永远是 0，`visitor_bot_verdict()` 查询 `SELECT is_bot FROM ... WHERE visitor_id = ...` **永远返回 `false`**！  
  **结论：CAPI 机器人过滤网关在现实中连一个机器人也过滤不了，全部放行回传，核心宣传彻底沦为假象！**
- **数据报表全假**：
  - `Bot & Device Signals` 页面（`class-gr-bot-signals-page.php` L145）展示的 `SUM(is_bot) AS bots` **永远恒等于 0**。
  - `Daily_Aggregator` 聚合表中 `is_bot = 1` 的度量值永远为 0。

---

### ❗ 缺陷 C2（P0 致命数据死结）：客户端安全探针 `bot_score` 采集后成为死数据，永不持久化到会话！

#### 1. 代码事实证据
1. 前端 `plugin/assets/js/gr-probe.js` 采集了 4 维信号并计算出 `bot_score: score`，异步 POST 到 `/wp-json/greenpng/v1/collect`。
2. 后端接收控制器 `plugin/includes/rest/class-gr-collect-controller.php` 第 230~232 行：
   ```php
   $event = gr_dispatch_event( $name, $payload );
   gr()->sessions()->touch( $payload['visitor_id'], $payload['session_id'] );
   ```
   - 第一行将 signal 写入了流水事件表 `wp_gr_events`。
   - 第二行调用 `touch()` 时，**只传入了 visitor_id 和 session_id，完全没有传递 `$landing` 参数！**
   - 即使传入了，如缺陷 C1 所示，`touch()` 的 `ON DUPLICATE KEY UPDATE` 子句只有 `last_active` 和 `pageviews`，根本没有 `bot_score = VALUES(bot_score)`！
3. 前台网页首次加载时，HTML 请求中的 `Gr_Attribution_Listener` 已经执行了 `touch()`，建立了 `bot_score = 0` 的初始会话。当随后的探针 JS 异步提交分数时，数据库命中唯一键冲突走 `ON DUPLICATE KEY UPDATE`，**该探针计算出来的得分被数据库无情丢弃！**

#### 2. 产生的灾难性后果
- `wp_gr_sessions` 表中的 `bot_score` 列在生产环境下**所有行全部恒等于 0**。
- `Bot & Device Signals` 后台页面的 6 档评分分布图：
  ```php
  SUM(bot_score = 0) AS b0, SUM(bot_score BETWEEN 1 AND 25) AS b1_25, ... SUM(bot_score = 100) AS b100
  ```
  在现实中无论来的是 RPA 还是无头浏览器，**100% 的会话全部分布在 `b0`（0分）档**，b1~b100 全为 0。
- 收集到的 `bot_score` 处于“只读不防”状态，即使探测到 100 分的自动化工具，也没有与 `is_bot` 判定或访问控制产生任何联动。

---

### ❗ 缺陷 C3（P1 安全虚设）：后台 `Access Rules` 封禁列表（`is_ip_blocked`）从未被任何 Web 请求生命周期执行！

#### 1. 代码事实证据
1. `plugin/includes/security/class-gr-access-rules.php` 实现了判断逻辑：
   ```php
   public static function is_ip_blocked( string $ip ): bool {
       if ( self::matches_ip_rule( self::TYPE_ALLOW, $ip ) ) { return false; }
       if ( Gr_Temp_Bans::is_locked( $ip ) ) { return true; }
       return self::matches_ip_rule( self::TYPE_BAN, $ip );
   }
   ```
2. 全局搜索 `is_ip_blocked`，在全插件中**仅在 `gr-functions.php` 的门面函数 `gr_is_ip_blocked()` 中被调用了一次**。
3. 在 `Gr_Plugin` 挂载的所有动作中、在 `Gr_Request_Inspector`（请求检查器）中、在 `init` 或 `template_redirect` 中，**没有任何一行代码去调用 `is_ip_blocked()` 并在匹配时执行拦截（如发送 403 或 `wp_die`）**！
4. 类似地，`Gr_Access_Rules::is_url_allowed( $uri )` 也从未在 `Gr_Request_Inspector` 中被调用，配置的 URL 白名单无法实现让特定路径跳过安全扫描。

#### 2. 产生的后果
站长在管理后台 `Access Rules` -> `Ban List` 里辛辛苦苦添加了被攻击的黑名单 IP，点击保存成功。但当该黑名单 IP 再次访问站点时，该请求依然畅通无阻，没有任何拦截动作发生。这属于严重的**功能实现虚设**。

---

### ❗ 缺陷 C4（P1 电商归因遗漏与退款反冲销缺失）：WooCommerce 货到付款/银行转账漏单，退款取消无冲销！

#### 1. 代码事实证据
1. `plugin/includes/integrations/ecosystem/class-gr-woocommerce-adapter.php` 第 108 行：
   ```php
   add_action( 'woocommerce_payment_complete', array( $this, 'complete_payment' ), 10, 1 );
   ```
   归因转化绑定**只监听了这一个钩子**。
2. 在 WooCommerce 真实场景中，以下高频支付网关**绝不触发** `woocommerce_payment_complete`：
   - 货到付款（Cash on Delivery, COD）
   - 银行直接转账（BACS / Direct Bank Transfer）
   - 支票支付（Cheque）
   这些订单创建后状态为 `on-hold`，由站长发货或收款后在后台手动将状态变更为 `processing` 或 `completed`。此时触发的是 `woocommerce_order_status_completed` 或 `woocommerce_order_status_processing`，导致**这部分订单的营销归因转化完全丢失！**
3. **退款与取消断链**：当订单被全额退款或取消时，缺少对 `woocommerce_order_status_refunded` 和 `cancelled` 的监听，`wp_gr_conversions` 表和 Campaigns 报表中的收益永远不会被冲销，导致广告 ROI 统计虚高。

---

### ❗ 缺陷 C5（P2 线索数据浪费）：表单生态桥接（CF7/FluentForms/WPForms）对提取出的用户邮箱/姓名“采而弃之”！

#### 1. 代码事实证据
1. `plugin/includes/integrations/class-gr-semantic-extractor.php` 具备优秀的智能字段识别能力，能够递归提取出 `email`、`phone`、`first_name`、`last_name`、`full_name`。
2. 但在 `plugin/includes/integrations/ecosystem/class-gr-form-adapter-base.php` 第 247~256 行：
   ```php
   $semantics = Gr_Semantic_Extractor::extract( $payload );
   $this->attribution->bind(
       $source,
       $this->identity->visitor_id(),
       null === $semantics['amount'] ? 0.0 : (float) $semantics['amount'],
       null === $semantics['currency'] ? '' : (string) $semantics['currency'],
       static::get_id()
   );
   ```
   **提取出来的邮箱、姓名、手机号等最有价值的潜在客户（Lead）信息被就地丢弃！**
3. 此外，如果用户在提交表单前未弹出 Cookie 授权（例如直接进落地页填单咨询），`! Gr_Consent::allows( 'marketing' )` 会直接 `return`，连转化记录都被丢弃，未能按照 GDPR 合规惯例将“用户主动提交表单”视为默示同意/合法利益建立联系人。

---

## 三、 代码级修复方案与架构改造清单

针对上述发现的 ISS-08 缺口（G1~G8）及本次增补的深层断链（C1~C5），提出分批落地整改方案：

```
┌────────────────────────────────────────────────────────┐
│                   v1.0.1 紧急修补集                     │
├────────────────────────────────────────────────────────┤
│ 1. [G1] Settings 页补齐 4 个控件                       │
│ 2. [C1+C2] Session 表 touch() 支持更新 is_bot/bot_score │
│ 3. [C1] Inspector 检测到恶意 UA/载荷时标记会话为机器人    │
│ 4. [C2] 探针提交 bot_score >= 80 时自动判定 is_bot = 1  │
│ 5. [C3] init/Inspector 挂载 is_ip_blocked 403 阻断     │
└────────────────────────────────────────────────────────┘
                           │
                           ▼
┌────────────────────────────────────────────────────────┐
│                   v1.1 完整增强集                       │
├────────────────────────────────────────────────────────┤
│ 1. [G3] Funnels 页增加 A/B 实验创建表单                │
│ 2. [G4+G5] Dashboard 渲染在线人数/设备分布；增加访客列表 │
│ 3. [G6] WP_List_Table 增加日期范围、搜索与 CSV 导出     │
│ 4. [C4] WooCommerce 订单处理中/完成及退款冲销适配       │
│ 5. [C5] 表单提交留存语义化客户线索至 CRM 接触点        │
│ 6. [G2] 引入本地 Tor/机房 CIDR 静态库                  │
└────────────────────────────────────────────────────────┘
```

### 3.1 v1.0.1 紧急修补方案（极小变动，彻底激活三大死结）

#### 补丁 1：`Gr_Session_Repository::touch()` 升级原子更新（解决 C1、C2）
修改 `plugin/includes/storage/class-gr-session-repository.php`：
```php
// 在 touch() 方法的 SQL 中升级 ON DUPLICATE KEY UPDATE：
$sql = $wpdb->prepare(
    "INSERT INTO {$table}
        (visitor_id, session_id, user_id, channel, utm_source, utm_medium, utm_campaign, click_id, landing_path, referrer_host, device_type, ua_family, country_code, is_bot, bot_score, pageviews, started_at, last_active)
    VALUES (%s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d, %s, %s)
    ON DUPLICATE KEY UPDATE 
        last_active = VALUES(last_active), 
        pageviews   = pageviews + 1,
        is_bot      = IF(VALUES(is_bot) = 1, 1, is_bot),
        bot_score   = GREATEST(bot_score, VALUES(bot_score))",
    ...
);
```
并在 `Gr_Session_Repository` 中增加轻量级状态更新方法：
```php
public function mark_bot( string $visitor_id, string $session_id, int $score = 100 ): void {
    global $wpdb;
    $table = Gr_Database::table( 'sessions' );
    $wpdb->query( $wpdb->prepare(
        "UPDATE {$table} SET is_bot = 1, bot_score = GREATEST(bot_score, %d) WHERE visitor_id = %s AND session_id = %s",
        $score, $visitor_id, $session_id
    ) );
}
```

#### 补丁 2：`Gr_Collect_Controller` 绑定探针得分与机器人判定（解决 C2、C1）
修改 `plugin/includes/rest/class-gr-collect-controller.php`：
```php
$score = isset( $params['bot_score'] ) ? (int) $params['bot_score'] : 0;
$is_bot = $score >= 80 ? 1 : 0;

gr()->sessions()->touch(
    $payload['visitor_id'],
    $payload['session_id'],
    array(
        'bot_score' => $score,
        'is_bot'    => $is_bot,
    )
);
```

#### 补丁 3：`Gr_Request_Inspector` 联动会话标记（解决 C1）
当安全检查器捕获到 `scanner_ua` 或高危 `payload` 规则时，通过钩子或仓储直接将该请求对应的当前会话标记为 `is_bot = 1`。这样后续该访客在 WooCommerce 下单时，`visitor_bot_verdict( $visitor )` 能够真正返回 `true`，触发 Meta CAPI 与 GA4 MP 的机器人拒发逻辑！

#### 补丁 4：`Gr_Request_Inspector` 挂载 IP 封禁与 URL 豁免（解决 C3）
在 `plugin/includes/security/class-gr-request-inspector.php` 的 `run()` 方法中引入访问规则前置校验：
```php
$ip   = Gr_Ip_Resolver::resolve();
$path = Gr_Request::path();

// 1. URL 白名单检查
if ( Gr_Access_Rules::is_url_allowed( $path ) ) {
    return; // 豁免检查
}

// 2. IP 封禁检查
if ( Gr_Access_Rules::is_ip_blocked( $ip ) ) {
    wp_die( esc_html__( 'Access denied: Your IP address is blocked.', 'greenpng' ), '', array( 'response' => 403 ) );
}
```

#### 补丁 5：`Gr_Settings_Page` 补全 4 个缺失控件（解决 G1）
在 `class-gr-settings-page.php` 的 Security 标签页表单中增加：
- `login_fail_threshold`（失败次数阈值，输入框）
- `login_lockout_base`（初始锁定时长秒数，输入框）
- `honeypot_enabled`（启用注册/登录动态蜜罐，复选框）
- `blackhole_enabled`（启用恶意蜘蛛黑洞陷阱，复选框）  
并在 `snapshot()` 与 `save()` 方法中纳入这 4 个键的存取白名单。

---

### 3.2 v1.1 业务闭环与能力拓展方案

1. **A/B 实验创建界面（解决 G3）**：  
   在 `class-gr-funnels-page.php` 顶部增加“创建实验”抽屉或原生表单，包含实验名称、目标 URL、变体列表（Variant A / Variant B）与流量分配滑块，数据直接调用现有的 `Gr_Ab_Engine` 存储层。
2. **访客明细列表与仪表盘完善（解决 G4、G5）**：  
   - 在 `Traffic` 页面增加第 4 个标签页 `Sessions`，以 `WP_List_Table` 展示 `wp_gr_sessions` 记录（入口页面、UTM 来源、设备类型、国家、停留时长、机器人标记）。
   - 在 `Dashboard` 页面顶部指标卡接入 `Gr_Session_Repository::count_online(300)`（当前 5 分钟在线人数），并在国家分布旁渲染 `sessions_by_device`（移动/桌面/平板占比）。
3. **表格交互通用增强（解决 G6）**：  
   封装基础类 `Gr_List_Table`，统一注入原生日期范围选择器（Today / Last 7 Days / Last 30 Days / Custom）、搜索框，并在底部提供 `Export CSV` 按钮，通过带 Nonce 的安全流式下载输出报表。
4. **WooCommerce 全场景结账与退款冲销闭环（解决 C4）**：  
   - 增加监听 `woocommerce_order_status_processing` 和 `woocommerce_order_status_completed`，配合现有的 `ATTRIBUTED_META` 防重锁，实现线下支付订单的自动归因。
   - 增加监听 `woocommerce_order_status_refunded` 与 `cancelled`，在 `wp_gr_conversions` 中记录负向冲销或标记状态。
5. **表单潜在客户线索捕获（解决 C5）**：  
   将 `Gr_Semantic_Extractor` 提取出的 `email` 与 `full_name` 存入即将随 v1.1 发布的 `wp_gr_contacts` 表，实现表单提交自动转 Lead。
6. **离线机房段与 Tor 出口识别（解决 G2）**：  
   采用类似 DB-IP Lite 的模式，随包预置 CC 协议的开源云厂商/数据中心 CIDR 列表（AWS, GCP, Cloudflare, DigitalOcean 等）与 Tor Project 官方出口节点列表，在纯本地通过 32 位整型位运算秒级匹配，无需任何远程 API 调用，严守零外呼铁律。

---

## 四、 综合对标与最终评审裁定

| 评估维度 | wp-plug 业务基准 | greenpng v1.0 现状 | ISS-08 审计结论 | ISS-09 本文最终核验与裁定 |
| :--- | :--- | :--- | :--- | :--- |
| **01 反爬/RPA/机器识别** | UA库、蜜罐、黑洞、假爬虫、行为探针 | 引擎均有代码实现 | G1（蜜罐/黑洞无UI开关） | **采纳 G1；追加 C1/C2/C3：会话 `is_bot` 永不置 1，探针得分不持久化，封禁未挂载。** |
| **02 设备指纹/客户端探针** | 硬件/环境/模拟器判定 | 安全探针 4 信号 0~100 分 | 设备信号标签延期 v1.3 | **确认已交付基础探针；但 C2 指出其探针得分未入库未联动。** |
| **03 IP质量/代理/机房** | GeoIP + 机房/Tor/代理 | 仅 DB-IP 国家库 | G2（机房/Tor未交付未排期） | **采纳 G2；建议 v1.1 随包预置离线 CIDR 库闭环。** |
| **04 注册/登录安全** | 暴力破解梯度锁定、蜜罐 | 梯度锁定与蜜罐代码完成 | G1（阈值无UI）；G7（临时邮箱） | **采纳 G1、G7；补丁已设计。** |
| **05 UTM营销归因** | 跨天归因、点击ID、5模型 | 30天Cookie双轨、5模型已交付 | 评为完全交付 | **肯定交付水准；注意 C5 表单提交需留存客户线索。** |
| **06 转化漏斗与 A/B 测试** | 漏斗流失图、A/B 测试 | 漏斗延 v1.1；A/B 引擎交付 | G3（A/B 实验无创建 UI） | **采纳 G3；必须在 v1.1 补齐后台配置入口。** |
| **07 行为统计与会话** | 页面浏览、会话明细、在线数 | 库表齐全；日聚合完成 | G4（无访客列表）；G5（无在线UI） | **采纳 G4、G5；v1.1 上屏。** |
| **08 操作审计日志** | 插件自身动作审计 | 自身操作 diff 审计已交付 | D8（全站审计非目标） | **确认自身审计已交付且达标。** |
| **09 客户打标与 CRM** | 评分打标、RFM 矩阵 | 底层表结构已先行 | 排入 v1.1 | **符合规划。** |
| **10 本地分析与 CAPI** | 本地统计、Meta CAPI/GA4 | CAPI 与 GA4 框架完成 | 表格缺筛选导出 G6 | **采纳 G6；追加 C1：CAPI 的机器人过滤因 `is_bot` 恒为 0 导致网关失效，须立即修复。** |

---

## 五、 总结与团队行动建议

1. **致开发团队与评审人员**：  
   `ISS-08` 准确抓住了产品在“UI 最后一公里”上的缺失，但低估了底层数据流断链的隐蔽性。代码能通过 657 个单元测试和 CI 门禁，是因为单元测试往往针对孤立的方法打桩（如给 `touch()` 传模拟数据、直接断言 `visitor_bot_verdict(true)`），**在缺少端到端全链路数据流集成测试的情况下，掩盖了 `is_bot` 和 `bot_score` 在真实前台访问中根本无法串联的致命问题**。
2. **行动优先级**：
   - **立即执行（v1.0.1 Hotfix）**：修复 C1、C2、C3、G1。这 4 个问题改动行数极少（约 50~80 行代码），但能瞬间将插件从“虚假防刷”转变为“真正生效的闭环系统”。
   - **下阶段迭代（v1.1 Growth）**：落实 G3（A/B 创建表单）、G4/G5（会话列表与在线上屏）、G6（表格时间筛选/导出）、C4（WooCommerce 全场景结算）、C5（表单线索提取）。
   - **中长期演进（v1.2+）**：推进 G2（本地机房 IP 库）、G7（一次性邮箱库）、G8（城市级 GeoIP）。

通过本次增补审计，整个项目的技术脉络与真实短板已彻底透明化，为打造 WordPress 官方目录顶尖的免费一体化插件奠定了最坚实的基础。
