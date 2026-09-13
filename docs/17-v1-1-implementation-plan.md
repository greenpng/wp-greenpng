# 17. v1.1 实施清单 (Implementation Plan)

> **真源地位**：本文件是 v1.1 任务与进度的唯一真源（与 `docs/13` 之于 v1.0 同构）。状态列以本文件为准。
> **范围真源**：`docs/12` v1.1 表（运营版：行为 + CRM + 漏斗 + 挽回 + 面板 + 冲销 + Webhook）+ 本轮七份 ADR 的逐项裁定。
> **完成定义**：每阶段四检查（find-lint / phpcs / compat 7.4 / phpunit）+ PHPStan L6 + e2e 扩展 + :8091 真栈实测回填本表；里程碑整体通过 `08` 合规 + `09` 预算 + `11` 门禁。
> **创建**：2026-09-13（v1.1 启动轮：wp-plug 同类交叉分析四域 + 本仓库全部悬置项收口）。

---

## 1. 目标

装上 v1.1 后，站长获得：**看得见行为**（停留/滚动/怒点/死点，访客同意下采集）、**管得起联系人**（表单线索留存 + 窗口化评分 + RFM 分层）、**定义得了漏斗**（表单式定义 + CSS 阶梯流失图 + 转化目标）、**挽回得了弃购**（同意勾选 + 15 分钟延迟信 + 令牌核销）、**算得准钱**（退款冲销 + 净额口径）、**接得出系统**（HMAC 签名通用 Webhook）+ 面板层（访客会话列表 / 在线与设备 / 表格筛选搜索导出 / 战役无效流量）。数据仍全在本地，出网仅站长显式配置面。

## 2. 裁定与范围外（收口记录）

| 事项 | 裁定 | 出处 |
| :--- | :--- | :--- |
| G2 IP 质量 | 机房段离线层采纳（随包 DCH + 三云段表按钮）；**Tor 出口拒绝**（小时级轮换两模型皆失效；重启条件已记录）；G8 城市 GeoIP 维持 v1.3 再评 | ADR-0011 |
| 退款冲销三问 | 状态列软标（金额永真）/ 部分退款按剩余额收敛 / revenue 净额 + 冲销定向重算（7 天窗规则不破） | ADR-0010 |
| C5 线索留存 | 表单桥同意门内落 gr_contacts（email 双轨 + visitor_id 联结）；电话不留存（边界如实） | ADR-0013 D1/D2 |
| G4 会话列表归属 | Traffic 页新增标签（docs/12 已定，维持）；IAWP Journeys 形态参照（无 IP/UA 展示） | docs/12、ADR 无涉 |
| G7 一次性邮箱 | v1.2 评估项（docs/16 既定，本轮不重开） | docs/16 §3 |
| D8 全站审计范围 | **明示范围外**：本插件审计面 = 自身管理操作（gr_audit_logs 既有语义）；全站内容/用户活动审计属另一产品域（WSAL 类），列入章程非目标邻接面，不因 v1.1 扩面 | 本轮裁定（ISS-08 D8 收口） |
| D5 会话列表页归属 | 已由 docs/12 v1.1「运营面板」行定置（Traffic 标签），收口 | docs/12 |
| 战役无效流量报表 | 形状落定：Campaigns 页新标签，战役 × bot/housing 占比 × 转化（90 天窗 gr_sessions 聚合，V30） | docs/16 §3 开放项收口 |
| `bot_verdict_threshold`=70 校准 | 维持开放项：首发后按真实分布回调（无真实站点数据前不预调） | docs/15 §5 既定 |
| 检测路标记覆盖边界 | 行为模块不改变登录/注册/陷阱无会话行边界（wp_enqueue_scripts 不在该生命周期），ADR-0012 D4 收口 | ADR-0012 D4 |

## 3. 任务台账

### Phase 1 · Schema v2 与退款冲销（ADR-0010）

