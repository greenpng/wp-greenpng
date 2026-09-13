# ADR-0009 · 机器人判定入会话、封禁前门执法与线下单归因挂载（v1.0.1 热修）

> **编号**：0009　**日期**：2026-09-14　**状态**：已接受
> **背景材料**：`iss/ISS-08`（交付面差距分析）、`iss/ISS-09`（运行时断链审计）、`docs/15-iss09-review-verdicts.md`（对两份报告的逐条核验裁定——引用其结论时以 `docs/15` 为准）。
> **触发问题**：v1.0.0 存在三项「引擎已交付、接线缺失」的运行时断链（`docs/15` C1/C2/C3 实锤）与一项转化丢失洞（C4 收窄后成立），以及 ISS-08 G1（四个引擎设置键无 UI 写入方）。

---

## 1. 背景（问题事实，核验见 docs/15）

1. `gr_sessions.is_bot`/`bot_score` 两列有定义、有消费方（CAPI/GA4 质量门 `visitor_bot_verdict()`、Bot & Device Signals 页分布表、日聚合 bot 维度），**但全插件无任何写入方**：`touch()` 的 `ON DUPLICATE KEY UPDATE` 只滑 `last_active`/`pageviews`；采集端点 `touch()` 连 landing 都不传；探针分数只落 `gr_events` 事件行。结果：质量门恒放行、bot 维度报表恒 0——产品「不把机器人喂给广告算法」的差异化承诺（docs/07 §4）在运行时落空。docs/05 §3.2 本就把这两列定义为「探针安全结论」的落点，接线属于遗漏而非设计。
2. `Gr_Access_Rules::is_ip_blocked()`/`is_url_allowed()` 谓词与 Access Rules 页写路径俱在，**但无任何请求生命周期消费方**（仅登录路径消费 `is_trusted_ip`）：站长手写的静态封禁在 log 与 block 两档都不产生任何拦截，属「功能虚设」。docs/10 §4 明确把「显式封禁规则」列为档 3（可拦截）高置信项。
3. WooCommerce 绑定只挂 `woocommerce_payment_complete`；ADR-0008（round 14）已在真栈实证：**Store API 线下网关（COD 等）订单创建即 'processing'**，`payment_complete` 对已支付态结构性早退，绑定永不发生（该路径实际触发的是 `woocommerce_order_status_processing` 钩子）。
4. `login_fail_threshold`、`login_lockout_base`、`honeypot_enabled`、`blackhole_enabled` 四个引擎键在 `Gr_Settings::defaults()` 注册、被引擎消费，但 Settings 页 12 个可写键不含它们——两个 opt-in 引擎普通站长无法启用，Login Protection 页文案还指向不存在的控件（ISS-08 G1）。

## 2. 决策

### D1 · 探针判定阈值：设置键 `bot_verdict_threshold`，默认 70

- 服务端判定（客户端永不自判）：采集端点收到 `signal` 的 `bot_score` 后，`score >= 阈值` ⇒ 会话 `is_bot = 1`。
- **默认 70 = 双信号佐证**：webdriver(40)+任一第二信号(≥30)、或三个次要信号齐发(40+20+10) 才定罪；**单一 webdriver(40) 不定罪**——Chrome 自动化旗标可被真实开发者环境触发，误判一位真客户 = 其转化永不回传广告平台，代价不对称。阈值经 Settings 页 Security 标签可调（1..100）。
- ISS-09 建议的硬编码 80 不采纳：无佐证依据且不可调；70 的推导如上，可由站长按站点客群调整。

### D2 · 判定写回会话：两条独立挂载，`touch()` 保持逐字节不变

- **探针路**（REST collect 请求内）：`handle()` 在既有 `touch()` 之后调用新仓储方法 `apply_probe_score( $visitor, $session, $score, $verdict )` —— 单条 `UPDATE ... SET bot_score = GREATEST(bot_score, %d), is_bot = IF(%d = 1, 1, is_bot)`（分数只升不降、is_bot 粘滞不清零）。行由同请求的 `touch()` 保证存在（含缓存命中页场景）。
- **检测路**（发现→结论）：`Gr_Security_Conclusions` 在派发 `suspected_bot && tier=high` 结论时，**布防一次性 PHP 级 shutdown 标记**；请求结束时以双轨身份调 `mark_session_bot()`（仅 `is_bot = 1`，不动 bot_score）。
- 选 shutdown 的原因：发现发于 `init@10`，而会话行由归因监听器 `template_redirect@10` 落地——标记必须晚于两者；WP 经 `register_shutdown_function('shutdown_action_hook')` 触发 `shutdown` 动作（wp-settings.php 实核），**`wp_die` 后亦执行**，覆盖黑洞陷阱路径。
- **仅 high 档标记**：medium = 未分类怀疑（今日无 medium 规则），不应直接定罪入 `is_bot`。
- **诚实边界（记录在案）**：`wp-login.php`/注册页不走 `template_redirect`、黑洞 `wp_die` 先于监听器 ⇒ 这些路径无会话行可标，标记 0 行落空；有效覆盖 = 普通页面上 scanner_ua/载荷发现 + 探针信号路。此边界不扩大 v1.0.1 范围。
- ISS-09 补丁 1（改 `touch()` 的 ON DUPLICATE 子句）不采纳：前台每请求的 `touch()` SQL 形状已被预算（docs/09 §1.1）与测试锚定；探针结论写走 REST collect 的独立预算口径，新 UPDATE 语句不触碰前台路径。

