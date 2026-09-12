# 03. 函数注册表 (Function Registry)

> **本文档是 greenpng 全局函数的唯一规格。** 每个函数在此登记后才允许实现；实现必须与签名一致。
> **与参考项目的关系**：本表以 wp-plug 原型中**真实存在**的 83 个 `agy_*` 函数为起点，按 `01-wp-plug-analysis-and-assessment.md` 的处置决议（保留/重构/降级/合并）重映射为 `gr_*`，并补齐其覆盖缺口。agy 命名到 gr 命名的完整对照见附录 A。
>
> **分层纪律**：`gr_*` 全局函数只是门面（≤3 行转发到类方法），业务逻辑在类里。REST 控制器与管理页渲染回调不在本表（见 `06` 与各自模块）。
> **修订记录**：2026-09-10 类名统一为 `Gr_` 前缀（`class-gr-<slug>.php` 文件名与 WordPress.Files FileName 规则及 `02`/`13` 口径对齐）；§1 `gr()` 与 §2 `gr_dispatch_event()` 返回类型同步修正。

---

## 1. 核心与运行时（Core）

| 函数 | 签名 | 说明 | 来源 |
| :--- | :--- | :--- | :--- |
| `gr()` | `gr(): GreenPNG\Core\Gr_Plugin` | 主容器访问器 | 改名自 `agy_kernel()` |
| `gr_get_client_ip()` | `gr_get_client_ip(): string` | **重写**：默认仅 `REMOTE_ADDR`；仅在站长配置可信代理后才读代理头（修复缺陷 S1） | 重写 `agy_get_client_real_ip()` |
| `gr_get_user_agent()` | `gr_get_user_agent(): string` | 消毒并截断至 512 字符 | 改名 |
| `gr_generate_event_id()` | `gr_generate_event_id(string $prefix='gr', string $entropy=''): string` | `prefix_` + 16 字节随机 hex；用于幂等键 | 改名 |
| `gr_hash_pii()` | `gr_hash_pii(string $value, string $type): string` | 小写规范化 + SHA-256；空进空出 | 改名自 `agy_sanitize_and_hash_pii()` |
| `gr_sign_hmac()` | `gr_sign_hmac(string $data, string $secret): string` | `hash_hmac('sha256')`；Webhook 签名用 | 改名自 `agy_capi_sign_hmac()` |

## 2. 事件（Event）——重构为 WP 原生钩子

| 函数 | 签名 | 说明 | 来源 |
| :--- | :--- | :--- | :--- |
| `gr_dispatch_event()` | `gr_dispatch_event(string $name, array $payload=[]): GreenPNG\Core\Gr_Event` | 构造 DTO → 持久化（若该事件类型启用了落库）→ `do_action('gr_event', $event)` | 重构 `agy_dispatch_event()` |
| `gr_get_recent_events()` | `gr_get_recent_events(string $name='', int $limit=50): array` | 读事件流水 | 改名 |

> 废弃：`agy_listen_event()`（直接用 `add_action('gr_event', ...)`）、`agy_clear_event_history()`（清空语义由瘦身模块接管）。

## 3. 流量安全风控（Security）

