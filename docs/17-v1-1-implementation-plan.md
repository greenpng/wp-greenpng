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
| V6 | 访客会话列表 | ✅ | Traffic 页第四标签（`Gr_Sessions_Table`：time-ago 起始/最近活跃（title 携精确戳）、渠道+战役、落地、设备、pageviews、bot 结论；**无 IP 无 UA 列**——SELECT 列清单即证明）；`Gr_Session_Repository::paged()` 白名单过滤读（from/to Y-m-d 校验、四列 LIKE 搜索经 esc_like、ORDER last_active DESC, session_id DESC 确定性断尾、LIMIT 钳 1..5000）；分页服务端；e2e 留 V33 D10 一并 | ListTablesTest +2（渲染/空态/bot 词表/标题含全 id）、SessionRepositoryTest +6（无过滤 count 不 prepare、日期界、坏日期弃、四路 LIKE 同值 ×4、LIKE 转义、钳位、今日聚合）、TrafficPageTest 3→4 标签 + sessions 标签渲染断言；:8091 真栈：seed 3 行 → 搜索精确 3、`sess-p2-newest` 独立 last_active 排首实证、坏过滤 0 行、清理后 0 | docs/12 G4，docs/06 |
| V7 | Dashboard 面板 | ✅ | 在线人数 + 今日会话/疑似 bot + 设备分布上屏（`count_online()` + `today_device_split()` 单聚合查询）；`Gr_Panels_Controller` GET /panels（manage_options 门）+ 页面服务端先渲染完整值（无 JS 亦完整）+ `gr-dashboard.js` 独立闭包 30s 轮询刷新（标签全服务端本地化，与图表互不连坐）；**勘正：起草时的"60s transient"系 explorer 提议带入，与 docs/05 §3.2 实测裁定（在线数不写共享 transient）冲突——按 docs/05 执行：每调用直算两条索引化读（last_active 范围 + started_at 今日界）；弃 admin-ajax | PanelsControllerTest 6 例（路由/双门/载荷/有界读且零 transient 写/读失败降级 0/script_data 键序与设备词表）；DashboardPageTest +1 例（四挂载点 + 服务端初值 + Desktop/Mobile 标签映射）+ inline 合并断言（panelsEndpoint/panelPollMs 无键冲突）；:8091 真栈：panels 载荷与当日 split 完全一致（online 3 / sessionsToday 13 / botsToday 2 / devices 2）、rest 服务器路由注册 yes、四挂载点齐 | docs/12 G5，docs/05 §3.2，AGENTS（REST-only） |
| V8 | 表格增强 | ✅ | `Gr_List_Filters` 共享词汇（from/to/s 解析-控件-导出 URL 三用，逐键 isset 读取，值自行 rawurlencode——核心 add_query_arg 拼值不编码）；Audit Log 页并入日期+搜索（既有 user/object/action 过滤保留）+ CSV 链接；Access Rules 页 CSV（随 tab）；sessions 标签同套；`Gr_Export_Controller` GET /export/{sessions\|audit\|access_rules} 封闭数据集词表 + `_wpnonce` 查询参数过 cookie 认证（纯链接可点）+ text/csv、nosniff、attachment 头 + 行帽 5000（audit LIMIT_CEILING 200→5000 同步）；fputcsv 显式四参（PHP 8.1+ 隐式默认弃用告警消除，7.4 输出等价）；e2e 留 V33 | ListFiltersTest 5 例（成型日期保留/坏日期弃+搜索 64 钳/控件现值/URL 镜像+nonce+空滤不入/audit 额外键）、ExportControllerTest 6 例（词表/门/sessions CSV 头行与引号规则/audit 过滤全入 WHERE/access_rules 双列表+离词表回退 ban/下载头）、AuditLogTest +2（created_at 范围 + action/object_type/object_id 三列搜索且 diff_json 不入词表）+ 页面共享控件与 CSV 镜像断言、AccessRulesPageTest +1；:8091 真栈：三数据集 CSV 头/行实测（时间戳字段引号包裹、nosniff/attachment 头）、audit 'conversion' 搜索命中、清理后探针残留 debug.log 9 行剔除（备份 debug.log.bak-p2）、fputcsv 弃用清零 | docs/12 G6，docs/11 §3 |

### Phase 3 · 行为洞察（ADR-0012）

