# ISS-08 · v1.0 交付后：实际 UI 与功能 对照用户需求与同类插件的差距分析

> **系列关系**：本文件是 `iss/` 系列的新条目，与 ISS-01~07（同事的**开发前**规划审计）性质不同——本文的对象是**已发布的 v1.0.0 实际代码**，每条结论都落到具体文件与可复核事实。引用 ISS-01~07 的旧结论时，一律以 `docs/14-iss-review-verdicts.md` 的核验结果为准。
> **评审基准**：`/Users/macos/wp-plug` 的 10 大业务维度 / 226 个同类开源项目 / 46 份前期文档 / 40 页管理后台静态原型（`preview/wp-admin-ui/01~40`）。
> **评审对象**：greenpng v1.0.0（tag 已推送，五条 CI 工作流全绿）。管理面 14 页、15 张物理表、21 个设置键、85 项任务（`docs/13` 全 ✅）。
> **评审日期**：2026-09-14。**用途**：站长与团队后续讨论用，未入 git 提交。

---

## 0. 口径与边界（先说清楚什么不算差距）

1. **云端/Pro 永久非目标**：参考项目的 Rust 云端控制台、跨站联防、License 体系（预览页 38、文档 01/08/22 的付费侧）**一律不作为差距**。greenpng 定位纯免费、纯本地闭环（ADR-0002，AGENTS.md 铁律 2）。对照范围因此是 40 页原型中的 39 页（38 号云端页剔除）。
2. **预览页不是实现目标**：40 页原型是自绘设计系统 + 硬编码假数据，禁止移植（AGENTS.md §6、docs/06 §2.1）。本文只把它的**功能点**当作用户需求清单，UI 形态对照以 WP 原生规范（docs/06）为准。
3. **46 份文档的数字不是事实**：其中的性能数字、测试结论（"100% PASS"类表述）不作引用（AGENTS.md §6）；但其**业务需求梳理与竞品功能矩阵**是有效的用户需求来源。
4. **设计内减法不算差距**：greenpng 自己的规划已把 40 页收敛为 20 页（17 页保留、14 页合并、5 页废弃、+1 Settings，docs/06 §1），v1.0 交付其中 14 页。**路线图明确延期项**（docs/12）与 **docs/14 已裁定取舍项**单独归类，不与真实缺口混谈。

**判定分级**：
- ✅ **已交付**：v1.0 有对应页面/引擎且可用。
- 🗓 **路线图延期**：docs/12 明确排入 v1.1 / v1.2 / v1.3。
- ⚖️ **裁定取舍**：章程非目标（docs/00 §4）或 docs/14 裁定不采纳，理由已记录。
- ❗ **真实缺口**：既不在路线图、也未经裁定、但对照用户需求或自身可用性存在实际问题。**这是本文的核心产出（§4）。**

---

## 1. v1.0 实际交付面盘点（证据基线）

### 1.1 管理页 14 页（与 docs/06 §1 树、docs/12「v1.0 管理页」完全一致）

| 页面 | 实际标签/区块（代码实证） | 写入口 |
| :--- | :--- | :--- |
| Settings | General / Security / Attribution 三标签，**恰 12 个可写键** | admin_init + nonce + 审计 |
| Dashboard | 14 天会话/浏览趋势图（uPlot 本地 ≤20KB gzip）+ 每日表 + 30 天国家分布 | 只读 |
| Traffic & Security | 实时流（REST 轮询网格）/ 威胁事件（折叠行）/ 机器人结论 三标签 | 只读 |
| Access Rules | 封禁 / 允许列表 两标签；规则增删 + 批量 | admin_init |
| Login Protection | 爆破审计 / 会话管理（锁查看与释放、CLI 指引、允许列表自救指引） | 释放动作 |
| Bot & Device Signals | FCrDNS 验证表 / Scanner-UA 引擎表 / bot_score 分布（设备信号标签 v1.3） | 只读 |
| Campaigns | 战役 / UTM / 点击ID / 归因模型（5 模型信用对比矩阵）四标签 | 只读 |
| URL Builder | 本地纯前端组装 + 校验 | 无（无副作用） |
| Funnels & Goals | A/B 报表（分桶预览 + 记录数 + z 检验/胜者）——漏斗/流失/目标 v1.1 | 只读 |
| Analytics & CAPI | Meta CAPI / GA4 MP 块：开关、加密凭据存取、自检、GA4 连通性探测 | admin_init |
| IP Intelligence | DB-IP 国家库状态（日期/条数/国家数）+ CC BY 4.0 归属 + 显式更新按钮 | 队列化更新 |
| Audit Log | 对象类型/用户/动作筛选 + 字段级 diff + 服务端分页 | 只读 |
| Data Retention | 8 表双轨（天数 + 行上限）+ 人工 OPTIMIZE（仅按钮） | admin_init |
| Status & Diagnostics | 环境/队列姿态/适配器挂载/表容量 + 脱敏 JSON 导出 | 导出动作 |

