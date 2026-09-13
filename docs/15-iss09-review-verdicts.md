# 15. iss/ ISS-09 深度审计报告评审结论 (Review Verdicts)

> **评审对象**：`iss/ISS-09-POST-V1-0-DEEP-GAP-AUDIT-AND-ISS-08-EVALUATION.md`（同事对 ISS-08 的复核 + 自报的 C1~C5 运行时断链审计）。
> **评审日期**：2026-09-14。**评审方法**：逐条论断实地核验（调用链 grep、源码通读、docs/05 §3.2·docs/07 §4·docs/10 §4 既有设计比对、ADR-0008 round 14 真栈实证记录比对）。
> **本文是 ISS-09 在本仓库的唯一引用口径**：引用其结论时以本文核验结果为准，不直接引用其原文表述（与 `docs/14` 对 ISS-01~07 的处理同构）。**修复方案以 `docs/adr/0009` 为准**，本文记录方案取舍理由。

---

## 1. 事实核验记录（C1~C5）

| 核验项 | 结果 | 核验证据 |
| :--- | :--- | :--- |
| C1 `is_bot` 运行时永不置 1 | ✅ **实锤（P0）** | `Gr_Session_Repository::touch()` 的 `ON DUPLICATE KEY UPDATE` 仅 `last_active`/`pageviews`；归因监听器 landing 不含 `is_bot`；全插件 grep `is_bot` 写方仅 touch() 的 INSERT 默认值 0。消费方却在：`visitor_bot_verdict()`（Meta CAPI 与 GA4 MP 双双调用）、`bot_score_distribution()` 的 `SUM(is_bot)`、日聚合 `GROUP BY is_bot`。**docs/05 §3.2 本就定义这两列为「探针安全结论」落点——接线属遗漏，非设计取舍** |
| C2 探针 `bot_score` 采集后成死数据 | ✅ **实锤（P0）** | `Gr_Collect_Controller::handle()` 校验 bot_score 后仅 `gr_dispatch_event()`（落事件表）+ `touch($visitor, $session)`（连 landing 都不传）；无任何到会话列的写路径。评分分布表 6 档将 100% 堆积 0 档 |
| C3 `is_ip_blocked` 无请求周期消费方 | ✅ **实锤（P1）** | 全插件消费面 grep：仅 `gr-functions.php` 门面定义 + 定义类自身；登录路径只消费 `is_trusted_ip`（3 处）。`is_url_allowed()` 同样零消费方。**docs/10 §4 已把「显式封禁规则」列入档 3 可拦截项——执法挂载属遗漏** |
| C4 COD/BACS「绝不触发 payment_complete」⇒ 漏归因 | ⚠️ **部分成立（P1，范围收窄）** | 「绑定只挂 `woocommerce_payment_complete`」属实；**但断言过宽**：ADR-0008（round 14，WC 11.1 源码实读 + 真栈复现）实证的是——`payment_complete` 仅从未支付状态（on-hold/pending/failed/cancelled）触发，**Store API 线下网关订单创建即 'processing'**，钩子结构性不可达（该路径实际触发 `..._order_status_processing`）；经典结账线下单生而 on-hold 属未支付态。**真实洞 = 生而 paid 状态的订单**（Store API 线下网关、部分 API/管理员建单），非「一切 COD/BACS/支票」。修复方向（补状态钩子）成立 |
| C4 退款/取消无冲销 ⇒ ROI 虚高 | ✅ 属实（能力缺口，v1.1） | 适配器仅三钩，无 refunded/cancelled 监听。**非 v1.0.1 可安全速修**：`gr_conversions` 的 UNIQUE source 键排除第二行（冲销须 UPDATE/软标）；日聚合 7 天重算窗与跨窗退款口径需设计——列 v1.1 任务（§4） |
| C5 表单桥接丢弃提取出的邮箱/姓名 | ✅ 事实成立（v1.1 CRM 范畴） | `Gr_Form_Adapter_Base::process()` 仅消费 `amount`/`currency`；`Gr_Semantic_Extractor` 提取的 email/name/phone 无落点。`gr_contacts` 表已随 S5 落地、CRM 页 v1.1——线索留存属 v1.1 联系人任务，非 v1.0 缺陷 |
| C5 「表单提交应视为默示同意/合法利益」 | ❌ **不采纳** | 违背 ADR-0005（营销轨同意门控、仅 cookie 轨绑定）与 `docs/14` §4 已裁定先例（「Recital 49 外推一切信号默认开」被拒——安全用途合法利益≠营销用途）。且结构上无同意即无 cookie 轨身份、本无可绑定对象；「连转化记录都被丢弃」的表述不成立（无 cookie 轨即无归因值，ADR-0005 语义） |
| ISS-08 G1~G8 复核 | ✅ 全部维持 | ISS-09 的 8/8 确认与本仓库核验一致；G1 随 v1.0.1 修复（ADR-0009 D5），G2~G8 维持原分类（§4） |
| ISS-09「事实准确率 95%+」对 ISS-08 的评价 | 参考 | 评级类表述不作事实引用（`docs/14` 先例）；核验表如上 |

