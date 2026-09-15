# ADR-0016 · 通用出网 Webhook（HMAC-SHA256 签名 + 队列异步 + 断路退避）

> **编号**：0016　**日期**：2026-09-13　**状态**：已接受
> **背景材料**：`docs/12` v1.1 出网行（HMAC-SHA256 签名 + 验签文档）、铁律 1/3（零静默外呼、前台零同步外呼）、`Gr_Http_Client`/`Gr_Queue`/`Gr_Secrets` 既有原语、`docs/05` §6（option 纪律）。
> **触发问题**：v1.1 需要通用出网通知面（转化/线索/行为事件 → 站长自配端点，如自有系统、自动化平台），无同类本地参考（wp-plug peers 的 webhook 皆为云端 SaaS 附属）——签名为王：无签名的出网面在公网端点上是公开数据投递。

---

## 1. 背景

1. 铁律 3：前台请求生命周期内禁止同步 `wp_remote_*`——一切出网走 `Gr_Queue`；铁律 1：出网目标必须站长显式配置并启用——webhook 端点表满足「显式配置」面。
2. 事件总线 `gr_event` 动作已带完整 DTO（事件名/组/访客/会话/载荷）——出网分发挂总线即得统一事件面，无需新采集。
3. `Gr_Secrets::sign_hmac`（sha256 64hex 确定性）与 `Gr_Http_Client`（超时+熔断+退避）v1.0 已交付实测。

## 2. 决策

### D1 · 端点存储：非 autoload option，有界列表

- option `gr_webhooks`（autoload=no，`docs/05` §6 纪律）：≤10 端点，每条 {url（**仅 https**，http 拒存）、secret（64hex 或站主自定 ≥16 字符）、events（闭词表子集：conversion/lead/behavior 四事件/ab——与 ADR-0014 步骤词表同源）、active}。
- Webhooks 页 CRUD（WP 原生表单 + List Table）；secret 写入即掩码显示（`Gr_Secrets::mask`），不再回显明文。
- **无新表**（docs/05 十五物理对象封顶）——投递历史不入库（见 D4）。

### D2 · 签名与请求形状（GitHub 同型，验签文档随页）

- 头：`X-Gr-Signature: sha256=<HMAC-SHA256(secret, 原始 body)>`、`X-Gr-Event: <事件名>`、`X-Gr-Delivery: <32hex>`、`X-Gr-Timestamp: <unix>`。body 为事件 DTO 的 JSON（访客/会话为既有哈希形态，**PII 零出网**——邮箱只出 hash，与 CAPI 纪律同源）。
- 验签文档（页面内文档段 + readme）：`hash_equals(hexdec HMAC, 头值)` + 时间戳 ±300s 防重放——两步验签为接收方义务，我方文档给出等价代码片段说明。

### D3 · 分发：总线挂载 + 队列单事件 + 有限重试

- `gr_event` 动作上按端点 events 子集匹配 ⇒ `Gr_Queue::enqueue('gr_webhook_deliver', [endpoint_id, event snapshot], 0)`——**出网永不在请求内**（铁律 3），投递体在入队时快照（事件行已落，DTO 可再生）。
- 投递经 `Gr_Http_Client`（timeout 5s、熔断参与）；失败重试 2 次（指数退避 60s/300s 经队列 delay），再败记失败态（D4）——**绝不静默丢**（钩漂移纪律同源：状态页可见）。

### D4 · 投递健康：端点级最近态 + 状态页

- 每端点在 option 内记最近投递态（last_delivery_at、last_status、consecutive_failures、circuit_open_since）——**不入库、不建表**；状态页出 Webhook 行（正常/重试中/熔断，`Gr_Diagnostics` 既有面板形态）。
- 熔断（连续 N 败）后端点自动停投 + 状态页告警，站主显式复位——`Gr_Http_Client` 熔断语义复用。

### D5 · 披露

- readme External services 段：通用 Webhook 条目（站长自配端点、仅显式启用后出网、签名头形状、无任何 greenpng 自有端点）。

## 3. 后果

- 正面：v1.1 出网行落定；事件面统一（总线一分多端点）；无表扩张；断路退避复用既有客户端。
- 负面/风险：投递历史无行级审计（端点级最近态替代——有界 option 纪律优先于可观测性细节，v1.2 可再议）；端点 ≤10 上限如实标注（站长多端点需求真实出现时再评）。
- 合规：PII 零出网（hash-only）；https 强制；默认全关；出网仅显式配置端点。
- 修订关联：`docs/06`（Webhooks 页规格）、`docs/12`（出网行）、`docs/05` §6（option 登记）、readme/POT 随实现同步。

## 4. 实现注释（2026-09-15，V31/V32 落地）

- **两梯级并立**：队列退避（60s/300s）与 `Gr_Http_Client` per-service backoff/熔断并行。线上未达成的拒收（`ERR_BACKOFF_WAIT`/`ERR_BREAKER_OPEN`——队列在 client 等待窗内开火）不烧队列梯级：按 client 自报 `retry_after` 重排且 attempt 不进（单测 WebhookDeliveryTest「提前触发」例钉死；:8091 真栈以 30s deferral 复现，attempt 保持）。
- **签名体即线上体**：`post_raw` 接预编码 body 直发、永不重编（`json_encode` 单点持有 WPCS ignore——签名字节必须等于线上字节）；真栈三次投递 body md5 一致。
- **per-endpoint 服务键** `webhook_<id>`：5s 超时与熔断互不连坐（一个死接收端不冷却另一个）；端点本身（owner 配置+active+非开路）即配置门，`configured()` 对该前缀永真。
- **'lead' 事件入词表**（V31 补充）：表单适配器捕获成功后总线派发 crm 组 'lead'（contact_id + 来源，email 绝不入 payload）——此前联系人捕获无总线事件，词表随此闭合为 7 项；pageview/signal/cart_email 有意留外。
- **存储门为结构门，非 DNS 门**（2026-09-15，e2e 轮暴露后修复）：`valid_url` 初版借用核心 `wp_http_validate_url()`——它是线上 SSRF 校验器，**对 host 做 DNS 解析并拒私网段**，于是内网/暂不可解析的接收端一概拒存；e2e 的 `hooks.e2e.test`（保留 TLD，任何解析器都 NXDOMAIN）以完美 https POST 被同词 `http_url` 拒存才暴露（unit 桩只做形状检查，掩盖了分叉）。修为纯结构门：scheme https + host 非空 + 长度 ≤2048；解析性与 SSRF 校验留在投递时（`post_raw` → `wp_safe_remote_post` 自带该校验，失败走既有重试/熔断梯级，绝不静默）。wp-plug 同类先例同结论（pixelyoursite 函数注释原文："must NOT be used for this"——它拒本地 host）。
- **真栈验证注记**：:8091 无 TLS → 接收端验签往返经 http（scheme 存储门由 `add()` 拒存 http 真栈实证）；接收端为临时 mu-plugin（验签 HMAC/±300s/记录载荷），验毕撤除、路由 404 复核。
