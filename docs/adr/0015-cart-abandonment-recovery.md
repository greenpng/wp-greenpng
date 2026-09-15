# ADR-0015 · 弃购挽回（失焦捕获 + 延迟单事件调度 + 令牌核销）

> **编号**：0015　**日期**：2026-09-13　**状态**：已接受
> **背景材料**：`docs/12` v1.1 弃购挽回行、`docs/05` §2（gr_cart_abandonments 表形态）、FluentCRM AbandonCart 实读（Runner 状态机 draft→skipped/processing + 冷却 + 分钟级 AS 调度 + 建联系人）、ADR-0005/0008（同意快照纪律）。
> **触发问题**：`gr_cart_abandonments` 表（status 'captured' 默认 + recovery_token + order_id + 三个时间戳）自 S5 落地零读写方；docs/12 指定形态（失焦邮箱捕获默认关+同意勾选、15 分钟调度、令牌核销、防重复发信互斥）的实现语义需裁清。

---

## 1. 背景

1. FluentCRM 模式实读：分钟级 AS 循环任务扫表（`as_schedule_recurring_action 60s`）+ 状态机互斥（draft→processing）+ 邮箱建联系人——循环扫描模型依赖常驻调度；其 Woo 驱动的钩面未随包（bundled copy 只有自家 FluentCart 驱动）。
2. 我方队列原语：`Gr_Queue::enqueue(hook, args, delay)` 单事件 + AS/WP-Cron 自适应（ADR-0007）——**延迟单事件是原生能力**，无需为弃购新建循环日程。
3. WooCommerce 公开钩面：经典结账 `woocommerce_after_checkout_validation`/`woocommerce_checkout_order_processed`（含邮箱的 posted data / 订单）；Store API `woocommerce_store_api_checkout_update_order_from_request`（v1.0 已用于访客捕获，order 上邮箱可得）；下单未付（pending/on-hold）≠ 弃购（银行转账等在途单）。

## 2. 决策

### D1 · 捕获双路：结账失焦（经典）+ 订单未付（两栖）

- **经典结账路**：`cart_recovery_enabled`（默认 0）开启时，结账页输出我方**同意勾选框**（不勾不发）+ 邮箱字段失焦监听（小段内联行为脚本或 behavior 文件内复用）；失焦即经 REST collect 发 `cart_email` 事件（营销同意 + 勾选框双闸），服务端在同一请求快照 `WC()->cart`（存在 Woo 时）落 `gr_cart_abandonments`（status='captured'，email_hash/email_enc 双轨、cart_json/total 快照、session_id 随行）。
- **订单路（两栖）**：订单创建钩（经典 + Store API 两既有挂载）在**无支付完成**路径上捕获：邮箱取自订单（公开 API）、同意取 `_gr_marketing_consent` 快照（ADR-0008 既有）、车内容取订单行项；仅**即时支付形态的未付单**（创建后 15 分钟仍 pending 且无绑定转化）计弃购——pending/on-hold 在途单（银行转账等）**不弃购不发信**，口径在页面文案如实标注。
- 同 UND 页会话重复捕获以 (session_id) 折叠：同会话新快照 UPDATE 覆盖 cart_json/total（邮箱首获优先，邮箱已获不覆写）。

### D2 · 调度：延迟单事件，无循环扫描