| 函数 | 签名 | 说明 | 来源/变更 |
| :--- | :--- | :--- | :--- |
| `gr_match_cidr()` | `gr_match_cidr(string $ip, string $cidr): bool` | IPv4 位运算 + IPv6 前缀匹配 | 改名并补 IPv6 |
| `gr_mask_ip()` | `gr_mask_ip(string $ip): string` | 展示脱敏：`inet_ntop` 规范化后**末段遮蔽**（v4 末八位组、v6 末组；规范形 `::` 尾部追加遮蔽标记）；非法输入返回空串——展示层永不回显无法解析的地址；纯展示，遮蔽形**永不回存/参与匹配** | 新增（ADR-0007 IP 双轨展示侧） |
| `gr_verify_crawler()` | `gr_verify_crawler(string $ip, string $ua): array` | FCrDNS 双向验证；**必须含 DNS_AAAA 正查**；结果 transient 缓存 24h；任何失败返回"无法验证"而非"伪造" | 重写（修复 IPv6 致命 bug 与同步 DNS 阻塞） |
| `gr_is_trusted_ip()` | `gr_is_trusted_ip(string $ip): bool` | 命中允许列表规则 | 改名 |
| `gr_is_url_allowed()` | `gr_is_url_allowed(string $uri): bool` | 路径前缀/通配符匹配 | 改名 |
| `gr_is_ip_blocked()` | `gr_is_ip_blocked(string $ip): bool` | 封禁规则 + 临时锁定（transient） | 新增（原型只有静态方法） |
| `gr_block_ip()` / `gr_unblock_ip()` | `gr_block_ip(string $ip, string $reason='', int $ttl=DAY_IN_SECONDS): void` | 临时封禁（transient）+ 记录 | 改名 |
| `gr_log_security_event()` | `gr_log_security_event(string $ip, string $rule_id, string $url, string $ua, string $reason): void` | 浪涌折叠：同折叠键 UPDATE `hit_count` 原子自增，不新增行 | 改名自 `agy_surge_fold_log()` |
| `gr_inspect_request_payload()` | `gr_inspect_request_payload(array $data): array` | **重写后的保守规则集**：默认只检测高置信 SQLi/LFI 模式，命中默认仅标记不拦截（成熟度模型见 `10` §4） | 重写 `agy_security_inspect_payload()` |
| `gr_is_scanner_ua()` | `gr_is_scanner_ua(string $ua): bool` | 扫描器 UA 特征（sqlmap/nikto/wpscan 等） | 改名 |
| `gr_render_honeypot()` | `gr_render_honeypot(string $form_context): string` | 输出隐藏陷阱字段；**必须 `aria-hidden="true"` + `tabindex="-1"`**（无障碍，修复 R4 风险） | 新增 |
| `gr_check_honeypot()` | `gr_check_honeypot(array $post): bool` | 陷阱字段非空即为脚本填充 | 新增 |
| `gr_check_login_lockout()` | `gr_check_login_lockout(string $username, string $ip): array` | 失败计数 + 递增锁定时长判定 | 重构 `agy_verify_login_honeypot_and_lockout()`（拆分为两个单一职责函数） |
| `gr_record_login_failure()` | `gr_record_login_failure(string $username, string $ip): void` | 计数 + 达阈值锁定 | 新增（拆分） |

## 4. 营销归因（Attribution）

| 函数 | 签名 | 说明 | 来源 |
| :--- | :--- | :--- | :--- |
| `gr_parse_attribution_params()` | `gr_parse_attribution_params(array $params): array` | 提取 utm_source/medium/campaign/term/content + gclid/gbraid/wbraid/fbclid/ttclid/msclkid | 改名扩展 |
| `gr_record_touchpoint()` | `gr_record_touchpoint(string $visitor_id, array $params, string $session_id='', string $url=''): int` | 写入触点行 | 改名 |
| `gr_get_touchpoints()` | `gr_get_touchpoints(string $visitor_id, int $days=30): array` | 触点序列 | 改名 |
| `gr_calculate_attribution()` | `gr_calculate_attribution(array $touchpoints, float $amount): array` | 5 模型：first/last/linear/position-based(40/20/40)/time-decay(7 日半衰) | 改名 |
| `gr_bind_conversion()` | `gr_bind_conversion(int $order_id, string $visitor_id, float $amount, string $currency): int` | 订单↔归因永久绑定，UNIQUE 幂等 | 改名自 `agy_attr_bind_order()` |

> 落地形态（2026-09-10，C7/C8 实装）：§4 前四个门面全部就位——`gr_parse_attribution_params` → `Gr_Attribution_Params::parse()`（渠道闭合词表：点击 ID 定 cpc/social → utm_medium 词表 → 外部引荐 direct 升 referral → 兜底 direct/other；`apply_referrer()` 纯函数）；`gr_record_touchpoint`/`gr_get_touchpoints` → `Gr_Touchpoint_Repository`；`gr_calculate_attribution` → `Gr_Attribution_Models::calculate()`（5 模型一次算齐，返回 `model => [touchpoint_id => {weight, amount}]`；**分币对账**使每模型合计恒等转化金额——逐点 floor 到分、缺分按权重降序平手给更晚触点；时间衰减 = `0.5^(age_days/7)` 相对最新触点；输入内排序 created_at+id）。position-based 退化口径：n=1 全取、n=2 五五、n≥3 40/20/40。`gr_bind_conversion` 于 C9 落地。

## 5. 转化漏斗（Funnel）——含原型完全缺失的部分