| # | 短名 | 状态 | 交付物 | 实测 | 规范 |
| :--- | :--- | :---: | :--- | :--- | :--- |
| V9 | 行为探针文件 | ✅ | assets/js/gr-probe-behavior.js（dwell 分桶 0-15/15-60/60-180/180+、scroll 25/50/75/100 里程碑 rAF 节流、rage 1s·20px·3 击 + 3s 冷却、dead 600ms MutationObserver 非交互目标；缓冲 + pagehide/visibilitychange(hidden) 单 sendBeacon（JSON Blob）；每页 ≤20）；独立体积预算测试（raw 8089B / gz 3149B，双 ≤8192 契约）；入队三闸（probe_enabled + behavior_enabled 默认 0 + `Gr_Consent::allows('marketing')` 服务端回落 → 任一不满足零输出）；script_data = {url, token, consent}；**实测揭出两处客户端缺陷并修复**：无输入阅读者 dwell 恒 0-15（时钟只随 pointer/key tick 起算 → 改为脚本加载即起算 + 滚动计入活动（rAF 内）+ flush 补收开区间，30s 闲置闸语义不变）；rage 载荷恒报 3（判定即第三击并清窗 → 改为判定即时 + 同窗口同半径内后续点击递增计数，冷联系数不丢） | BehaviorModuleTest 12 例（默认关 / 词表开关 / 入队三闸含 consent-off·probe-off·admin / defer / script_data / 插件接线 apply_filters / 静态契约：预算双界、sendBeacon-only、唯一存储访问为 cookie 读、桶与帽标记、不引用 gr-probe.js）；:8091 真栈：首页 HTML 携 `gr-probe-behavior.js?ver=1.0.1` + `window.GreenPNGBehavior={url,token(输出掩码),consent:true}`；回落 0 → 页面 0 处脚本引用；真浏览器（Chrome 153 headless × 3 会话）：flush 落库 dwell 15-60（修复后 16s 纯阅读实测）/ scroll_depth 100 / dead_click `h1.wp-block-heading` / rage_click `clicks:5`（修复后 5 击实测，修复前同操作恒 3）；`wp_consent_marketing=deny` cookie → flush 零发（前后行数实证 0 增） | ADR-0012 D1/D3 |
| V10 | 采集端扩展 | ✅ | collect `behavior` 名信封（内层 ≤20 全验全存或全拒）；BEHAVIOR_FIELDS 分事件字段白名单（dwell: bucket∈四桶词表；scroll_depth: milestone∈{25,50,75,100}；rage_click: clicks 3..100 + locator；dead_click: locator）；locator 服务端 sanitize（`[^A-Za-z0-9_.#-]` 清洗 + 64 字符钳）于 store 点统一施加；行为组两目的闸：营销同意缺 → 400 gr_collect_consent；`Sec-Purpose: prefetch/prerender` 头 → 400 gr_collect_prefetch（经 $request->get_header）；每信标单次 session touch；直发单事件同校验且传输键（token/name/events）剥离；gr_collect_events 过滤器路线核心词表不动 | CollectControllerTest +8（批量 4 内层各自落行 + touch 唯一语句断言、consent 400、prefetch 400、7 例非法值（桶外/里程碑外/clicks 2 与 101/locator 长度与字符集）、21 内层 400、直发同校验 + 传输键剥离、locator 标记清洗）；:8091 curl 实测：`Sec-Purpose: prefetch` → 400 `gr_collect_prefetch`；无头批量 → 200 `{"stored":true}`；无 token → 401 | ADR-0012 D2 |
| V11 | 行为报表 | ✅ | `Gr_Daily_Aggregator::behavior_metrics()`（`WHERE event_group='behavior' GROUP BY event_name` → `behavior_events` 指标，metric_key=事件名）并入 aggregate_date（幂等 upsert 同既有家族）；Behavior Insights 页（Audience 区，Funnels & Goals 后）：engagement/friction 两标签，四 KPI 瓦读 dimension('behavior_events',30)（含 Deep readers % 派生注记）+ dwell/scroll/rage/dead 四明细列表读 Gr_Event_Repository::recent(name,25)（bucket/milestone/clicks/locator 载荷值、短 visitor、path；locator 列仅 friction）；e2e 留 V33 D10 一并 | DailyAggregatorTest +1（behavior_events 家族独立语句 + 7 行写入断言；既有 13 行家族测试零扰动）；BehaviorPageTest 5 例（菜单一枚 / 双标签 / 瓦值 40/10/3/7 + Deep readers 注记 / locator 列仅 friction / 空态）；:8091 真栈全链：beacon 3 内层事件（dwell 60-180 + rage clicks:7 `button#checkout-now` + dead `div.hero`）→ `aggregate_date` 15 行 → 页面 engagement 标签（Dwell events 瓦 + 60-180 桶明细）与 friction 标签（`button#checkout-now`、`>7<`、`div.hero`）全数渲染命中 | ADR-0012 D4 |
| V12 | 披露与实测 | ✅ | readme 探针段实述化：FAQ「What does the client probe collect?」与 Privacy 双处改四事件 + 双闸（默认关 + 访客同意，"both gates must open"）+ locator 结构性披露（tag+id/首 class，无文本无坐标）；Screenshots 清单 + 第 6 条 Behavior Insights；POT 重生成（1558→1812 行，behavior 相关条目 29 处，Behavior Insights / Prefetched pages do not count behavior / Deep readers 等串入册） | :8091 全项收官：词表 7 名（pageview/signal + 四行为名）；服务端输出闸（回落 0 → 0 脚本）；真浏览器四事件落库 + deny cookie 零发（V9 实测列）；prefetch 拒收 400（V10 列）；页面全链渲染（V11 列）；设置复原（behavior_enabled=0、fallback=0）+ 测试行清理（behavior 事件 12+3 行、behavior_events 统计 3 行、daily_stats 归零复核）+ debug.log 当日探针残留 1 行剔除（备份 debug.log.bak-p3，昨日历史行不动）；五检全绿：find-lint / phpcs 0 / compat 7.4- 0 / phpunit **754 tests 4,403 assertions**（728→754）/ PHPStan L6 clean | ADR-0012 D5，08 清单 |