### D3 · 封禁前门：检查器 `run()` 增设执法卫，静态封禁无条件、临时锁随 block 档

- `Gr_Request_Inspector::run()` 在 `is_admin`/总熔断检查之后、检测器之前：解析 IP → 命中 **静态封禁** ⇒ `wp_die('Access denied.', '', 403)`（与 W12 黑洞同款应答）；`security_action_mode === 'block'` 时 **临时锁**（登录梯度/黑洞 block 落锁）同样 403。
- **静态封禁不随 action_mode 门控**：档位模型的保守性是为「启发式误判伤人」（docs/10 §4 的动机）；站长逐条手写的封禁是显式指令、无误判面，且 docs/10 §4 已把「显式封禁规则」列入档 3 高置信清单。若随 log 档失效，则 G1 式「装饰性」换个模式重现。
- **临时锁只在 block 档执法**：log 档 = 纯观察（档位模型语义），登录梯度锁维持仅登录域记录的现状；block 档下临时锁升格为全站前门拒止（与「Log and block」选项语义一致）。
- 实现上新谓词 `Gr_Access_Rules::is_static_banned()`（allow 恒胜、不含临时锁）供无条件臂；block 档臂调 `Gr_Temp_Bans::is_locked()`。不直接复用 `is_ip_blocked()`——其内部含临时锁，无条件调用会把 log 档的登录锁泄漏成全站拦截。
- **URL 允许列表豁免检测、不豁免封禁**：`is_url_allowed()` 命中 ⇒ 跳过检测器（防误报面），IP 封禁照常评估（地址轴与内容轴正交）。
- 自救阀不动：允许列表恒胜、`wp greenpng unblock`、`GR_SECURITY_OFF` 总熔断三层保持。管理面请求依旧跳过（run() 既有 is_admin 门）。
- 已知边界如实记录：wp-cron 请求走前台 init，若站长封禁了本机/服务器地址将阻断计划任务（属站长可自行恢复的配置错误，三层自救阀均可解）。

### D4 · WooCommerce 状态钩子挂载：绑定面补两钩，退款冲销延期

- `register_hooks()` 增挂 `woocommerce_order_status_processing` 与 `woocommerce_order_status_completed`（均 accepted_args=1，复用 `complete_payment($order_id)` → `bind_order()`）。幂等双防线（`_gr_attributed` meta 锁 + `gr_conversions` UNIQUE）既有，多钩并发/重放恒一行。
- 覆盖：Store API 线下网关生而 processing 的订单（ADR-0008 实证的洞）、管理员手动流转的线下单、以及一切跳过 `payment_complete` 的完成路径。
- **退款/取消冲销不进 v1.0.1**：需要独立设计（`gr_conversions` 的 UNIQUE source 键排除了第二行 ⇒ 冲销须 UPDATE/软标而非 INSERT；日聚合 7 天重算窗与跨窗退款的口径；金额改负 vs 状态列 vs 删除的取舍）——列为 v1.1 任务并附开放问题（docs/15 §4）。

### D5 · G1 收口：五个控件 + 架构不变式测试

- Settings 页 Security 标签新增：`login_fail_threshold`（number，钳 ≥2）、`login_lockout_base`（number 秒，钳 ≥60）、`honeypot_enabled`、`blackhole_enabled`（checkbox，各带一句风险/语义披露）、`bot_verdict_threshold`（number 1..100）。`snapshot()`/保存白名单同步纳入（审计 diff 覆盖）。
- 新增架构测试：`Gr_Settings::defaults()` 全部标量键 **必须** ⊆ 各管理页可写键并集（豁免清单：`retention_days`/`retention_rows` 归 Data Retention 页、`capi_meta_enabled`/`capi_ga4_enabled` 归 Analytics & CAPI 页）——把「引擎键必须有 UI 写入方」变成可测不变式，堵死 ISS-08 G1 的产生机制。

## 3. 后果

- 正面：CAPI/GA4 质量门、bot 维度报表、评分分布表首次获得真实数据源；静态封禁与 URL 豁免从装饰变为生效；Store API 线下单归因闭合；两个 opt-in 引擎对普通站长可用。
- 负面/风险：静态封禁无条件 403 意味着误封自救依赖三层阀（已记录）；探针阈值 70 首次落定，站点实测后可能需调（已做成设置）；检测路标记在登录/注册/黑洞路径无行可标（边界已记录，v1.1 行为模块扩面时再评估）。
- 合规：`is_bot`/`bot_score` 本就是安全轨合法利益结论（docs/05 §3.2 语义、readme 探针披露段既有），本 ADR 只是把既有列接上写方，不新增任何原始信号落库；结论通道「只传结论不传信号」契约（docs/07 §4）不变。
- 修订关联：`docs/05` §3.2（写方语义）、`docs/10` §4（执法点）、`docs/12`（修订记录）、`docs/13`（Phase 7 任务行）随本 ADR 一并更新。