- 捕获落行时 `Gr_Queue::enqueue('gr_cart_recover_check', [id], 900)`（`cart_recovery_delay` 设置键，默认 15 分钟、钳 5..120）。
- 触发时判定四闸：status 仍 'captured'（未核销未发过）∧ 无该会话/来源的绑定转化（`gr_conversions` 查询）∧ 无该邮箱的已付订单（Woo 公开 API 查询，邮箱解密后查询——队列上下文非前台，预算不适用）∧ 同意快照仍真。
- 全过 ⇒ **受守卫 UPDATE `status: captured→abandoned`（受影响行数=1 才发信）= 防重复发信互斥**；`wp_mail` 发挽回邮件（站点自己的事务邮件——站长显式启用的功能面，非遥测、非第三方端点，铁律 1 不适用面），发信失败 ⇒ 一次 6 小时延迟重试，再败置 'failed' + 状态页信号（绝不静默丢）。**实修勘正（:8091 实测揭出）**：失败时行必须先守卫回退 `abandoned→captured` 再排重试——滞留 abandoned 会让「仍 captured」闸把重试永远拒在门外、failed 不可达（行须如实回「未发信」态，重试全闸重跑后再赢一次互斥）；`failed` 的入口须同时收 `captured`（解密失败停机——pre-mutex 无可重试之事）与 `abandoned`（第二次发信拒绝）。词表与迁移纪律见 docs/05 §3.5。
- FluentCRM 的分钟级循环扫描**不采纳**：延迟单事件天然随捕获派生、无空转扫描、AS 宿主上时点精确——与 ADR-0007 队列原语同构。

### D3 · 令牌核销：单用途 64hex，恢复链接 + 订单回写

- 发信时生成 `recovery_token`（64hex 随机，`Gr_Secrets` 熵原语）随恢复链接入信；链接 `?gr_recover={token}` 命中未核销行 ⇒ 经典结账恢复车内容（cart_json 行项经 Woo 公开 API 回填）+ 行置 'recovered_attempted'（防重复入车）。
- **核销终态由订单回写**：该邮箱/会话的订单进入绑定转化（v1.0 绑定路径自动发生）时，适配器/队列将行置 `status='recovered', order_id=绑定 id, recovered_at=NOW()`——「挽回成功」以真实订单为准，不以求链接点击为准（诚实口径，页面标注）。
- 令牌随行生死：90 天保留期既有，行删令牌亡；令牌仅用于恢复入车，不含任何身份断言（无自动登录、无免结账）。

### D4 · 模板与设置

- `gr_settings` 增三键（Attribution 标签 + G1 不变式测试扩展）：`cart_recovery_enabled`（0）、`cart_recovery_delay`（15，钳 5..120 分钟）、`cart_recovery_subject`（短文本，默认模板串）。
- 邮件正文模板存独立非 autoload option `gr_cart_recovery_template`（≤2000 字符占位模板：{site}、{items}、{total}、{recover_url}、{unsubscribe}——退链说明文案必含）；渲染走 `wp_kses_post` 纪律收白名单。
- 退订：挽回信必带说明——弃购挽回属站长自启营销邮件，本地记录退订（option 内邮箱 hash 列表，`sys:unsubscribed` 标签同义）后永不再发。**不重发**是纪律而非设置。

### D5 · 隐私与披露

- email 全程 hash+加密双轨（ADR-0013 同款）；cart_json 仅商品行项（无地址无支付细节）；同意勾选显式（C5「默示同意」驳回纪律沿用）。
- readme：弃购功能披露段（默认关、勾选框、站点自发电邮、无第三方端点）+ POT 全量新串。

## 3. 后果

- 正面：docs/12 指定形态全部落语义；互斥三防线（status 守卫 UPDATE + 绑定转化回写 + 退订 hash）；无循环调度新增；两栖钩面复用 v1.0 既有挂载。
- 负面/风险：Store API Blocks 结账无法输出我方勾选框/失焦脚本（Blocks 字段注册超出 v1.1 面）——Blocks 站走订单路（邮箱+同意快照在订单上），页面文案如实标注覆盖差异；`wp_mail` 送达质量依赖站点邮件配置（失败有状态页信号，不假装送达）。
- 合规：勾选框 + 营销同意 + 退订记录 + 加密落库；默认关；无出网。
- 修订关联：`docs/05`（表状态机词表：captured/abandoned/recovered_attempted/recovered/failed）、`docs/06`（弃购挽回管理面——并入 Campaigns 或独立小节随 docs/17 定）、`docs/12`、readme/POT 随实现同步。