## 2. 修复方案采编（ISS-09 提案 → 采纳形态）

| ISS-09 提案 | 裁定 | 理由（详见 ADR-0009） |
| :--- | :--- | :--- |
| 补丁 1：改 `touch()` 的 ON DUPLICATE 子句纳 `is_bot`/`bot_score` | **改造后采纳** | 前台每请求 `touch()` 的 SQL 形状已被预算（docs/09 §1.1）与测试锚定；改为 REST collect 预算口径下的独立 UPDATE（`apply_probe_score`，GREATEST + is_bot 粘滞） |
| 补丁 2：采集端 `$score >= 80` 硬编码判定 | **改造后采纳** | 阈值改设置键 `bot_verdict_threshold`，默认 **70 = 双信号佐证**（webdriver40+任一第二信号；单一 webdriver 40 不定罪——误判真客户 = 其转化永不回传，代价不对称）；80 无推导依据且不可调 |
| 补丁 3：Inspector 检测到恶意 UA/载荷时标记会话 | **改造后采纳** | 挂载点不在 init（发现时会话行尚未落地——归因监听器 `template_redirect@10` 晚于 `init@10`）；改为结论通道 high 档布防 **PHP 级 shutdown 标记**（`register_shutdown_function('shutdown_action_hook')` 实核，`wp_die` 后亦执行）。仅 high 档标记；登录/注册/黑洞路径无会话行的边界如实记录 |
| 补丁 4：`run()` 挂 `is_ip_blocked()` 无条件 403 + URL 白名单豁免 | **改造后采纳** | 静态封禁无条件 403（显式指令、docs/10 §4 档 3 高置信）；**临时锁仅 block 档执法**——`is_ip_blocked()` 内含临时锁，无条件调用会把 log 档登录梯度锁泄漏成全站拦截，破坏档位模型；新增 `is_static_banned()` 谓词分离两臂。URL 豁免只免检测、不免封禁（地址轴/内容轴正交） |
| 补丁 5：Settings 页补 4 控件 | **采纳并扩展** | 增至 5 控件（+`bot_verdict_threshold`）；补 D5 架构不变式测试（defaults 键集 ⊆ 各页可写键并集）堵死 G1 产生机制 |
| C4 状态钩子（v1.1 提案） | **提前进 v1.0.1** | 两钩复用 `complete_payment`，幂等双防线既有，改动极小；ADR-0008 已实证洞与钩子路径。退款冲销维持 v1.1 |
| C5 线索入 `gr_contacts`（v1.1 提案） | 维持 v1.1 | 同意门控下留存（ADR-0005）；随 v1.1 CRM 交付 |
| v1.0.1「约 50~80 行」工作量估计 | 不采信 | 本仓库纪律要求每修必配测试 + 文档同步（ADR/docs/13/docs/05/docs/10）；实际规模以 docs/13 Phase 7 任务行为准 |

## 3. 与 docs/14 既有裁定的关系

- 「表单提交视为默示同意」（ISS-09 C5 后半）与 docs/14 §4 已拒的「Recital 49 外推」同族，再次拒绝并留档。
- C4 的断言修正不推翻 docs/14 对 ISS-03 系列的裁定；ADR-0008 的 Store API 实证（round 14）是本次 C4 裁定的直接证据。
- ISS-08（本仓库 Droid 产出）经 ISS-09 复核 8/8 成立，其 G 分级维持；本文不重复 ISS-08 内容，引用以其原文 + 本文 §1 核验列为准。

## 4. 后续任务映射

**v1.0.1（docs/13 Phase 7，随本裁定落地）**：H1 G1 五控件+文案闭环；H2 会话仓 `apply_probe_score`/`mark_session_bot`；H3 采集端判定接线；H4 结论通道 shutdown 标记；H5 检查器前门执法（静态无条件/临时随 block 档/URL 豁免）；H6 Woo 状态钩子挂载；H7 e2e 09 自然 COD 路径扩展。

**v1.1（并入 docs/12 既有 v1.1 范围）**：ISS-08 G3（A/B 创建表单）、G4/G5（访客列表/在线数上屏）、G6（表格筛选/搜索/CSV）；**新增**：C4 退款冲销（开放问题：UNIQUE source 键下冲销须 UPDATE/软标而非 INSERT；日聚合 7 天重算窗与跨窗退款口径；金额改负 vs 状态列 vs 删除）、C5 线索留存（同意门控下入 `gr_contacts`）。

**维持讨论（不自动排期）**：ISS-08 G2（本地 Tor/机房段库 vs 仅外部 API vs 裁定不做）、G7（一次性邮箱）、G8（城市级 GeoIP）、D8（全站审计范围）。

## 5. 开放项

- `bot_verdict_threshold` 默认 70 的站点实测校准（首发后按真实分布回调）。
- 检测路标记的覆盖边界（登录/注册/黑洞路径无会话行）在 v1.1 行为模块扩面时重评。
- 退款冲销三项开放问题的 ADR（v1.1 启动时立）。
