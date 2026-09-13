# ADR-0012 · 行为洞察模块（停留/滚动/怒点/死点，双端同意门控）

> **编号**：0012　**日期**：2026-09-13　**状态**：已接受
> **背景材料**：`docs/12` v1.1 行为洞察行、readme 探针披露段（两模块承诺早已公开）、ADR-0005（营销轨同意门控）、wp-plug/06-SPEC、wp-plug/28 §七（怒点/死点算法源型）、wp-plug/10 本地分析 explorer 报告（Burst 驻留时长模式、Koko prefetch 拒收）。
> **触发问题**：v1.0 探针只有安全模块（结论 only，合法利益）；行为模块是 readme 已承诺的 v1.1 交付（停留/滚动/怒点/死点），属营销轨——隐私基、采集形态、词表与预算需一次裁清。

---

## 1. 背景

1. `gr-probe.js` 现为 135 行安全模块，体积预算已被静态契约测试锚定（raw 4247B < 8192B、gz ≤ 8192B）——行为模块若内联必破预算。
2. 采集端点已有可扩展词表（`gr_collect_events` 过滤器）+ 每 IP 60/60s 限流 + 8KB body 上限；服务端身份注入（客户端断言身份键即拒）。
3. 同类实读：Burst 以 visibilitychange/pagehide 信标报驻留（明确弃 beforeunload 及缘由）；Koko 服务端拒收 `Sec-Purpose: prefetch`；wp-plug/28 给出怒点（1s/20px/3 连击）与死点（600ms 无 DOM 变更）算法原型。

## 2. 决策

### D1 · 独立文件 `gr-probe-behavior.js`，双闸门控

- 安全模块文件与预算测试一字不动；行为模块为第二文件，独立体积预算测试（raw/gz 各 ≤8192B）。
- 入队闸：`behavior_enabled`（新设置键，**默认 0**——readme 承诺「启用且同意后才发」）与 `probe_enabled` 串联；无同意回落态（服务端本地化 consent 标志）时不输出脚本。
- 客户端再闸：模块读 WP Consent API cookie（`wp_consent_marketing`）自判营销同意，同意方武装监听——CMP 中途授予/撤回即时生效；无 Consent API 宿主则信服务端回落标志。服务端第三闸：`behavior` 组事件无营销同意即 400（`Gr_Consent::allows('marketing')` 同款语义）。

### D2 · 词表四事件、event_group='behavior'、载荷类别化

- `dwell`：驻留秒数分桶（0-15 / 15-60 / 60-180 / 180+，payload `bucket` + `path`）；TimeMe 式活跃计时（30s 空闲暂停、visibilitychange 暂停）。
- `scroll_depth`：**仅在冲刷时发一条**，取最远里程碑（25/50/75/100，rAF 节流监听）——每页浏览恒 ≤1 行，词表服务 4 值而非 4 事件。
- `rage_click`：1s 内 ≥3 击且首末击距 ≤20px（Math.hypot），触发后清历史 + 冷却；payload `clicks` + `locator`。
- `dead_click`：点击非交互元素后 600ms 无 DOM 变更（MutationObserver 观 body，childList+subtree+attributes）且无导航；payload `locator`。input/textarea/select/option 天然豁免。
- **locator 隐私裁定**：`标签名 + (id 或首个 class 词)`，sanitize 后 ≤64 字符——站点自己的标记结构，非访客 PII；**禁**文本内容、禁完整选择器链、禁精确坐标（怒点死点定位靠 locator，分辨率到此为止）。`cta_click` 不进 v1.1 词表（停留/滚动已覆盖投入度，词表保持最小）。
- 服务端：经 `gr_collect_events` 过滤器扩展四事件（核心词表不动）；字段白名单按事件分别收紧；`Sec-Purpose: prefetch/prerender` 头出现即拒（prefetch 不算行为）。

### D3 · 传输：页内缓冲 + 单信标冲刷

- 页内事件缓冲，`pagehide`/`visibilitychange(hidden)` 时单次 `navigator.sendBeacon`（JSON Blob，C13 教训）冲刷；每页事件上限 20（超限静默丢弃，宁缺毋滥）。不引入 fetch keepalive 回退（sendBeacon 覆盖面 2026 年已足够，安全模块同款纪律：API 缺失即静默不发）。
- 量级核算：稳态 ≤2 行/页浏览（dwell + scroll_depth）+ 罕发点击事件；同意门控 + `behavior_enabled` 默认关 + 60/60s 限流三重封顶，`gr_events` 30 天保留期内的行为行占比可控。

### D4 · 落库与报表：gr_events 流水 + daily_stats 汇总词

- 行为事件落 `gr_events`（event_group='behavior'），继承 30 天保留与隐私 API 导出/擦除（营销轨既有覆盖）。
- `Gr_Daily_Aggregator` 词表增 `behavior_events`（metric_key=事件名）：Behavior Insights 页两标签（停留/滚动 | 怒点/死点）的 KPI 瓦读汇总（「读不扫原始表」，docs/05 §1）；按 locator/path 的明细列表读原始事件表（30 天窗内，钻取语义与 Bot & Device Signals 页读会话表同例）。
- **检测路标记覆盖边界重评**（docs/15 §5 悬置项 → 本 ADR 收口）：行为模块不改变登录/注册/陷阱路径无会话行的边界（这些路径不跑归因监听器，也不入队行为脚本——`wp_enqueue_scripts` 不在 wp-login 生命周期）；边界维持如实记录，**不因行为模块扩面而消失**。

### D5 · readme 与隐私披露同步

- 探针披露段从「计划中的行为模块」改为实述四事件 + 双闸（设置默认关 + 访客同意）；隐私导出/擦除覆盖面文案核更新（`gr_events` 行为行已在 v1.0 申明范围内，语义不变）。

## 3. 后果

- 正面：readme 两模块承诺兑现；行为→CRM 评分（ADR-0013）与漏斗步骤匹配（ADR-0014）获得数据源；Koko 式 prefetch 拒收提升口径纯度。
- 负面/风险：MutationObserver 每次点击一个 600ms 观察器（量级可承受，passive 监听 + 观察器即抛）；无 JS 站点无行为数据（诚实边界，与 v1.0 同）。
- 合规：营销轨同意门控三重（客户端武装闸 + 服务端接受闸 + 入队输出闸）；PII 零新增（locator 为站点结构，分桶秒数与里程碑为聚合值）；默认关。
- 修订关联：`docs/12`（行为洞察行）、`docs/06`（Behavior Insights 页规格）、readme、POT 随实现同步。