| 函数 | 签名 | 说明 | 来源 |
| :--- | :--- | :--- | :--- |
| `gr_funnel_track_step()` | `gr_funnel_track_step(string $funnel_id, string $session_id, int $step): void` | 漏斗状态机步进 | **新增**（原型无漏斗引擎） |
| `gr_funnel_get_stats()` | `gr_funnel_get_stats(string $funnel_id, int $days=30): array` | 各步骤留存/流失率 | **新增** |
| `gr_goal_record()` | `gr_goal_record(string $goal_key, string $visitor_id, array $context=[]): int` | 宏/微观目标达成 | 改名 |
| `gr_goal_get_stats()` | `gr_goal_get_stats(string $goal_key='', int $days=30): array` | 目标聚合 | 改名 |
| `gr_ab_assign_variant()` | `gr_ab_assign_variant(string $experiment, string $visitor_id): string` | 一致性哈希分流（稳定分桶） | 改名 |

> **C14 落地（2026-09-10）**：`gr_ab_assign_variant` 已交付 → `GreenPNG\Funnel\Gr_Ab_Engine::assign()`（一致性哈希 `sprintf('%u', crc32(...)) % n` 跨位宽稳定；`?gr_variant=` 强制仅限实验声明变体；未定义/停用返 ''）。实验定义仓储 = `Gr_Ab_Experiments`（非 autoload `gr_ab_experiments`，首写定 autoload=no、空批整行删除；有界校验 ≤20×≤8）。短码 `[gr_ab experiment=... <变体名>=<内容>]` = `Gr_Ab_Shortcode`（动态属性直解析 $atts——真核 `shortcode_atts` 剥非 defaults 键；fail-open 回落 control）。`gr_ab_record`/`gr_ab_significance` 属 C15。
| `gr_ab_record()` | `gr_ab_record(string $experiment, string $variant, string $type): bool` | type ∈ impression/conversion | 合并原型两个函数 |
| `gr_ab_significance()` | `gr_ab_significance(string $experiment): array` | 双比例 Z 检验，n<30 返回"样本不足" | 改名 |

> **C15 落地（2026-09-10）**：两函数已交付。`gr_ab_record` → `Gr_Ab_Recorder::record()`（事件名 `ab`/组 `funnel`，ab_* 专列入库；停用实验照记——in-flight 访客不作废）。`gr_ab_significance` → `Gr_Ab_Significance::calculate()`（pooled 双比例 Z 检验，control vs 每个变体；每臂 <30 → insufficient；|z|≥1.96 → significant+winner；SE=0 诚实 tie；聚合读 = `Gr_Event_Repository::ab_counts()` 走 `ab_events` 索引）。真站 z 值与单测手工期望逐位一致。
| `gr_cart_capture_email()` | `gr_cart_capture_email(string $session_id, string $email, array $cart=[]): int` | 失焦邮箱捕获 + 64 位挽回令牌。**调用前置条件：站长已开启且页面有同意勾选** | 改名 + 合规前置 |
| `gr_cart_mark_abandoned()` | `gr_cart_mark_abandoned(int $minutes=15): int` | 批量状态流转（cron 调用） | 改名 |
| `gr_cart_mark_recovered()` | `gr_cart_mark_recovered(string $token, int $order_id): bool` | 令牌核销 | 改名 |
| `gr_cart_get_stats()` | `gr_cart_get_stats(): array` | 捕获/弃购/挽回/金额 | 改名 |

## 6. 行为与 CRM 评分（Behavior & CRM）

| 函数 | 签名 | 说明 | 来源 |
| :--- | :--- | :--- | :--- |
| `gr_detect_rage_clicks()` | `gr_detect_rage_clicks(array $clicks, int $window_ms=1000, int $min=3, int $radius_px=25): array` | 时空聚类怒点检测 | 改名 |
| `gr_is_dead_click()` | `gr_is_dead_click(string $tag, bool $navigated, int $dom_mutations=0): bool` | 死点击启发式 | 改名 |
| `gr_scroll_milestones()` | `gr_scroll_milestones(float $max_pct): array` | 25/50/75/100% 分档 | 改名 |
| `gr_lead_score()` | `gr_lead_score(array $events, int $days_inactive=0): int` | 0-100 加权 + 30 日半衰（0.90^⌊天/30⌋）。**权重常数集中定义于 `Gr_Scoring_Config`，禁止散落魔法数字**（修复原型三处文档互相矛盾的问题） | 重构 |
| `gr_rfm_segment()` | `gr_rfm_segment(int $recency_days, int $frequency, float $monetary): array` | R/F/M 三维评分 → 8 象限 | 改名 |
| `gr_contact_sync()` | `gr_contact_sync(string $email, array $data): int` | upsert 联系人（email 存哈希+可选加密） | 改名 |
| `gr_tag_attach()` / `gr_tag_detach()` | `gr_tag_attach(int $contact_id, int $tag_id): bool` | **新增**：标签多对多关联（修复原型把标签塞 JSON 字段的缺陷） | 新增 |
| `gr_evaluate_tag_rules()` | `gr_evaluate_tag_rules(array $profile, int $score, float $ltv, string $rfm): array` | 规则打标 | 改名 |
| `gr_user_quality_score()` | `gr_user_quality_score(int $user_id): array` | 注册用户 0-100 质量分 | 改名 |
| `gr_is_disposable_email()` | `gr_is_disposable_email(string $email): bool` | 本地一次性邮箱域名表 | 改名 |