### Phase 4 · CRM（ADR-0013）

| # | 短名 | 状态 | 交付物 | 实测 | 规范 |
| :--- | :--- | :---: | :--- | :--- | :--- |
| V13 | 联系人仓储 | ✅ | `Gr_Contact_Repository`：capture() 对 UNIQUE email_hash 原子 upsert（`ON DUPLICATE KEY UPDATE` + `IF(VALUES(x)…)` 仅补空——空白重投不抹已采姓名/绑定，last_seen 滑动、first_seen 只写一次、信封仅在新非空时替换）；id_for_email、attach_tag（INSERT IGNORE 词表 + 链接，is_system 语义）、row_for_id、ids_active_since（daily 骑手工单）、all_ids（键集分页波次工单）、set_score（0..100 钳制）、rfm_population、set_segment_and_ltv；档案/列表读补：paged（行+计数双语句、segment/tag 过滤）、tag_vocabulary（含计数）、tag_slugs_for_contact、`Gr_Event_Repository::recent_for_visitor` | ContactRepositoryTest 10 例（信封随机 IV → 断言 decrypt 往返；空值保留语义逐字段）；ContactsPageTest 5 例 + ContactProfilePageTest 7 例走真实仓储路径；:8091 实弹见 V15/V18 列 | ADR-0013 D1/D2 |
| V14 | 线索留存接线 | ✅ | `Gr_Form_Adapter_Base` 第三构造参数 `?Gr_Contact_Repository`（缺省自建，既有构造零扰动）；process() 同意门内 capture（email 双轨 + names + cookie 轨 visitor）→ 附加 `sys:form:{bridge}` 系统标签 → 归因绑定；phone 提取后按 schema 缺位诚弃 | FormAdaptersTest +5（19 例）：同意内落库+标签+转化 / 无同意零写 / FF 漂移路径捕获 / 无邮箱零联系人行 / phone 弃置；:8091 真栈 FF 表单 1（姓名/邮箱/主题/留言）提交 → contact 行（visitor 8e6cab0c… + 信封 decrypt 正确）+ conversion（fluentform/FF entry 1）+ sys:form:fluentform 标签全落；fallback=0 二次提交 → FF entry 2 落而 contacts/conversions 仍 1（同意门线上证实）；清理：行/标签/词条/页面 53/用户全删、fallback 复位 | ADR-0013 D2，docs/15 C5 |
| V15 | 评分引擎与规则页 | ✅ | `Gr_Scoring_Rules`（非 autoload option、六名闭词表、points ±100/cap 0..10 边界、重名坍缩、≤30 行、畸形行读侧跳过）；`Gr_Scoring_Engine`：WINDOW_DAYS=30 与事件保留窗对齐，Σ rule.points × Σ_days min(count, daily_cap) → set_score 钳 0..100；三触发面共一码路：daily 骑手（优先级 8，25h 垫整窗）／RECOMPUTE_HOOK 波次（键集 500 波、满波再入队）／档案页单算；规则页单表单 CRUD（整表可编辑 + 空白行追加 + 勾选移除），save → 审计 + `Gr_Queue::enqueue` 批量重算 + PRG，拒收回显已提交行 | ScoringRulesTest 12 例；ScoringEngineTest 10 例（bot 短路不读事件、零 cap、钳制带、波次再入队）；ScoringRulesPageTest 6 例（双闸、拒收回显、审计+队列断言）；:8091 实弹：种子 2 联系人 + 8 事件 + 1 转化 + 规则 → 引擎现算 **42（15 pageview + 2 dwell + 25 conversion）与 10 精确落库**；UI 改 dwell 4 + 增 rage_click 保存 → option 落 4 规则、审计 `save/scoring_rules` 全量快照、**AS 后端 `gr_scoring_recompute_all` 派发并 complete**、波次重跑 42→**44（15+4+25）** | ADR-0013 D3 |
| V16 | bot 结论消费 | ✅ | `Gr_Session_Repository::is_bot_for_visitor`（get_var EXISTS 型点读）→ 引擎短路：score 0 + `sys:suspected_bot` attach-only 系统标签（文案明示 v1.1 无插件内移除、移除是站长的手动行为） | ScoringEngineTest 2 例（判定盖过 100 分规则、无事件读短路 + INSERT IGNORE 词表 SQL 断言）；ContactsPageTest 标签页渲染 is_system 词表 | ADR-0013 D3 |
| V17 | RFM 引擎 | ✅ | `Gr_Rfm_Engine`：三轴现算（r=last_seen Unix 秒、F=绑定数全量、M=净额 status='active'）；PHP 五等分（升序、`(pos+1)*5/N` 顶桶任意 N 可达、同名值坍缩首位置得分、**零 F/M 按语义钉底 1**——单人无购不得因独占而登顶）；八段真值表（champions 三高、lost 垫底）；movement-only 写（segment/ltv 皆同则零写）；daily 骑手优先级 9；axes_for() 档案页现算只读路径（与 compute_all 共享 population_axes 纯计算，单次读） | RfmEngineTest 8 例（真值表全词覆盖 canonicalizing、并列坍缩、单人退化 'new'、净额轴含冲销、无扰动零写）；**实测抓到真缺陷：浮点金额做数组键 → PHP 8.1+ "Implicit conversion" 弃告每趟刷屏 → axis_key() 规范字符串键（%.2F）修复，:8091 复跑 0 弃告**；实弹双人（20 天差 last_seen）：champions r5/f5/m5 ltv 199.50 与 at-risk r2/f1/m1 ltv 0.00 皆精确 | ADR-0013 D4 |
| V18 | CRM 三页与隐私 | ✅ | Contacts 页（Audience 区首项，docs/06 树序 Contacts→Scoring Rules→Behavior Insights；列表 tab：掩码邮箱 + 姓名/评分/段/净额/last_seen + segment 白名单过滤 + tag 过滤 + 服务端分页；标签 tab：词表+计数+attach-only 文案；RFM tab：八段分布含"未分段"行）；Contact Profile 页（Contacts 子菜单行内进入：掩码默认 + Reveal email 写（双闸 + 审计载**掩码非明文** + PRG 单视图明文）+ Recompute now 写（评分引擎 + RFM 现算 + 审计）+ r/f/m 现算显示 + 标签 + 绑定 + 时间线三段（事件 30/转化全量/会话全量）+ 回退轨无时间线文案）；Privacy API：contact_row 改 **hash_pii_sha256 与表单桥同哈希**（原 hash_pii 前缀型哈希错配 → 永远查空，实测修复）、擦除先扫 tag 链接再删行、visitor_ids_for_email 并入联系人自身 visitor 绑定（无订单的表单线索也认领会话/触点）、政策文案 +CRM 段 | ContactsPageTest 5 + ContactProfilePageTest 7（双闸两向、揭示审计 diff 只载掩码、现算得分/段落库、掩码永不出明文、未知 id 自述）；WpPrivacyApiTest 16→**17 例**（哈希口径断言、链扫先于行删、联系人绑定并入映射链）；:8091 实弹全链：Contacts 列表双行掩码 `gr****om`/42/champions/199.50 + champions 过滤单行 + 标签/RFM tab 计数对；档案页全字段 + Reveal（PRG `revealed=1`、单视图明文 `grcrm@example.com`、审计 `reveal/contact_email` 载 `gr****om`）+ Recompute（`rescored=1` + 审计）；隐私：export 返 1 item 7 字段（无哈希无信封）、erase B → 行+链接清、A 的 visitor 链 `["v-crm-live"]` 无订单也通；**实测再抓两真缺陷：无过滤列表渲染 prepare() 零占位符触发核心 notice（:8091 log 证实）→ paged() 无参分支直查修复复测 0 notice；列表姓名列空 → 行预拼 name 修复**；清理：种子 8 事件/2 会话/1 转化/2 联系人/词表/链接/规则 option/审计 3 行/临时管理员全删，debug.log 当日残留（4 浮点弃告 + 2 notice + phar 噪声 + 1 parse error）剔除备份 .bak-p4，09-14 仅存 automatic-updates 4 行 | ADR-0013 D5，docs/06 |

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
