# ISS-10 · v1.0.1 热修代码深度审计与闭环核验报告

> **报告属性**：针对 `greenpng v1.0.1`（Phase 7 热修复 H1~H7、ADR-0009、docs/15 评审落地）的代码级闭环核验与架构质量审计报告。  
> **审计基线**：  
> 1. 输入问题源：`iss/ISS-08`（交付面差距分析 G1~G8）、`iss/ISS-09`（运行时断链审计 C1~C5）。  
> 2. 裁定与决策基准：`docs/15-iss09-review-verdicts.md`、`docs/adr/0009-bot-verdict-wiring-ban-enforcement-and-offline-order-attribution.md`。  
> 3. 验收代码基线：`git diff v1.0.0..v1.0.1`（33 个修改文件、+1,797 / -82 行代码）、单元测试 679 例全绿、phpcs 零警告、PHPStan Level 6 清洁、E2E 12/12 通过。  
> **审计原则**：严谨客观，实事求是；代码即事实，执行流即证据；严格恪守纯免费、零云端外呼与双向兼容红线。  
> **审计日期**：2026-09-14。

---

## 0. 执行摘要与最终评审结论

在 `ISS-09` 报告指出 `greenpng v1.0.0` 存在的 5 大深层运行时断链（C1~C5）及 `ISS-08` 遗留的设置项缺口（G1）后，开发团队以极高的工程素养迅速响应：
1. 编写了极其严谨的裁定文档 [`docs/15-iss09-review-verdicts.md`](file:///Users/macos/greenpng/docs/15-iss09-review-verdicts.md)，对全部论断进行了源码与数据流级的事实核验；
2. 形成了架构决策记录 [`docs/adr/0009-bot-verdict-wiring-ban-enforcement-and-offline-order-attribution.md`](file:///Users/macos/greenpng/docs/adr/0009-bot-verdict-wiring-ban-enforcement-and-offline-order-attribution.md)；
3. 落地了 Phase 7（H1~H7）共 7 项工程任务，发布了 **v1.0.1 热修复版本**。

经过本次对 v1.0.1 源码、执行生命周期、并发安全性与测试用例的全量审计，给出如下权威结论：

- **修复质量评级：卓越（Exceptional，A+）**。开发团队没有采取打补丁式的“头痛医头”，而是立足于前台性能预算（docs/09）、WordPress 请求生命周期时序机制以及安全档位隔离模型，对方案进行了深度推演与升华，**其实际实现质量显著超越了 ISS-09 最初提出的雏形方案**。
- **核心断链彻底闭环**：
  - ✅ **C1 & C2 闭环**：探针 `bot_score` 正式入库，`is_bot` 在运行时能够被探针判定与恶意扫描器检测双向置 1，Meta CAPI 与 GA4 MP 的机器人过滤网关真正具备了拦截能力！
  - ✅ **C3 闭环**：请求检查器前门正式挂载 IP 静态封禁与 URL 豁免，黑名单不再是摆设。
  - ✅ **C4 前半闭环**：WooCommerce Store API 线下支付（COD）等“生而 paid”订单的漏归因漏洞被彻底堵死。
  - ✅ **G1 闭环**：5 个关键安全设置旋钮在后台完整渲染，并首次引入“架构不变式测试”，永久性阻断了同类疏漏的发生。
- **剩余工作合理收敛**：退款冲销（C4 后半）、表单客户线索留存（C5 前半）、A/B 创建 UI（G3）、会话明细列表（G4）等，均已清晰划入 **v1.1 路线图**，并完成了数据结构与时序层面的前置准备。

---

## 一、 对 Phase 7（H1~H7）热修代码的逐项深度解构与技术评价

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                           v1.0.1 核心修复时序与数据流全景图                       │
├─────────────────────────────────────────────────────────────────────────────────┤
│ 1. [前门检查] init@10: Gr_Request_Inspector                                     │
│    ├── Gr_Access_Rules::is_static_banned($ip) ──[命中]──> 403 wp_die (无条件阻断) │
│    ├── Gr_Temp_Bans::is_locked($ip) [block档] ──[命中]──> 403 wp_die            │
│    └── Gr_Access_Rules::is_url_allowed($path) ──[命中]──> 跳过后续检测器 (URL豁免)│
│                                                                                 │
│ 2. [会话生成] template_redirect@10: Gr_Attribution_Listener                      │
│    └── touch($visitor, $session) ──> 插入基础会话 (is_bot=0, bot_score=0)        │
│                                                                                 │
│ 3. [探针回写] POST /wp-json/greenpng/v1/collect                                 │
│    └── apply_probe_score() ──> GREATEST(bot_score), is_bot = (score >= 70 ? 1 : is_bot)
│                                                                                 │
│ 4. [检测回写] shutdown 钩子: Gr_Security_Conclusions::mark_current_session()      │
│    └── high档威胁 (scanner_ua等) ──> mark_session_bot() ──> is_bot = 1 (晚于第2步)│
│                                                                                 │
│ 5. [转化过滤] 订单成交 / 表单提交 ──> Meta CAPI / GA4 MP                         │
│    └── visitor_bot_verdict() ──[查询 is_bot] ──> 为 1 则坚决拒发广告算法！         │
└─────────────────────────────────────────────────────────────────────────────────┘
```

### 1.1 H1（G1 修复）：Security 标签 5 控件与架构不变式测试

- **代码实现**：[`class-gr-settings-page.php`](file:///Users/macos/greenpng/plugin/includes/admin/class-gr-settings-page.php)
  - 在 Security 标签页新增渲染 5 个设置控件：`bot_verdict_threshold`（机器人判定阈值，默认 70）、`login_fail_threshold`（登录失败阈值，默认 5）、`login_lockout_base`（锁定基数，默认 300 秒）、`honeypot_enabled`（蜜罐开关）、`blackhole_enabled`（黑洞陷阱开关）。
  - 表单保存逻辑实施了严格的**边界钳制防御**（阈值 1..100，失败 2..100，基数 60..86400 秒），并确保缺省字段回落到安全默认值而非 0。
- **架构创新超越点（不变式测试）**：[`tests/Unit/SettingsPageTest.php#L300`](file:///Users/macos/greenpng/tests/Unit/SettingsPageTest.php#L300)
  ```php
  public function testEveryScalarSettingKeyHasAnAdminWriter(): void
  ```
  该测试通过反射提取 `Gr_Settings_Page::snapshot()` 的可写键集合，断言 `Gr_Settings::defaults()` 中除特定跨页托管键外的所有标量设置项，**必须在设置页存在对应的输入端**！
- **专家点评**：这是极具前瞻性的工程实践。不仅修复了 G1，更将“防止设置项在 UI 漏配”固化为 CI 自动化防线，彻底杜绝了此类隐患复发。

---

### 1.2 H2 & H3（C1/C2 修复）：探针得分回写与机器人判定阈值机制

- **代码实现**：
  - [`class-gr-session-repository.php`](file:///Users/macos/greenpng/plugin/includes/storage/class-gr-session-repository.php#L157)：新增 `apply_probe_score( $visitor, $session, $score, $verdict )`
    ```sql
    UPDATE {$table} 
    SET bot_score = GREATEST(bot_score, %d), 
        is_bot    = IF(%d = 1, 1, is_bot) 
    WHERE visitor_id = %s AND session_id = %s
    ```
  - [`class-gr-collect-controller.php`](file:///Users/macos/greenpng/plugin/includes/rest/class-gr-collect-controller.php#L249)：在采集端点接收 `signal` 事件时，调用 `apply_probe_score()`，以 `score >= verdict_threshold()` 判定是否置 1。
- **对比与技术超越分析**：
  - **为何驳回 ISS-09 提出的“直接改 `touch()` 的 `ON DUPLICATE KEY UPDATE`”方案？**  
    `touch()` 属于前台每个普通页面请求的核心路径，其 SQL 形状被 `docs/09` 性能预算（≤2 条 SQL，执行时间微秒级）与已有并发测试严密锚定。探针上报属于独立的异步 REST 请求，理应消耗独立的 REST 采集预算。在 `collect` 接口中执行独立的 `apply_probe_score()`，**既不增加前台 HTML 页面的 SQL 开销，又保持了职责单一性**。
  - **阈值设计的精妙考量**：放弃了 ISS-09 建议的硬编码 80，采用设置项 `bot_verdict_threshold`，默认值定为 **70**。
    - **原理推导**：客户端探针（`gr-probe.js`）的 4 个信号权重为 40/30/20/10。如果阈值设为 40，则真实前端开发人员开启 Chrome Headless/WebDriver 就会被一票否决；设为 70 则必须满足“WebDriver(40) + 第二特征(≥30)”或“三个次要特征齐发(40+20+10)”的**双信号佐证原则**，极大降低了对高净值技术型真实客户的误判率，且站长可在后台自由微调。
  - **粘滞性（Sticky Verdict）**：`GREATEST(bot_score)` 确保分数只升不降；`IF(%d = 1, 1, is_bot)` 确保一旦定罪终生有效，后续弱信号绝不能为机器人“洗白”。

---

### 1.3 H4（C1 修复）：安全检测器威胁结论的生命周期时序闭环

- **代码实现**：[`class-gr-security-conclusions.php`](file:///Users/macos/greenpng/plugin/includes/security/class-gr-security-conclusions.php#L108)
  ```php
  private static function arm_marker( array $conclusion ): void {
      if ( self::$marker_armed || self::TIER_HIGH !== $conclusion['bot_tier'] ) {
          return;
      }
      self::$marker_armed = true;
      add_action( 'shutdown', array( self::class, 'mark_current_session' ), 10, 0 );
  }
  ```
- **关键技术瓶颈突破（时序竞争问题）**：
  - ISS-09 曾建议在 `Gr_Request_Inspector` 命中时立即标记会话。**开发团队敏锐地指出了该建议中的时序致命伤**：
    - `Gr_Request_Inspector` 运行在 `init@10`；
    - 而访客会话行是由 `Gr_Attribution_Listener` 运行在 `template_redirect@10` 时才写入数据库；
    - 如果在 `init` 阶段执行 `UPDATE`，目标会话行在数据库中**根本尚未创建**！
  - **解决方案**：引入 PHP 级 `shutdown` 动作延迟标记！WP 核心将 `shutdown` 挂载在 PHP 原生 `register_shutdown_function` 中，无论请求正常结束还是因 `wp_die` 中断，`shutdown` 均必定执行。
  - 到了 `shutdown` 阶段，会话行早已由 `template_redirect` 创建完毕，此时执行 `mark_session_bot()` 必定精准命中！
  - **诚实边界划分**：对于直接向 `wp-login.php` 爆破或直接抓取黑洞路径、完全不触发前台页面的孤立恶意请求，由于本身就不存在会话行，标记 0 行落空属正常设计，不污染业务会话表。

---

### 1.4 H5（C3 修复）：检查器前门静态封禁与 URL 白名单执法

- **代码实现**：
  - [`class-gr-access-rules.php`](file:///Users/macos/greenpng/plugin/includes/security/class-gr-access-rules.php#L90)：新增 `is_static_banned( $ip )` 谓词；
  - [`class-gr-request-inspector.php`](file:///Users/macos/greenpng/plugin/includes/security/class-gr-request-inspector.php#L65)：在 `run()` 中植入 `front_door()` 执法守卫。
- **多维安全边界与防护层次**：
  1. **静态封禁无条件阻断**：站长在后台手动添加的封禁规则属于显式明确指令，不受 `security_action_mode` 影响，命中直接输出 403 并终止；
  2. **临时锁随模式联动**：登录失败产生的临时梯度锁（`Gr_Temp_Bans`），仅在设置模式为 `block` 时在全站执行前门 403 阻断；在 `log` 模式下仅保持观察，严格避免了“仅记录模式下因密码输错导致全站封禁”的负面体验；
  3. **正交隔离**：URL 白名单（`is_url_allowed`）仅豁免检测器（免除内容与载荷扫描），**绝不豁免 IP 封禁**。内容轴与地址轴完全正交解耦；
  4. **失败开放（Fail-open）**：前门阻断逻辑整体处于 `try/catch` 保护中，一旦数据库异常自动捕获并上报，绝不阻断正常访客访问。

---

### 1.5 H6 & H7（C4 修复）：WooCommerce 线下网关与“生而 Paid”订单归因闭环

- **代码实现**：[`class-gr-woocommerce-adapter.php`](file:///Users/macos/greenpng/plugin/includes/integrations/ecosystem/class-gr-woocommerce-adapter.php#L115)
  ```php
  add_action( 'woocommerce_payment_complete', array( $this, 'complete_payment' ), 10, 1 );
  add_action( 'woocommerce_order_status_processing', array( $this, 'complete_payment' ), 10, 1 );
  add_action( 'woocommerce_order_status_completed', array( $this, 'complete_payment' ), 10, 1 );
  ```
- **深度机理解密（为何 Store API 线下单会丢归因？）**：
  - 结合 ADR-0008（round 14）对 WooCommerce 11.1 核心源码的实地测试证实：
    - 在 WooCommerce 古腾堡 Blocks 现代结账模式（Store API）下，选择货到付款（COD）等线下网关的订单，**被创建时初始状态直接就是 `'processing'`**；
    - 既然初始状态已经是已支付态（processing），WooCommerce 核心中的 `payment_complete()` 函数会判断“非未支付态”直接退出，**该钩子结构性地永远不会触发**！
  - 增挂 `woocommerce_order_status_processing` 和 `completed` 之后，无论是 Store API 创建直接进入处理中的订单，还是管理员在后台手动将 Pending/On-hold 改为 Processing/Completed 的订单，均能稳定触发归因绑定。
  - 借助现有的 `_gr_attributed` 订单元数据锁与 `wp_gr_conversions` 的 `uk_source` 唯一索引双重防重线，多次触发自然坍缩为单条记录，幂等性坚如磐石。

---

## 二、 对 docs/15 裁定中驳回与延期项的技术复审

在 `docs/15` 中，同事对 `ISS-09` 提出的部分建议做出了“延期至 v1.1”或“不采纳”的裁定。我们对这些裁定进行了复审，认定其**逻辑严密、决策正确**：

### 2.1 关于 C4 后半（订单退款与取消反冲销）延期至 v1.1 的复审
- **同事裁定**：不在 v1.0.1 仓促实施，排入 v1.1 独立 ADR 论证。
- **复审意见（完全赞同）**：
  1. `wp_gr_conversions` 具备唯一键 `uk_source (source_type, source_id)`。如果简单粗暴地在退款时再次 `bind()` 一条负金额记录，会立即触发数据库 `Duplicate entry` 致命错误；
  2. 冲销方案存在 3 种技术选型（A: `UPDATE` 既有行金额为 0 并打上 refunded 标记；B: 物理 `DELETE`；C: 变更数据表结构允许子事件）；
  3. 日聚合器 `Gr_Daily_Aggregator` 具有 7 天数据重算窗口，如果一个订单在第 15 天发生退款，如何与已落库的聚合度量对账，属于严肃的财务与报表设计问题。
  在 v1.0.1 维持稳定闭环、将退款冲销留待 v1.1 建立独立设计，是成熟工程团队的负责任表现。

### 2.2 关于 C5 后半（“表单提交视为默示同意/合法利益”）被驳回的复审
- **同事裁定**：驳回。坚持 ADR-0005 铁律（营销归因轨必须经由 Cookie API 显式授权）。
- **复审意见（完全赞同）**：
  1. 欧洲 GDPR 与各主流司法区对隐私合规的解释极其严格：用户提交咨询表单的“合法利益”仅限于处理该次业务咨询本身，**绝不自动等同于授权网站给该用户设备植入用于未来 30 天跨天广告跟踪的第一方 Cookie**；
  2. greenpng 定位为官方插件目录的五星级合规插件，坚决不在隐私底线上妥协是项目的生命线；
  3. 用户的姓名、邮箱线索将在 v1.1 CRM 模块中作为普通业务联系人合法录入 `gr_contacts`，与营销归因的 Cookie 授权解耦处理，这是最稳健的架构。

---

## 三、 v1.0.1 交付后面向 v1.1 / v1.2 的全景演进矩阵

立足于已发布的 v1.0.1，对产品当前状态与后续路线图做最终对标盘点：

| 业务维度 | v1.0.0 初始状态 | v1.0.1 热修落地状态 | 遗留问题与 v1.1 / v1.2 明确归宿 | 状态判定 |
| :--- | :--- | :--- | :--- | :---: |
| **01 恶意蜘蛛/RPA识别** | 引擎在库，但 `is_bot` 恒为 0，封禁未生效 | **闭环**：探针 70 分与扫描器双向置 1；静态封禁 403 阻断；蜜罐黑洞有 UI | 登录/注册页无会话行时的覆盖扩面（v1.1 行为模块） | ✅ **核心达标** |
| **02 设备指纹/客户端探针** | 探针计算 0~100 分，但入库丢失 | **闭环**：`apply_probe_score` 成功持久化，GREATEST 只升不降 | 设备明细信号展示标签（v1.3，待独立隐私评审） | ✅ **核心达标** |
| **03 IP质量/机房代理** | 仅 DB-IP 国家库，无机房/Tor识别 | 维持现状（国家级） | **ISS-08 G2**：建议 v1.1 随包预置离线机房 CIDR 与 Tor 出口列表（零外呼） | 🗓 **v1.1 待办** |
| **04 登录与注册防护** | 梯度锁定在库，但阈值无后台控件 | **闭环**：Security 标签渲染失败阈值与基数控件，架构不变式守护 | **ISS-08 G7**：一次性临时邮箱域名拦截（v1.2 评估） | ✅ **核心达标** |
| **05 UTM营销归因** | 30 天双轨归因就绪；Store API 线下单漏单 | **闭环**：增挂 processing/completed 钩子，线下单归因闭合 | **C4 后半**：退款/取消订单金额冲销与日聚合重算（v1.1） | ✅ **核心达标** |
| **06 转化漏斗与 A/B 测试** | A/B 引擎与分流完备，但后台无法创建实验 | 维持现状（WP-CLI/代码创建） | **ISS-08 G3**：Funnels 页面增加原生表单式实验创建入口（v1.1） | 🗓 **v1.1 待办** |
| **07 行为统计与访客列表** | 会话表完备，但无访客明细；在线数未上屏 | 维持现状 | **ISS-08 G4 & G5**：Traffic 增加会话列表，Dashboard 上屏在线人数与设备图（v1.1） | 🗓 **v1.1 待办** |
| **08 操作审计与报表交互** | 自身动作审计完备，但表格缺日期筛选/导出 | 维持现状 | **ISS-08 G6**：WP 原生 List Table 通用日期范围、搜索与 CSV 导出增强（v1.1） | 🗓 **v1.1 待办** |
| **09 客户打标与 CRM** | 数据表 S5 已建，CRM 页面待交付 | 维持现状 | Contacts / Profile / Scoring / RFM 五页面交付；**C5 前半** 表单线索入库（v1.1） | 🗓 **v1.1 待办** |
| **10 本地分析与广告 CAPI** | CAPI 框架就绪，但防刷网关因 `is_bot` 恒 0 失效 | **闭环**：`visitor_bot_verdict` 恢复真实拦截力，机器人转化绝不回传 | 战役级无效流量报表（v1.1） | ✅ **核心达标** |

---

## 四、 总结与团队后续工作指引

1. **审计总结**：  
   `greenpng` 在 v1.0.1 版本中打了一场极其漂亮的翻身仗。团队直面 `ISS-09` 揭露的底层断链，在 24 小时内完成了架构推演、决策记录、代码编写、单元测试（679 例）、PHPStan/PHPCS 静态分析及真实 E2E 验证（12/12）的全流程闭环。**v1.0.1 已经成为一个逻辑自洽、真实生效、兼备高性能与高合规度的工业级坚固底座。**
2. **后续建议（v1.1 聚焦）**：
   - 坚决将注意力转向增长与交互（v1.1 重点攻坚：A/B 实验创建表单、访客明细列表、在线人数/设备上屏、表格日期筛选与 CSV 导出、WooCommerce 退款冲销、表单线索入 CRM）；
   - 继续坚持纯免费、零云端外呼、不搞非原生自绘的高质发展路线！
