# ADR-0013 · CRM 联系人、线索评分与 RFM 分层（含表单线索留存 C5 后半）

> **编号**：0013　**日期**：2026-09-13　**状态**：已接受
> **背景材料**：`docs/12` v1.1 CRM 行、`docs/15-iss09-review-verdicts.md` §1/§2（C5 裁定：表单提交≠默示同意）、ADR-0005、wp-plug/28 §七（评分衰减与 NTILE 原型）、wp-plug/46（RFM 八象限、行为直通 CRM 意向度）。
> **触发问题**：`gr_contacts`/`gr_tags`/`gr_contact_tags` 表自 S5 落地但零读写方；表单桥提取的 email/name（ISS-09 C5）无落点；评分/分层引擎与页面未建。CRM 是 v1.1 最大的「表已在、引擎与界面全缺」域。

---

## 1. 背景

1. `Gr_Semantic_Extractor::extract()` 已产出 email/first_name/last_name/full_name（+amount/currency），`Gr_Form_Adapter_Base::process()` 在 cookie 轨 + 营销同意门内只消费 amount/currency——线索留存只差落库一步（C5 属 v1.1 CRM 范畴的评审结论）。
2. 同类实读（wp-plug/28/46）：FormRank 静态单点评分（缺陷：无衰减无动态）；wp-plug/28 原型用 NTILE(5) 窗口函数算 RFM——**MySQL 8.0+ 才有，本项目下界 MySQL 5.7（ADR-0007），结构性不可用**；其评分含 14 天闲置扣分模型。
3. `gr_contacts` 现有列（email_hash UNIQUE/email_enc/first_name/last_name/user_id/lead_score/ltv/rfm_segment/first_seen/last_seen）无 visitor_id——**无联系人↔访客联结，行为事件无法喂评分**（wp-plug/46「行为流直通 CRM 意向度」的先决件）。

## 2. 决策

### D1 · 联结列：`gr_contacts.visitor_id`（cookie 轨专属，最新优先）

- DB_VERSION 2 增列 `visitor_id CHAR(64) NOT NULL DEFAULT ''` + `KEY visitor (visitor_id)`（与 ADR-0010/0011 同批 dbDelta，`docs/05` §2 表清单同步）。
- 写入纪律与归因绑定同源：**只写 cookie 轨 visitor_id**（回退轨每日盐哈希无跨天价值，ADR-0005——存了也是垃圾联结）；同一邮箱重复捕获时**最新 cookie 轨胜**（评分跟最新活动，联结保鲜）。
- 此列是行为评分、会话钻取、RFM 频次口径的共同基础。

### D2 · 线索留存（C5 落地）：表单桥扩展，同意门不动

- `Gr_Form_Adapter_Base::process()` 在既有同意门（`has_cookie_identity() && Gr_Consent::allows('marketing')`）之后：email 非空 ⇒ upsert `gr_contacts`（`email_hash = Gr_Secrets::hash_pii_sha256(归一小写 email)`，`email_enc = Gr_Secrets::encrypt()` 信封，names 截列宽，visitor_id 按 D1，first_seen 首写/last_seen 滑动，`sys:form:{bridge}` 源标签附加）。UNIQUE email_hash 收敛重复提交。
- **电话不留存**（gr_contacts 无 phone 列，v1.1 不为它加列——提取器照旧提取但丢弃，边界如实记录在页面文案）。ISS-09「表单提交=默示同意」论断已被 `docs/15` §1 驳回，本设计维持显式同意门，不重开。

### D3 · 线索评分：窗口化重算引擎（衰减=窗口老化，规则可配）

- 规则存储：非 autoload option `gr_scoring_rules`（≤30 条；每条 {event_name（闭词表：pageview + behavior 四事件 + conversion）、points（-100..100）、daily_cap（0..10，防刷顶）、active}），Scoring Rules 页 CRUD（WP 原生表单+List Table 纪律）。
- 引擎：**确定性从源重算**——score = Σ 规则分 × 30 天窗内 `gr_events`（按 visitor_id 聚合，daily_cap 按日钳制），0..100 封顶。触发面：① 每日队列全量重算（接触 last_seen 有变动者）；② 联系人档案页按需单算；③ 规则保存后队列批量重算。**规则改动可追溯生效、重算自愈、无漂移**——优于 wp-plug/28 的增量累加+闲置扣分（其扣分模型被窗口老化天然覆盖：旧事件自动出窗，无需另设惩罚算术）。
- **bot 结论消费（docs/12 既定语义）**：联系人联结的会话/访客判定 `is_bot=1` ⇒ score 强制 0 + 附加 `sys:suspected_bot` 标签（`gr_tags.is_system=1`）。**系统标签只附加不自动摘除**（判定粘滞，ADR-0009 D2 同源；站长可手摘，自动化不再复加的语义边界如实写进页面提示）。原始探针信号/检测明细**永不入画像**——只有 is_bot 结论这一个布尔跨轨。
- 窗口与保留期对齐的诚实注记：行为事件 30 天保留 ⇒ 评分窗即数据窗；contribution of conversions（永久表）单独计 ltv。

### D4 · RFM：PHP 五等分（5.7 无窗口函数），八象限词表

- R=last_seen、F=gr_conversions 绑定计数（净额口径 ADR-0010 后仍计全部绑定行——购买发生过）、M=SUM(amount WHERE status='active')（退款冲销进 LTV 口径，D3 同理）。
- 五等分在 **PHP 内排序分桶**（按聚合列排序后切五段——MySQL 5.7 无 NTILE，wp-plug/28 的 SQL 原型结构性不可用；联系人体量下 PHP 分桶可测且无方言风险）。
- 段名词表八象限（`rfm_segment` VARCHAR(16) 收敛）：champions/loyal/potential/new/at-risk/hibernating/lost/needs-attention——写入 `rfm_segment` 列；r/f/m 分值不落列（档案页现算现示，无 schema 膨胀）。重算与 D3 同一队列趟次。

### D5 · 界面与隐私边界

- Contacts 页（列表：掩码邮箱 `Gr_Secrets::mask`、标签、score、RFM 段、ltv；筛选按标签/段）、Contact Profile 页（行内进入：解密邮箱揭示——`manage_options` 能力门 + gr_audit_logs 记揭示；时间线 = gr_events(30d) + 绑定 conversions + 会话列表）、Scoring Rules 页（D3 规则 CRUD）。
- WP 隐私 API 导出/擦除覆盖 gr_contacts/gr_tags/gr_contact_tags（email_hash/email_enc 按 hash 列擦除语义——擦除 = 行删除或 email 双列置空 + 关联结清，随实现配 Privacy_API 用例）。
- 邮箱在 contacts 之外不落明文（docs/05 §1 既有铁则不变）。

## 3. 后果

- 正面：C5 两轮悬置收口；行为→CRM 闭环（wp-plug/46 原型的可本地化子集）；评分可配置、可追溯、自愈；RFM 兼容 5.7 下界。
- 负面/风险：评分窗=事件保留窗（30 天）的口径要在页面如实标注（长周期联系人靠 conversions/ltv 维度补偿）；系统标签 attach-only 语义需文案讲清（避免「摘了又回来」的困惑——不会回来：判定粘滞但站长手摘为终态）。
- 合规：email 全程加密+哈希双轨；揭示走能力门+审计；同意门沿用 ADR-0005；DB_VERSION 步进一批完成。
- 修订关联：`docs/05`（visitor_id 列）、`docs/06`（Contacts/Profile/Scoring Rules 页规格）、`docs/12`（CRM 行）、readme/POT 随实现同步。