### 1.2 引擎与底座（摘要，全量见 docs/13）

身份双轨（30 天签名 cookie + 每日盐回退）、同意门控归因（5 UTM + 8 点击 ID + 5 归因模型分币对账）、WooCommerce 三挂载 + CF7/FluentForms/WPForms 桥接（主钩+回退钩+漂移哨兵）、客户端安全探针（4 信号 40/30/20/10 封顶 100、无持久标识符）、UA 引擎（1468 条 MIT 种子本地化）、FCrDNS 双栈验证（A+AAAA）、蜜罐（时间差+动态字段名+AES 信封）、Blackhole 陷阱、载荷检查（2 条高置信规则族）、梯度登录锁定、浪涌折叠（原子 upsert）、安全→质量结论通道（只传结论不传信号）、A/B（一致性哈希 + Z 检验）、GA4 MP + Meta CAPI（event_id 双端去重、仅回传过质过滤事件）、自适应队列（AS 嗅探→wp-cron 回退）、日聚合（10 指标族、7 天重算窗、瘦身不变式）。

### 1.3 质量与测试证据（如实，非宣称）

单元 657 tests / 3,902 assertions；phpstan L6 零错误；phpcs + PHPCompatibilityWP（7.4 下界）通过；五条 GitHub Actions 工作流在 main 与 tag v1.0.0 均绿（本会话实测状态）。E2E 10 份 spec（01 前台访客链 … 10 隐私政策页）+ ci-seed；管理页的 E2E 覆盖为 Settings/Access Rules/Bot Signals/URL Builder/Login（经 04/05/06）等，Dashboard/Campaigns/Funnels/Analytics/IP Intel/Audit/Retention/Status 页以单元测试为主（见 §5.2 覆盖缺口）。

---

## 2. 十大业务维度逐项对照

参照：wp-plug 十域（各域 README 与 226 项目），greenpng 章程五大域（docs/00 §3）。