| # | 短名 | 状态 | 交付物 | 实测 | 规范 |
| :--- | :--- | :---: | :--- | :--- | :--- |
| V1 | Schema v2 迁移 | ✅ | DB_VERSION 1→2：gr_conversions +status/reversed_at；gr_contacts +visitor_id+KEY；gr_sessions +ip_quality；maybe_upgrade 单批 dbDelta；docs/05 §2/§3.2/§3.3 登记；Schema 单测列断言更新 | :8091 `wp eval-file`——`db_version=2`、DESCRIBE 三表新列全在；phpunit SchemaTest +1 例（v2 列登记）全绿 | ADR-0010/0011/0013，docs/05 §4 |
| V2 | 冲销仓储 | ✅ | Gr_Conversion_Repository::reversal_state_for_source()（先读后判，no-op 不入队）+ reverse_for_source()（守卫 UPDATE `status='active'` 幂等）+ converge_amount_for_source()（剩余额收敛、0 值同语句翻 reversed）；recent()/rows_for_visitor() SELECT 增 status/reversed_at | RefundReversalTest 6 例——守卫形状/重放 no-op/收敛 '42.50'/零额翻 reversed/状态读/未绑定 null | ADR-0010 D1/D2 |
| V3 | Woo 冲销钩 | ✅ | refunded/cancelled/partially_refunded 三钩 + reverse_payment()/partial_refund() + Throwable 隔离；**坑位实录：`WC_Order::get_remaining_refund_total()` 在 :8091 WC 11.1 不存在（真栈 fatal 实证）——改用 `get_total() − get_total_refunded()` 长稳公开对**（ADR 已同步勘正）；Woo 桩增 get_total_refunded() | WooCommerceAdapterTest +3 例——refunded 软标+审计+入队、cancelled 未绑定零写、partial 收敛 '60.00'；挂载断言 5→8 | ADR-0010 D2，铁律 6 |
| V4 | 净额聚合 | ✅ | aggregator revenue 口径 `SUM(CASE WHEN status='active' THEN amount ELSE 0 END)`；`recompute_conversions_for_date()` 定向重算（**只触 conversions/revenue 两指标**——旧日期其余指标族读的是已瘦身表，绝不重算）；`gr_recompute_conversion_date` 队列钩注册 | RefundReversalTest +3 例——净额 CASE 形状与 upsert 值、定向重算零触碰 sessions/events/security_logs、坏日期 0 行拒绝；:8091 实弹见 V5 | ADR-0010 D3 |
| V5 | 冲销消费面 | ✅ | Campaigns 模型比对跳过 reversed 行（净额语义）；gr_audit_logs 冲销审计（conversion_reversed / conversion_partial_refund）；转化明细 status 徽章随 V18 档案时间线交付 | :8091 全链实弹（WC 11.1 真栈）：绑定后 revenue 239.98 → **refunded 后 119.98（reversed 120 离场）→ conversions 计数 4 不动** → partial 40 退款后 79.98、行 amount '100.00'→'60.00' status 保持 active、订单剩余额 60 一致；audit_reversal_rows=1；queued_as=1（AS 宿主后端可见）；清理后 conversions/audit=0、revenue 回落基线 19.98、debug.log 探针残留 20 行剔除（备份 debug.log.bak-p1）；四检查 + PHPStan L6 全绿（679→696 tests / 3,978→4,071 assertions） | ADR-0010 D4 |

### Phase 2 · 运营面板与表格增强（G4/G5/G6）

| # | 短名 | 状态 | 交付物 | 实测 | 规范 |
| :--- | :--- | :---: | :--- | :--- | :--- |
| V6 | 访客会话列表 | ⬜ | Traffic 页新标签（WP_List_Table：time-ago/落地/渠道/设备/pageviews/bot 徽/分页；无 IP 无 UA 展示）；单测 + e2e | — | docs/12 G4，docs/06 |
| V7 | Dashboard 面板 | ⬜ | 在线人数 + 设备分布上屏（count_online()/设备聚合既有读侧）+ REST 面板端点（60s transient，无 admin-ajax）；单测 + e2e | — | docs/12 G5，AGENTS（REST-only） |
| V8 | 表格增强 | ⬜ | 日期范围筛选 + 搜索 + CSV 导出通用 pattern（Audit Log / Access Rules / 访客会话三面统一；CSV 走下载端点 nonce 门）；单测 + e2e | — | docs/12 G6，docs/11 §3 |

### Phase 3 · 行为洞察（ADR-0012）

| # | 短名 | 状态 | 交付物 | 实测 | 规范 |
| :--- | :--- | :---: | :--- | :--- | :--- |
| V9 | 行为探针文件 | ⬜ | assets/js/gr-probe-behavior.js（dwell 分桶/scroll 里程碑/rage 1s·20px·3 击/dead 600ms MutationObserver；缓冲 + pagehide 单信标；每页 ≤20）；独立体积预算测试；入队双闸（behavior_enabled 默认 0 + consent 回落）；script_data 扩展 | — | ADR-0012 D1/D3 |
| V10 | 采集端扩展 | ⬜ | collect 四事件 schema + 分事件字段白名单 + 服务端营销同意闸 + Sec-Purpose prefetch 拒收；gr_collect_events 过滤器路线（核心词表不动）；单测全分支 | — | ADR-0012 D2 |
| V11 | 行为报表 | ⬜ | aggregator 词表 + behavior_events 指标；Behavior Insights 页两标签（KPI 汇总瓦 + locator/path 明细钻取）；单测 + e2e | — | ADR-0012 D4 |
| V12 | 披露与实测 | ⬜ | readme 探针段实述化 + POT；:8091 agent-browser 真浏览器实测（真 dwell/scroll/怒点事件落库 + 无同意零发 + prefetch 拒收） | — | ADR-0012 D5，08 清单 |

