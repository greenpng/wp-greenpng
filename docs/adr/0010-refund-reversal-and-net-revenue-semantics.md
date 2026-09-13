# ADR-0010 · 退款/取消冲销与日聚合净额口径（v1.1）

> **编号**：0010　**日期**：2026-09-13　**状态**：已接受
> **背景材料**：`docs/15-iss09-review-verdicts.md` §4（C4 后半三项开放问题）、`docs/16-iss10-review-verdicts.md` §5、ADR-0009 D4（冲销延期 v1.1 的既定理由）。
> **触发问题**：v1.0 只绑定不冲销——退款/取消后 `gr_conversions` 金额留存、`gr_daily_stats` 收入口径不变，ROI 虚高。三项开放问题（UNIQUE 键下冲销表示法、跨窗退款口径、金额语义）自 ISS-09 起悬置，本 ADR 一并裁定。

---

## 1. 背景

1. `gr_conversions` 受 `UNIQUE KEY source_unique (source_type, source_id)` 约束：同一订单/提交恒一行，冲销**不可能**以第二行表示（ISS-09 论断，ADR-0009 D4 复核维持）。
2. 日聚合 `Gr_Daily_Aggregator` 重算窗 = 今日回看 7 天，且**窗口外日期永不重访**；该规则的存在理由是**不读已瘦身数据**（`docs/05` §3.4 引注：窗口必须小于最小保留期 30 天）。但 `gr_conversions` 保留期为**永久**（聚合后仍留明细，`docs/05` §2 表清单）——conversions/revenue 两类指标的源表任何日期都可安全重读。
3. WooCommerce 侧可用公开钩：`woocommerce_order_status_refunded` / `woocommerce_order_status_cancelled`（全额冲销）与 `woocommerce_order_partially_refunded`（部分退款，逐次触发）。

## 2. 决策

### D1 · 冲销表示法：状态列软标，金额永不改动

- `gr_conversions` 增列 `status VARCHAR(16) NOT NULL DEFAULT 'active'` 与 `reversed_at DATETIME NULL`（DB_VERSION 1→2，`maybe_upgrade()` dbDelta，`docs/05` §3.3 同步登记）。
- 冲销 = `UPDATE` 该行 `status='reversed', reversed_at=NOW()`；**金额保持原值**（总额真相留存，毛/净两口径皆可复算）。不改负、不置零、不删除、不开第二行——四种替代案全部落选：改负破坏 `SUM()` 语义与列宽、置零丢失总额真相、删除丢失归因历史、第二行被 UNIQUE 键结构性排除。
- 幂等：`UPDATE ... WHERE source_type=%s AND source_id=%d AND status='active'`——重放钩与已冲销再冲销皆零效（受影响行数即互斥信号）。

### D2 · 触发语义：全额退款/取消即冲销，部分退款按剩余额收敛

- `refunded` / `cancelled` 状态钩 → `reverse_payment($order_id)`：查行 → status 置 'reversed' + reversed_at；适配器以 `\Throwable` 隔离（`gr_adapter_error` 上报，铁律 6 同款）。
- 部分退款钩 → 目标金额 = 订单剩余总额（公开 CRUD 口径 `get_total() − get_total_refunded()`——专用的剩余额 getter 仅存在于较新 Woo 构建，:8091 WC 11.1 实测 fatal，故用长期稳定的计算式）：`UPDATE amount = 剩余额`。**以订单态为唯一真源、每笔收敛到同一终值 ⇒ 天然幂等**（重放钩不叠加扣减）。剩余额为 0 时同时置 'reversed'（与全额路径合流）。
- 在途订单（pending/on-hold，如银行转账）**不属冲销面**：只有到达 refunded/cancelled/partially-refunded 的订单才触发。

### D3 · 聚合口径：revenue 转净额、conversions 计全部、冲销触发定向重算

- **`revenue` 指标改净额口径**：`SUM(amount) WHERE status='active'`（v1.0 全行皆 active，改口径零回溯影响；改后毛额可由 `conversions` 明细随时复算）。
- **`conversions` 指标维持计全部绑定行**：转化事件确实发生过，转化率不应因退款波动；金额层面的纠正由 revenue 承担。
- **冲销任何时刻发生 ⇒ 定向重算该转化 `created_at` 日期的 conversions/revenue 指标**（经 `Gr_Queue::enqueue` 异步，键即日期，聚合器既有 upsert 替换语义天然幂等）。7 天窗口规则**不变**（其存在理由是不读已瘦身数据，而本重算只读永久保留的 `gr_conversions`——规则与其理由两不相伤）；跨窗退款由此闭合，"报表稳定性"的准确表述随之修正为：**未经业务事件触碰的历史日期不变**，被冲销触碰的日期如实变动（退款本身就是业务事实）。

### D4 · 消费面与审计

- Campaigns / Analytics 页收入读 `gr_daily_stats`，净额口径自动生效，无需改页。
- 转化明细列表（Traffic/联系人档案钻取）增 status 徽章（active/reversed）。
- 冲销属适配器写操作：`gr_adapter_error` 上报之外，以 `gr_audit_logs` 记录冲销动作（object_type='conversion', object_id=行 id，diff 含 status/amount 变更）——与既有审计纪律一致。

## 3. 后果

- 正面：退款不再虚增 ROI；跨窗冲销闭合；毛/净两口径可复算；幂等三防线（UNIQUE 键、status 守卫、订单剩余额收敛）。
- 负面/风险：`revenue` 语义变净额与 v1.0 字面口径不同（已如 D3 说明，无回溯影响）；历史日期在冲销后变动，依赖日期绝对稳定的第三方导出需自行注意（如实披露，不隐藏）。
- 合规：无新增 PII；DB_VERSION 步进一版走 dbDelta（铁律 3 的升级例程白名单内）。
- 修订关联：`docs/05` §3.3（列与口径）、§3.4（revenue 净额语义）、`docs/12`（归因冲销行）、`docs/13`（历史行注明 v1.1 覆盖）随实现同步。