| # | wp-plug 维度（项目数） | 同类插件常态 | greenpng v1.0 实际 | 判定 |
| :--- | :--- | :--- | :--- | :--- |
| 01 | 反爬/反机器人/RPA（23） | UA 库比对、蜜罐、陷阱路径、假爬虫过滤 | UA 引擎 1468 条 + FCrDNS 双栈 + 蜜罐 + Blackhole + 载荷两规则族 + 浪涌折叠；默认仅记录（docs/10 §4 保守档） | ✅ 引擎层达标；**蜜罐/Blackhole 两个 opt-in 引擎无 UI 开关（G1）** |
| 02 | 设备指纹/模拟器（22） | Canvas/Audio/WebGL 指纹、模拟器判定 | 安全探针 4 信号 bot_score（合法利益、默认开、可关、readme 披露）；无原始指纹串出客户端 | ✅ 安全子集交付；明细标签 v1.3（独立隐私评审，docs/14 开放项）；指纹营销画像 ⚖️ 永久非目标 |
| 03 | IP 归属/代理/VPN/Tor/欺诈（22） | GeoIP + 机房段/代理/Tor 识别 + 欺诈评分 | DB-IP Lite 国家库开箱 + 显式更新；**代理/VPN/Tor/机房段检测既未交付也未排期**（仅 v1.2 AbuseIPDB 外部选项） | ✅ 国家级已交付；❗ **G2：本地 IP 质量层空缺且路线图无此项** |
| 04 | 注册/登录安全（23） | 失败限次、梯度锁定、一次性邮箱拦截、人机验证 | 梯度锁定（5 败起锁、300s×2ⁿ 封顶 24h、24h 轮记忆、允许列表恒胜、CLI 释放）+ 登录/注册蜜罐 | ✅ 核心交付；**阈值/基数无 UI 旋钮（G1）**；一次性邮箱拦截未考虑（G7）；Turnstile/hCaptcha 🗓 v1.2 |
| 05 | UTM/归因（22） | 参数捕获、30 天持久化、首/末触点 | 5 UTM + 8 点击 ID、30 天签名 cookie（同意门控）、5 模型分币对账、**Campaigns 页 5 模型对比矩阵已随 v1.0 交付（领先 roadmap v1.3 的『对比界面』行）**、URL Builder 本地组装 | ✅ 交付；Safari ITP/同意门控下 cookie 覆盖率窄于同行属 ⚖️ ADR-0005 诚实取舍 |
| 06 | 漏斗/流失/A-B（22） | 漏斗步骤、流失图、弃购挽回、A/B 对比 | A/B 引擎（一致性哈希 + URL 参数/短码 + Z 检验 + 样本不足诚实标注）+ Funnels 页报表 | ✅ 引擎交付；**实验定义无创建 UI（G3）**；漏斗定义/流失图/弃购挽回 🗓 v1.1；可视化画布 ⚖️ 永久非目标（表单式） |
| 07 | 行为流/微事件（22） | 停留、滚动、点击、热图、回放 | 事件总线 + REST 采集 + gr_events 落库（基建就绪）；行为模块（停留/滚动/怒点/死点）| 🗓 v1.1 行为洞察页；会话回放 ⚖️ 永久非目标（隐私最高风险） |
| 08 | 操作审计/行为流水（22） | 核心钩子监听、全站用户操作审计 | Audit Log 页（筛选 + 字段 diff + 分页）；审计范围 = **插件自身动作**（设置/规则/凭据/导出/保留/更新队列等 10 类写入方） | ✅ 自身动作审计交付；全站 WSAL 式审计不在章程五域内 → §6 讨论项 D8 |
| 09 | 打标/评分/RFM（23） | 自动标签、线索评分、RFM 分群 | **安全→质量结论通道 v1.0 已交付**（suspected_bot/bot_tier，原始信号永不入画像）；gr_contacts/gr_contact_tags 表随 S5 先行 | 🗓 v1.1（Contacts/Profile/Scoring/RFM 五页）；与安全联动的架构难点已提前解决 |
| 10 | 本地统计分析（25） | 本地统计、实时监控、无 cookie 口径 | Dashboard（趋势图/日表/国家分布）+ 实时流网格 + 日聚合（瘦身不变式） | ✅ 核心交付；**在线人数有引擎无 UI（G5）** |

---

## 3. 40 页原型 → greenpng 实际落点明细