### Phase 4 · CRM（ADR-0013）

| # | 短名 | 状态 | 交付物 | 实测 | 规范 |
| :--- | :--- | :---: | :--- | :--- | :--- |
| V13 | 联系人仓储 | ⬜ | Gr_Contact_Repository（upsert by email_hash、visitor_id cookie 轨最新胜、first/last_seen、标签附加）+ 单测 | — | ADR-0013 D1/D2 |
| V14 | 线索留存接线 | ⬜ | Gr_Form_Adapter_Base::process() 扩展（email 双轨 + names + sys:form:{bridge} 标签）；三表单桥单测；:8091 FF 真钩实测（同意门内落库、无同意零写） | — | ADR-0013 D2，docs/15 C5 |
| V15 | 评分引擎与规则页 | ⬜ | gr_scoring_rules 仓储（≤30 条边界）+ 窗口重算引擎（daily_cap 钳制、0..100 封顶、队列趟次 + 档案页单算 + 规则保存批量重算）+ Scoring Rules 页 CRUD；单测 | — | ADR-0013 D3 |
| V16 | bot 结论消费 | ⬜ | is_bot → score 0 + sys:suspected_bot attach-only（is_system 语义 + 文案边界）；单测 | — | ADR-0013 D3 |
| V17 | RFM 引擎 | ⬜ | PHP 五等分（5.7 无 NTILE）+ 八段词表 + M 净额口径；队列趟次与评分同跑；单测（分桶边界、小样本退化） | — | ADR-0013 D4 |
| V18 | CRM 三页与隐私 | ⬜ | Contacts 页（掩码邮箱/筛选）+ Contact Profile 页（时间线 + 揭示审计 + 现算 r/f/m）+ Privacy API 三表导出/擦除用例；e2e + :8091 实测 | — | ADR-0013 D5，docs/06 |

### Phase 5 · 漏斗与 A/B 创建（ADR-0014）

| # | 短名 | 状态 | 交付物 | 实测 | 规范 |
| :--- | :--- | :---: | :--- | :--- | :--- |
| V19 | 漏斗定义 | ⬜ | Gr_Funnel_Repository + flow_json 校验（≤10 步、match 词表、路径不含查询串口径标注）+ Funnels 页「漏斗」标签表单 CRUD；单测 | — | ADR-0014 D1 |
| V20 | 推进引擎 | ⬜ | Gr_Funnel_Tracker（gr_event 总线挂载、前进式 upsert、max_step 粘滞、completed_at 末步）；单测 + :8091 实测（两步漏斗真推进） | — | ADR-0014 D2 |
| V21 | 阶梯流失图 | ⬜ | 「步骤流失」标签：纯 CSS Flexbox 阶梯 + dashicons 徽章 + screen-reader 等价表（max_step≥N 口径 + 步间流失率）；单测 + e2e | — | ADR-0014 D3，docs/06 §2.3 |
| V22 | 目标与 G3 表单 | ⬜ | 「目标」标签（conversions 计数/净额读侧）+ A/B 创建表单（仓储边界逐条对齐）+ 显著性现算展示；单测 + e2e | — | ADR-0014 D4/D5 |

### Phase 6 · 弃购挽回（ADR-0015）

| # | 短名 | 状态 | 交付物 | 实测 | 规范 |
| :--- | :--- | :---: | :--- | :--- | :--- |
| V23 | 经典结账捕获 | ⬜ | cart_recovery_enabled 闸 + 我方同意勾选框 + 邮箱失焦捕获（REST cart_email + WC cart 快照）；单测 | — | ADR-0015 D1 |
| V24 | 订单路捕获 | ⬜ | 两栖挂载（经典/Store API 既有钩面）+ 同意快照 + 在途单排除口径；单测 | — | ADR-0015 D1 |
| V25 | 调度与发信 | ⬜ | 延迟单事件（cart_recovery_delay 钳 5..120）+ 四闸判定 + 守卫 UPDATE 互斥 + wp_mail + 一次重试/failed 状态页信号；单测（双发坍缩、在途单不发） | — | ADR-0015 D2 |
| V26 | 令牌核销与设置 | ⬜ | 64hex 令牌 + 恢复入车 + 订单回写 recovered + 退订 hash 永不再发 + 模板 option（占位渲染 wp_kses_post）+ 设置三键 + Attribution 控件 + G1 不变式测试扩展；e2e + :8091 真结账实测 | — | ADR-0015 D3/D4 |