## 7. 生态集成与出网（Integrations）

| 函数 | 签名 | 说明 | 来源 |
| :--- | :--- | :--- | :--- |
| `gr_uif_extract_fields()` | `gr_uif_extract_fields($payload): array` | 语义自适应提取（`auto:email`/`auto:name`/`auto:amount`） | 改名（参数省略类型声明，PHP 7.4 无 `mixed`） |
| `gr_uif_detect_ecosystem()` | `gr_uif_detect_ecosystem(): array` | 扫描 `active_plugins`，报告可用桥接 | 改名 |

> **C12 落地（2026-09-10）**：两函数已交付。`gr_uif_extract_fields` → `GreenPNG\Integrations\Gr_Semantic_Extractor::extract()`（纯函数零写入；深度 8 层封顶、对象经 `get_data()` 或公有属性并入、email 键提示 + RFC 值采纳双通道且 url/id 类键拒绝采纳、phone 仅键命中且 7 位数字起、first/last/full name 词表含 CF7 `your-name`、amount 键词表 + 货币符号千分位剥离、currency 3 字母大写归一、名字单侧检测自动推导另一侧表示、custom_fields 经 `sanitize_text_field` 且 64 键封顶、`detected_keys` 记语义 → 点路径供诊断）。`gr_uif_detect_ecosystem` → `Gr_Ecosystem_Detector::detect()`（单次 `get_option('active_plugins')` 读，零目标插件代码加载；目录固定四桥 woocommerce/fluentform/cf7/wpforms，wpforms-lite 归并 wpforms；非数组 option 值降级为空目录）。语义提取是 C11 表单桥的金额/币种提取引擎，实施次序刻意先于 C11（原语先行，同 C2→C1 先例）。
| `gr_capi_dispatch()` | `gr_capi_dispatch(string $channel, string $event, array $data): array` | **统一入口**（channel ∈ ga4/meta/tiktok），内部调度异步队列。**合并原型的 Capi_Dispatcher 与 Universal_Capi_Gateway 双栈**（payload 只构建一遍） | 合并重构 |
| `gr_capi_build_payload()` | `gr_capi_build_payload(string $channel, array $event): array` | 纯本地 payload 构建 | 改名 |
| `gr_webhook_dispatch()` | `gr_webhook_dispatch(string $hook_id, array $payload): bool` | HMAC-SHA256 签名出网；`timestamp.body` 签名串；`hash_equals` 验签文档化 | 新增 |
| `gr_query_ip_reputation()` | `gr_query_ip_reputation(string $ip): array` | AbuseIPDB 等可选情报查询；24h transient 缓存；429/超时熔断（继承 wp-plug 档案中最好的弹性设计） | 新增 |
| `gr_geoip_country()` | `gr_geoip_country(string $ip): string` | DB-IP Lite 本地国家库查询（**v1.0 随包预置**，ADR-0007）；每请求 L1 静态缓存；**零外呼**；库更新仅经站长显式按钮 | 新增（ADR-0007） |

## 8. 审计（Audit）

| 函数 | 签名 | 说明 |
| :--- | :--- | :--- |
| `gr_audit_diff()` | `gr_audit_diff(array $old, array $new): array` | 递归 added/modified/removed |
| `gr_audit_log()` | `gr_audit_log(string $action, string $object_type, string $object_id, array $old, array $new, int $user_id=0): int` | 写审计流水 |
| `gr_audit_query()` | `gr_audit_query(array $filters=[], int $limit=50, int $offset=0): array` | 过滤分页查询 |

## 9. 动态事件嗅探（Dynamic Events）

