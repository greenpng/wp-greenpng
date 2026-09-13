# ADR-0014 · 漏斗定义、步骤推进与 A/B 实验创建表单（G3）

> **编号**：0014　**日期**：2026-09-13　**状态**：已接受
> **背景材料**：`docs/12` v1.1 漏斗行（含 ISS-08 G3）、`docs/06`（漏斗/步骤流失/目标三标签 + 纯 CSS 阶梯图规范、表单式定义非画布）、wp-plug/05-SPEC（漏斗状态机与流失公式源型）、wp-plug/06 维度实读（wpfunnels/funnel-builder 步模型——CPT 耦合页建器，非我方形态）。
> **触发问题**：`gr_funnels`/`gr_funnel_sessions` 表自 S5 落地零读写方；A/B 实验定义只有 WP-CLI/代码通道（G3：普通站长无法创建实验）。

---

## 1. 背景

1. 表形态已在位：`gr_funnels(name/slug UNIQUE/flow_json/is_active)` + `gr_funnel_sessions(UNIQUE funnel_session, current_step/max_step/completed_at)`——状态机载体齐备，缺定义 UI、匹配引擎与报表。
2. A/B 实验存于非 autoload option `gr_ab_experiments`（`Gr_Ab_Experiments` 仓储，≤20 实验 × ≤8 变体、键规范化、脏批防御、空批整行删除）；分流引擎与显著性检验（Z 检验）v1.0 已交付并实测——**G3 只缺写入 UI，不缺引擎**。
3. 同类实读：wpfunnels 的步骤 = CPT 子文 + `_step_type`/`_steps_order` meta，与页建器耦合——其「漏斗=页面序列」模型要求接管建站，与本项目「漏斗=既有 URL/事件序列」的表单式定义（`docs/06` 既定）相反；CartFlows 模式的 CSS 阶梯图已被 `docs/06` 采纳为漏斗可视化基线。

## 2. 决策

### D1 · 漏斗定义：flow_json 步骤数组，表单式 CRUD

- 步骤结构：`[{name, match: {kind: 'url'|'event', value, compare: 'exact'|'prefix'}}]`，**≤10 步**；`kind=url` 匹配路径（exact 或前缀），`kind=event` 匹配 `gr_events.event_name`（闭词表：pageview + behavior 四事件 + conversion + ab）。
- Funnels 页「漏斗」标签：创建/编辑表单（name、slug 自动、逐步行内编辑、启停）；校验：≥2 步、match value 列宽内、步骤名唯一。无拖拽画布、无可视化编辑器（`docs/06` 既定减法）。
- 仓储走 storage 层新 `Gr_Funnel_Repository`（prepare 纪律 + slug 唯一冲突处理），无 schema 变更（flow_json 承载全部定义）。

### D2 · 推进引擎：事件总线挂载，前进式状态机

- `Gr_Funnel_Tracker` 挂 `gr_event` 动作（事件总线既有）：每事件按 active 漏斗匹配步骤——`kind=event` 按名匹配；`kind=url` 由 pageview 事件的 path 载荷匹配。
- 命中第 N 步 ⇒ upsert `gr_funnel_sessions`（UNIQUE funnel+session）：`current_step` 只进不退（回访早步零降级）、`max_step` 粘滞取大、末步命中时 `completed_at` 落值。会话键 = `gr_session` cookie（30 分钟滑窗——漏斗旅程天然短窗，回退轨日身份亦可用：会话行本就以 session_id 记）。
- 写入预算：tracker 在事件总线内（事件行已落的同请求），每事件对每个 active 漏斗 ≤1 条 upsert——active 漏斗数本身站长可控，无前台额外查询（匹配内存完成）。

### D3 · 阶梯流失图：纯 CSS Flexbox + dashicons + 屏幕阅读器等价表

- 每步一行：步骤名 + 人数/占比条（Flexbox 宽度百分比）+ 流失率徽章（dashicons）；**不引入 JS 图表库**（`docs/06` 既定，uplot 仅 Dashboard 趋势线既有用途不扩面）。
- 计数口径：进入第 N 步人数 = `max_step >= N` 计数（状态机单调性保证该口径成立）；步间流失率 = (进入N − 进入N+1)/进入N。数据窗 = `gr_funnel_sessions` 30 天保留期（页面注明口径）。
- 屏幕阅读器等价表（`screen-reader-text` 包裹的全量数据表）与图同源渲染。

### D4 · G3：A/B 实验创建表单（复用既有仓储，零 schema 变更）

- Funnels 页「A/B」标签增创建表单：实验名/slug、变体名 2..8（行内增删）、启停。校验与仓储边界逐条对齐（≤20 实验、slug 化、单变体拒绝、重复归一）——表单是仓储的 UI 投影，不另立校验层。
- 列表呈现既有实验 + 状态（显著性引擎现算：曝光/转化/Z 值/胜出，读 `ab_counts()` 既有聚合）。

### D5 · 目标标签：绑定转化读侧

- 「目标」标签 = 各 source_type 的 `gr_conversions` 计数与净额（ADR-0010 口径自动生效）+ 漏斗 completed 计数对照——纯读侧页面，无新引擎。

## 3. 后果

- 正面：G3 收口（普通站长可建实验）；漏斗三标签（定义/流失/目标）与 `docs/06` 页树对齐；状态机载体启用；匹配引擎复用事件总线（无新采集面）。
- 负面/风险：漏斗步骤 URL 匹配不含查询串（prefix/exact 以路径为界——站内多变体着陆页需用 event 步或规范化路径，页面文案如实提示）；active 漏斗 × 事件量 = 状态机行增长（`funnel_sessions` 30 天保留 + 500k 行上限既有双轨瘦身兜底）。
- 合规：漏斗会话行属营销轨（同意门控下的事件流驱动）；无 PII（visitor_id 既有列）；无新表无新列。
- 修订关联：`docs/06`（Funnels & Goals 三标签规格）、`docs/12`（漏斗行）、`docs/05` §3.4（无词表新增——流失图读原始状态机表属钻取语义，与 Behavior Insights 同例登记）随实现同步。