### Phase 7 · IP 质量与战役无效流量（ADR-0011）

| # | 短名 | 状态 | 交付物 | 实测 | 规范 |
| :--- | :--- | :---: | :--- | :--- | :--- |
| V27 | 数据文件 | ⬜ | datacenter CIDR 文本（IP2Proxy LITE DCH 过滤 + 生成脚本 + 文件头归属/数据日期）+ packed packer（Geoip_Packer 同款）；readme 归属与注册告知 | — | ADR-0011 D1 |
| V28 | 匹配与落库 | ⬜ | Gr_Ip_Quality 匹配器（内存 CIDR）+ 归因监听 touch() 增列首触写入（同条 upsert、SQL 条数不变、touch 形状测试同步更新）；单测 | — | ADR-0011 D4 |
| V29 | 更新按钮 | ⬜ | IP Intelligence 页「机房段」节：数据日期显示 + AWS/Azure/Google 官方段表按钮拉取（显式点击、最近拉取时间、readme 端点披露）；单测 | — | ADR-0011 D2，铁律 1 |
| V30 | 消费面 | ⬜ | 会话列表 ip_quality 列 + Campaigns 页「无效流量」标签（战役 × bot/housing 占比 × 转化，90 天窗聚合 + 口径标注）；e2e | — | ADR-0011 D4/D6，docs/16 §3 收口 |

### Phase 8 · 通用 Webhook（ADR-0016）

| # | 短名 | 状态 | 交付物 | 实测 | 规范 |
| :--- | :--- | :---: | :--- | :--- | :--- |
| V31 | 端点存储与页 | ⬜ | gr_webhooks 仓储（≤10、https 强制、secret 掩码）+ Webhooks 页 CRUD；单测 | — | ADR-0016 D1 |
| V32 | 分发与投递 | ⬜ | 总线挂载 + 队列单事件 + HMAC 四头 + PII 零出网（hash-only）+ 2 次退避重试 + 熔断 + 端点最近态 + 状态页行 + 验签文档段；单测 + :8091 本地接收端验签往返实测 | — | ADR-0016 D2/D3/D4 |

### Phase 9 · 收口与发布

| # | 短名 | 状态 | 交付物 | 实测 | 规范 |
| :--- | :--- | :---: | :--- | :--- | :--- |
| V33 | e2e 页覆盖 | ⬜ | D10 收口：新页（Contacts/Profile/Scoring/Behavior/Webhooks）+ 既有未覆盖页 spec 化 + ci-seed 扩展；e2e 全绿 | — | docs/11，ISS-08 D10 |
| V34 | 文档与版本 | ⬜ | docs/05/06/12/README 同步 + iss 无涉核验 + POT 全量 + `./tools/bump-version.sh 1.1.0` + readme changelog | — | 08 清单 |
| V35 | 发布 | ⬜ | 四检查 + PHPStan L6 全绿；docs/17 实测列回填；push/tag 按站主授权执行 | — | ADR-0008（CI） |

## 4. 预算与门禁锚点（实现时逐项核）

- 前台每请求 SQL 预算不变（docs/09 §1.1）：touch() 增列**同条语句**；漏斗 tracker 在事件总线内且 active 漏斗站长可控；行为采集走 REST collect 独立口径。
- Option 纪律（docs/05 §6）：gr_settings 增 3 键（behavior_enabled/cart_recovery_enabled/cart_recovery_delay），≤8KB 不变；gr_scoring_rules/gr_webhooks/gr_cart_recovery_template 皆非 autoload；gr_settings 唯一 autoload=yes 地位不变。
- G1 不变式测试随每批新键扩展（defaults ⊆ 页面可写键并集）。
- 15 物理对象封顶：本轮零新表，仅三列增补（DB_VERSION 2 单批）。

## 5. 风险登记

| 风险 | 缓解 |
| :--- | :--- |
| Blocks 结账无法承载勾选框/失焦脚本 | 订单路兜底（邮箱+同意快照在订单上）；覆盖差异页面如实标注（ADR-0015 已裁） |
| IP2Proxy LITE 段表随包渐陈旧 | 数据日期如实显示 + 三云官方段表按钮免注册刷新（ADR-0011 D2） |
| 评分窗=事件保留窗（30 天）口径困惑 | 页面标注 + conversions/ltv 维度补偿长周期（ADR-0013 D3 注记） |
| MutationObserver 逐点击观察器开销 | passive 监听 + 600ms 即抛 + 每页事件上限 20 |
| wp_mail 送达质量参差 | 一次重试 + failed 状态页信号（不假装送达） |
| 行为事件量增长 | 三重封顶（默认关 + 同意门 + 60/60s 限流）+ 30 天保留双轨瘦身既有 |