| 预览页 | greenpng 落点 | 判定 |
| :--- | :--- | :--- |
| 01 仪表盘 | Dashboard | ✅ |
| 02 健康检查清单 | Status & Diagnostics（环境/队列/适配器/容量/导出） | ✅ 实质等价（无罗盘式评分卡，符合 docs/06 禁自绘） |
| 03 实时监控 | Traffic 实时流标签 | ✅ 但无『当前在线』计数（G5） |
| 04 实时流量 / 05 拦截管理 / 06 允许列表 | Traffic 实时流 / Access Rules 封禁+允许列表 | ✅ |
| 07 蜜罐陷阱 | 引擎已交付，**设置开关无 UI（G1）** | ❗ |
| 08 模拟器金库 / 09 设备指纹 | Bot & Device Signals 设备信号标签 | 🗓 v1.3（裁定：独立隐私评审后） |
| 10 登录保护 | Login Protection | ✅（阈值旋钮缺失 G1） |
| 11~15 战役/UTM/点击ID/URL构建/归因模型 | Campaigns 四标签 + URL Builder | ✅（15 号对比矩阵已交付） |
| 16 广告欺诈审计 | bot 结论 + CAPI 质量过滤为底；无按战役无效流量报表 | 部分 → §6 讨论项 D3 |
| 17 漏斗列表 / 19 步骤流失 / 21 目标转化 | Funnels & Goals 对应标签 | 🗓 v1.1（转化绑定引擎 v1.0 已有） |
| 18 漏斗画布 | 表单式定义 + CSS 阶梯图 | ⚖️ 永久非目标（画布）+ 🗓 v1.1（表单/图） |
| 20 弃购挽回 | — | 🗓 v1.1（失焦捕获+令牌核销+防重发） |
| 22 A/B 测试 | Funnels 页 A/B 报表 + 引擎 | ✅ 引擎/**❗ 创建 UI 缺失（G3）** |
| 23 审计流 | Audit Log（自身动作范围） | ✅（范围差异见 D8） |
| 24 用户会话管理 | Login Protection 会话管理标签（锁定会话）；**访客会话列表无页面**（gr_sessions 数据已在库） | 部分 ❗ G4 |
| 25 停留滚动 / 26 怒点死点 / 27 用户时间线 | — | 🗓 v1.1（行为洞察 + Contact Profile 时间线） |
| 28~33 CRM 六页（列表/档案/标签/自动化/评分/RFM） | — | 🗓 v1.1 五页（自动化规则并入 Scoring Rules，范围窄于原型 31） |
| 34 集成分析 | Analytics & CAPI（GA4+Meta v1.0；Matomo 🗓 v1.2；TikTok 🗓 v1.3） | ✅ 主体交付 |
| 35 广告 CAPI | Meta CAPI ✅（Google Ads 离线转化 API ⚖️ 永久非目标，需 Google 审批令牌） | ✅ |
| 36 IP 质量 | GeoIP 国家 ✅；代理/VPN/Tor ❗ G2；AbuseIPDB 🗓 v1.2（opt-in） | 部分 |
| 37 Webhooks | — | 🗓 v1.1 通用（HMAC-SHA256）+ v1.2 消息模板 |
| 38 云端 Key | — | ⚖️ 永久非目标（铁律 2） |
| 39 库瘦身 / 40 诊断导出 | Data Retention / Status & Diagnostics | ✅ |

**汇总**：39 个在范围内的原型页中，**17 页有 v1.0 交付落点；13 页路线图明确延期（v1.1 为主）；4 页裁定取舍；5 页部分交付**（07/16/22/24/36——其中 07/22/24/36 的缺口即 §4 的 G1/G3/G4/G2）。

---

## 4. 真实缺口清单（核心产出）

> 以下各项**均不属于**路线图延期或已裁定取舍；每项附代码级证据。

### G1（P1）· 四个已交付引擎的设置键没有任何后台 UI 写入方

- **证据**：`Gr_Settings::defaults()` 定义 21 键；Settings 页 `snapshot()` 仅 12 键可写。差集恰 4 键：`login_fail_threshold`（5）、`login_lockout_base`（300s）、`honeypot_enabled`（0）、`blackhole_enabled`（0）。全插件 grep 证实除 `class-gr-settings.php` 默认值与引擎 `enabled()/threshold()` 读点外**零写入方**。
- **后果**：蜜罐（W8）与 Blackhole（W12）是** opt-in 引擎**——普通站长不写 WP-CLI/不改 option 就**永远无法启用**这两个已交付并实测过的引擎；登录阈值同理只能用默认值。更严重的是 Login Protection 页文案明写 *"a lock starts at the threshold set under Settings"*——**指向一个 Settings 页并不存在的控件**（误导性文案）。
- **根因**：docs/06 的 Settings 页规范本身就没给这四个键留位置（只列了总开关/代理/匿名化/探针/档位），W7/W8/W12 落地时注册了设置键，UI 规范与引擎任务之间的缝隙没人补。
- **建议**：Security 标签补 4 控件（阈值 number、基数 number、蜜罐 checkbox、Blackhole checkbox + 各自一句风险披露）；Login Protection 页文案与实际控件对齐。工作量小、纯 UI + 既有键。

### G2（P2）· 代理/VPN/Tor/机房段 IP 质量层：未交付且未排期

- **证据**：全插件无 proxy/Tor/datacenter 判定实现；`docs/12` 路线图 v1.1~v1.3 无此项（最近的是 v1.2 的 AbuseIPDB **外部** API，opt-in）。IP Intelligence 页仅有国家库 + v1.2 预告文案。
- **对照**：wp-plug 维度 03（22 个项目）的核心用户需求就是机房流量/匿名代理识别；greenpng 章程域一「看清并挡住假访客」的完整故事里，IP 质量是 UA/探针/FCrDNS 之外的第四根支柱，目前缺席。CAPI 的「仅回传过质过滤事件」过滤面也因此少一层。
- **讨论选项**（都需遵守零外呼铁律 1）：(a) 随包本地数据文件（Tor 出口段 + 常用云厂商 CIDR，DB-IP 式 CC 授权 + 归属，随 release 更新）——与既有 GeoIP 交付模式同构；(b) 仅走 v1.2 AbuseIPDB 外部选项（现状，能力弱）；(c) 明确裁定不做并写入章程非目标（至少消除「隐形缺口」状态）。

### G3（P2）· A/B 实验定义无创建 UI

- **证据**：Funnels 页自述 *"Experiment definitions are created through the experiments repository (the gr_ab_experiments option); … This page reports on them and creates nothing."*——定义实验需要 WP-CLI/写代码。
- **后果**：v1.0 四支柱之一「可做落地页 A/B 测试」对非技术站长实际不可用（引擎、分流、短码、显著性全在，唯独没有入口）。
- **建议**：v1.1 漏斗页（本就是表单式定义）顺带交付实验定义表单（名称/变体表/启停，仓储与校验 W 已就绪：≤20 实验 × ≤8 变体）；或在 docs/12 v1.1 行显式补一句，消除排期黑洞。

### G4（P3）· 访客会话无列表页（数据已在库）

- **证据**：`gr_sessions` 表落地（S5，含 last_active 索引）；无任何管理页消费它做**行级**展示（Dashboard 只有聚合；Login Protection 的「会话管理」标签是锁定会话）。docs/06 20 页树中也无访客会话页；v1.1 Contact Profile 只覆盖「有联系人身份」的访客。
- **对照**：本地统计类同行（Koko/Independent Analytics）与预览页 24 的常态是访客/会话明细列表（入口页、设备、时长、国家）。
- **建议**：作为 v1.1 Contacts 页的「访客」标签或 Traffic 页第四标签排期（表和索引都在，纯读页面）。

### G5（P3）· 有引擎无 UI 之二：在线计数与设备维度

- **证据**：`Gr_Session_Repository::count_online()` 已实现（含 30..3600s 窗口钳制），**全插件零消费方**；日聚合有 `sessions_by_device` 指标族，Dashboard 只展示国家分布，设备维度未上屏。
- **建议**：Dashboard 加「当前在线」数字块（一条 COUNT）；设备分布表随国家表同款渲染。两处都是纯读、低成本。

### G6（P2）· 列表页缺同类标配：时间范围筛选、搜索、CSV 导出

- **证据**：Audit Log 有筛选（类型/用户/动作）但无时间范围；Traffic/Campaigns/Login Protection/Bot Signals 各表为固定窗口，无日期选择、无关键字搜索；除 Status 页 JSON 诊断导出外**无任何 CSV 导出**。
- **对照**：安全/统计类同行（Wordfence、Koko、WSAL）与 wp-plug 文档 16/17 的数据网格规范，时间筛选 + 搜索 + 导出是标配；站长「把某段时间的威胁事件/战役报表拿去汇报」是高频真实需求。
- **建议**：v1.1 通用表格增强（原生 `WP_List_Table` 已支持 search box / date filtering 模式；CSV 走 nonce + capability + 免死循环）。注意 wp-plug 16/17 的侧滑抽屉/自绘网格**不采纳**（docs/06 §2.1 禁自绘），只取能力点。

### G7（P3）· 注册防垃圾深度：一次性邮箱等同行手段未评估

- **对照**：wp-plug 维度 04 常态含临时邮箱域拦截（antispam-bee 类）。greenpng 注册面目前只有蜜罐（且被 G1 困住）。
- **讨论**：一次性邮箱域拦截需要随包域名单数据文件（零外呼可满足）或外部 API（v1.2 AbuseIPDB 同型的 opt-in）；是否值得进 v1.2，请站长定夺。未列入即视为不做，也应记一笔裁定。

### G8（P3）· GeoIP 仅国家级

- **证据**：DB-IP Lite 仅国家库（IP Intelligence 页 IPv4/IPv6 段数与国家数）。同行常态含城市级（DB-IP Lite 亦有 city 库，同为 CC BY 4.0）。
- **讨论**：城市级对独立站长的价值（区域投放决策）vs 数据体积（包内文件翻倍）；如做，与 G2 的本地数据文件决策可合并评估。

---

## 5. 测试与证据覆盖的如实说明

### 5.1 已有（可复核）
- 657 单元 / 3,902 断言；phpstan L6；phpcs + 7.4 兼容；五工作流 main + tag 双绿。
- :8091 真站链路实证贯穿 docs/13 各任务（含并发折叠、HPOS 双轨、真 DNS FCrDNS、探针真浏览器端到端）。

### 5.2 覆盖缺口（诚实记录）
- **管理页 E2E 不均**：10 份 spec 偏重前台链路与 6 个管理面；Dashboard/Campaigns/Funnels/Analytics/IP Intel/Audit/Retention/Status 页无直接 E2E spec（靠单元测试 + 本次人工盘点）。
- **G1 类问题正是测试盲区的产物**：设置键的单元测试覆盖了「定义与消费」，没有测试断言「每个引擎键都有 UI 写入方」——建议加一条架构测试（defaults() 键集 ⊆ 各页可写键并集，白名单豁免只读键），一次堵死这类缝隙。
- 真实流量形态（共享主机、多访客并发、CDN 前置）在 CI 与本地站均未模拟；docs/09 分层预算已在门禁，但「共享主机实测」这一章程成功标准项尚无执行记录。

---

## 6. 讨论议题（按优先级）

| # | 议题 | 关联 |
| :--- | :--- | :--- |
| D1 | G1 是否作为 v1.0.1 小版本先行修复（纯 UI + 文案，风险极小）？ | G1 |
| D2 | IP 质量层走「随包本地数据（Tor/机房段）」还是「仅外部 API」还是「裁定不做」？ | G2/G8 |
| D3 | 按战役的无效流量/广告欺诈报表（campaign × bot 份额 × 转化）是否排 v1.1？（daily_stats 已有 bot 维度，缺上屏与按战役切片） | §3-16 |
| D4 | A/B 实验创建表单并入 v1.1 漏斗页？ | G3 |
| D5 | 访客会话列表页的归属（Contacts 访客标签 vs Traffic 第四标签）？ | G4 |
| D6 | 表格时间筛选/搜索/CSV 导出是否列 v1.1 通用增强？ | G6 |
| D7 | 一次性邮箱拦截进 v1.2 还是裁定不做？ | G7 |
| D8 | 全站用户操作审计（WSAL 式）是否永远保持在章程范围外？（当前 Audit Log = 插件自身动作；这是与维度 08 同行的**范围差异**而非缺陷，但应有明示裁定） | §2-08 |
| D9 | 架构测试「设置键必须有 UI 写入方」是否采纳（§5.2）？ | G1 防回归 |
| D10 | 管理页 E2E 补齐（8 个无 spec 页面）是否排入下个测试任务？ | §5.2 |

---

## 7. 证据清单（本文引用的主要文件）

- greenpng 实际面：`plugin/includes/admin/`（16 个类：菜单/14 页/两表格/图表资产）、`plugin/includes/core/class-gr-settings.php`（21 键默认值）、各引擎类（`class-gr-honeypot.php` / `class-gr-blackhole.php` / `class-gr-login-protection.php` / `class-gr-session-repository.php` 等）、`plugin/includes/rest/class-gr-collect-controller.php`
- greenpng 规划基线：`docs/00-project-charter.md`（范围/非目标）、`docs/06-admin-pages-and-ui.md`（20 页树与版本交付）、`docs/12-roadmap-free-v1.md`（v1.0 不做清单 / v1.1~v1.3）、`docs/13-v1-0-implementation-plan.md`（85 任务台账）、`docs/14-iss-review-verdicts.md`（裁定口径）
- 参照系：`wp-plug/README.md`（十域 226 项目）、`wp-plug/docs/README.md`（46 文档索引）、`wp-plug/preview/wp-admin-ui/`（40 页功能清单，仅取功能点）

---

## 8. 一句话总结

**引擎与数据底座的质量达到甚至超过同类插件的水准（隐私双轨、FCrDNS 双栈、并发折叠、诚实 A/B 都是同行常见缺陷的主动修复）；差距集中在「最后一公里」——4 个引擎开关没有 UI（G1）、A/B 没有创建入口（G3）、IP 质量层整层缺席且未排期（G2）、表格交互停在只读（G6）。** 其中 G1 同时暴露了一个流程问题：UI 规范与引擎任务之间的缝隙需要一条架构测试来永久封堵（D9）。