| 函数 | 签名 | 说明 |
| :--- | :--- | :--- |
| `gr_dynamic_event_register()` | `gr_dynamic_event_register(array $config): int` | 站长自定义 Hook 监听规则落库 |
| `gr_dynamic_events()` | `gr_dynamic_events(): array` | 已激活规则列表 |
| `gr_dynamic_event_delete()` | `gr_dynamic_event_delete(int $id): bool` | 删除规则 |
| `gr_param_resolve()` | `gr_param_resolve(string $expression, array $args, array $context=[])` | 点语法参数提取（`args[0].total`、`auto:email`） |

> 原型的 `agy_sniffer_*` 4 个函数（全局 Hook 嗅探器）**降级为仅后台工具页按需启用**，禁止前台常驻。

## 10. 维护（Maintenance）——补齐原型"只建 SQL 不执行"的缺口

| 函数 | 签名 | 说明 |
| :--- | :--- | :--- |
| `gr_prune_table()` | `gr_prune_table(string $table_key, string $date_col, int $retention_days, int $batch=2000): int` | **实际执行**分批删除（LIMIT + 100ms 间隔），返回删除行数 | 修复原型孤儿函数 |
| `gr_get_table_stats()` | `gr_get_table_stats(): array` | 各表行数与体积（读 `information_schema`，带缓存） | 新增 |
| `gr_schedule_maintenance()` | `gr_schedule_maintenance(): void` | 注册每日 `gr_cron_daily_maintenance`（汇总→瘦身） | 新增 |
| `gr_export_diagnostics()` | `gr_export_diagnostics(): array` | 环境自检数据（PHP/WP/MySQL 版本、队列状态、表健康），脱敏后供下载 | 新增 |

---

## 附录 A：agy → gr 重映射总表（改名速查）

```
目录/文件      agy-suite/                → greenpng/
主文件         agy-suite.php             → greenpng.php
类文件         class-agy-*.php           → class-gr-*.php
命名空间       Agy\<Sub>                 → GreenPNG\<Sub>
常量           AGY_VERSION 等 5 个        → GR_VERSION 等
函数           agy_*() ×83               → gr_*()（按本表规格）
数据表         {$wpdb->prefix}agy_* ×13  → {$wpdb->prefix}gr_*（表结构以 05 为准，非平移）
Option         agy_* ×11                 → gr_*（收敛为 1 个 autoload 主设置 + 少量 gr_* 选项）
Transient      agy_fcrdns_/agy_block_/agy_fail_/agy_live_visitors → gr_*
User meta      _agy_* ×8                 → _gr_*
Cookie         agy_attr                  → gr_attr
钩子           do_action('agy_event_*')  → do_action('gr_event') / gr_* 动作
Text Domain    agy-suite                 → greenpng
菜单 slug      agy-suite                 → greenpng / greenpng-<module>
CSS            .agy-* ×55                → .gr-*
JS 全局        window.AgyDataGrid 等     → window.GreenPNG（单一入口）
REST           （原型不存在）            → greenpng/v1（新建）
```

## 附录 B：明确废弃（不进入 greenpng 的 agy 元素）

- `?agy_test_runner=1` 端点（未鉴权数据操纵后门）
- `agy_live_visitors` 读-改-写 transient 计数器（并发丢更新 + wp_options 写热点）
- 一切 `mock_*` 默认凭据（静默假成功）
- 明文存储的 `agy_meta_access_token` 等密钥 option（改用 `Gr_Core_Secrets`）
- 文档 34 纸面清单中的 9 个云端函数与 125 个未实现函数名
- `window.AgySimulator` 与 `agy-interaction-simulator.js`（纯造假交互层）
- CSS 类 `agy-cloud-grid`、`agy-api-badge-rust` 及全部 `agy_live_*` 假密钥字符串

## 附录 C：规模与缺口说明

- 本表登记 **64 个全局函数**（继承改名/重构 56 个 + 新增 8 个）。相比原型 83 个减少的原因是：合并 CAPI 双栈、拆分误合并函数、删除造假层、删除无实现函数名。
- 全局函数之外，领域能力主要由类方法承载（约 40~50 个类）。**函数数量不是 KPI**；覆盖缺口由 `05`（表）、`06`（页面）、REST 控制器共同闭环，不以堆砌函数数为解决手段。
- 原型 13 张表仅 1 张有完整 CRUD 的教训：本注册表中的每个"写"函数必须能在 `06` 的页面结构中找到对应的"读/管理"界面，否则评审不予通过。
