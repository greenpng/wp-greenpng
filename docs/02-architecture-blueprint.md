# 02. 架构蓝图 (Architecture Blueprint)

> **本文档是 greenpng 的唯一架构真源。** 与参考项目 wp-plug 的六层企业架构的差异及理由见 `01-wp-plug-analysis-and-assessment.md` §5.2 与 ADR-0003。
> **修订记录**：2026-09-09 依据 ADR-0007——§2.4 异步改为自适应队列；§2.6 适配器补通用化原则；§4 身份双轨与探针数据流。

---

## 1. 架构总览：三层 + 两个横切面

```
┌────────────────────────────────────────────────────────────────┐
│                        WordPress 核心                          │
│   do_action / apply_filters / REST / WP-Cron / WP_List_Table   │
│        （含宿主已有的 Action Scheduler —— 运行时嗅探使用）        │
└──────────────┬─────────────────────────────┬───────────────────┘
               │                             │
┌──────────────▼──────────────┐   ┌──────────▼───────────────────┐
│   采集层 (Collect)          │   │   管理后台 (Admin)            │
│   • WP 钩子监听             │   │   • 20 页，WP 原生组件        │
│   • REST 采集端点 (1个)     │   │   • WP_List_Table + form-table│
│   • 前台探针 gr-probe.js    │   │   • Settings API（独立页）    │
│     （安全模块默认开 /       │   │   • REST 读接口 (greenpng/v1) │
│       行为模块 v1.1 同意）   │   └──────────┬───────────────────┘
│   • 生态插件适配器          │                │  只读仓储
└──────────────┬──────────────┘                │
               │  gr_event（do_action + DTO）  │
┌──────────────▼─────────────────────────────▼───────────────────┐
│                     领域层 (Domain)                             │
│   Security │ Attribution │ Funnel │ Behavior/CRM │ Integrations │
│   每个域是纯 PHP 类集合：输入数组/DTO → 计算 → 仓储写入          │
└──────────────┬─────────────────────────────────────────────────┘
               │
┌──────────────▼─────────────────────────────────────────────────┐
│                     存储层 (Storage)                            │
│   Repository 类（每表一个，唯一允许碰 $wpdb 的地方）            │
│   gr_* 自定义表 + 1 个 autoload 设置项 + Gr_Queue 异步任务      │
└────────────────────────────────────────────────────────────────┘

横切面 A：Observability —— 结构化日志、健康检查（只读诊断，不外发）
横切面 B：Privacy —— 双轨（ADR-0007）：安全轨合法利益 / 营销轨同意门控
```

## 2. 关键架构决策（每条附理由）

### 2.1 不要 DI 容器，显式构造
`Gr_Plugin`（`class-gr-plugin.php`）在 `plugins_loaded` 优先级 10 显式构造约 8~14 个服务并注入构造函数。
> 落地形态（2026-09-10，S3 实装）：Phase 1 协作方均为静态门面（`Gr_Queue` / `Gr_Schema` / `Gr_Cli`，与 §2.4 的静态 API 一致），在 `register_hooks()` 显式接线；实例服务自 C 阶段起以构造函数注入加入。服务清单以构造函数签名 + `register_hooks()` 为准（静态可查；PHPStan 全量覆盖于 T4 收口）。
**理由**：每个接口只有一个实现，无运行时替换需求；反射装配在 WP 无编译缓存，是纯开销；显式构造可被静态分析（PHPStan）完整覆盖，且同样可测试（测试里手动注入 mock）。wp-plug 的 PSR-11 容器评估记录见 `01` §5.2。

### 2.2 事件用 WP 原生钩子 + 一个 DTO，不自建总线
```php
// 定义：includes/core/class-gr-event.php —— 一个普通不可变风格 DTO（PHP 7.4 无 readonly，用私有属性+getter）
// 派发：
do_action('gr_event', Gr_Event $event);
// 域内订阅：
add_action('gr_event', [Scoring_Service::class, 'on_event']);
```
**理由**：第三方开发者用已知的 WP API 即可扩展；Query Monitor 等工具天然可观测；零自维护成本。wp-plug 自建总线的 `ksort` 每派发重排序等问题不复存在。

> 落地形态（2026-09-10，C1 实装）：DTO 经 `Gr_Event::create()` 构造——payload 中 7 个上下文键（visitor/session/event_id/event_group/ab_*）提升为列且从 payload_json 剔除，并按列宽截断；落库开关为 `gr_persist_event` 过滤器（默认开）；派发次序固定为 落库 → `do_action('gr_event', $event)`，订阅者可读到行 id。读侧 `gr_get_recent_events()` 仓储 LIMIT 钳制 1..500 并解码 payload_json。

### 2.3 仓储模式隔离 $wpdb
- 只有 `includes/storage/` 下的 Repository 类允许出现 `$wpdb`。
- 领域层只面对 Repository 的方法签名（返回数组或 DTO），可脱离 WordPress 单测。
- 表名统一由 `GreenPNG\Core\Database::table('security_logs')` 解析。

> 落地形态（2026-09-10，C2 实装）：解析器为 `GreenPNG\Core\Gr_Database::table()`（Core 门面，不触 `$wpdb`，请求内缓存），实现在 `Gr_Schema::resolve_table()`（DDL 派生名单校验，未知名抛 `InvalidArgumentException`；可选 `gr_` 前缀等价）。`gr()` 返回 `Gr_Plugin::instance()`（惰性构造、私有构造函数；钩子注册仍仅经 `run()` 于 `plugins_loaded@10`）。

### 2.4 异步：自适应队列（ADR-0007），不自建也不教条排斥 Action Scheduler
统一入口 `Gr_Queue::enqueue(string $hook, array $args = []): void`：
1. 运行时嗅探：宿主已加载 Action Scheduler（`function_exists('as_schedule_single_action')`，如所有 WooCommerce 站）→ 入 AS 队列，享受其重试与并发锁；
2. 否则回落 `wp_schedule_single_event()` + **transient 互斥锁（TTL 300s）防并发重入**；
3. 日常维护仍是单一每日钩子 `gr_cron_daily_maintenance`；提供 WP-CLI 命令 `wp greenpng maintenance` 供真实系统 cron 驱动；状态页显示当前队列后端并引导配置系统 cron。
**理由**：WP-Cron 依赖访客触发，低流量站的弃购挽回/CAPI 回传会被延挙数小时；WooCommerce 站全部自带 AS。不打包 AS（GPLv3，实读自 woocommerce/packages/action-scheduler/license.txt；不分发则无许可问题）。iss/ 报告的相关评审见 `14`（其 AutomatorWP 证据不实，但 WooCommerce 事实成立）。

### 2.5 前台采集端点：一个 REST 路由，签名令牌 + 限流
```
POST /wp-json/greenpng/v1/collect
```
- `permission_callback => '__return_true'`（公开端点），但三重防护：
  1. **旋转日盐令牌**：服务端每日派生令牌，经 `wp_localize_script` 注入页面；验证用 `hash_equals`。令牌不是秘密，作用是抬高脚本批量灌水的成本并支持按天失效。
  2. **每 IP 限流**：对象缓存优先、transient 兜底（短 TTL，不长驻）。
  3. **严格 schema + 8KB body 上限**：REST `args` 校验全部字段。schema 白名单含探针安全信号字段（`bot_score` 与自动化标志集）——**仅结论值，不收指纹原始串**。
- `nocache_headers()` + 对缓存插件声明不缓存。
- 探针用 `navigator.sendBeacon(url, new Blob([json], {type:'application/json'}))`，保证 WP REST 能解析 JSON body。
**理由**：wp-plug 的独立 `collect.php`（绕过 WP 引导）在审核与主机兼容性上都是雷区；无防护的公开写端点是数据库灌水通道。

> 落地形态（2026-09-10，C6 实装）：`Gr_Collect_Controller`（Rest 层）。permission 阶段即三重防护（令牌 → 限流 → 8KB，顺序保证被拒请求零落库 SQL），未采用 `__return_true`——实测发现核心 REST 的必填 args 检查先于 permission 回调执行，故 token 不标 `required`、由 gate 归口 401（无/错令牌均 401 实证）。事件词表 = `pageview`(web)/`signal`(probe) 经 `gr_collect_events` 过滤器可扩展；字段白名单 9 键硬编码，**身份键（visitor_id/session_id）刻意不在白名单**——身份由服务端 `gr()->identity()` 双轨解析注入，客户端提交身份键按未知字段 400 拒绝（实测 `field=visitor_id` 拒绝样例）。`signal` 额外要求 `bot_score` 整数 0..100（args min/max 核心校验 + 控制器 is_int 双保险）与四布尔标志，`probe_enabled=0` 时 400。限流 `Gr_Rate_Limiter`：`wp_using_ext_object_cache()` 为真走 `wp_cache_add/incr`（group `greenpng`，0 SQL），否则 per-key transient（键 `gr_rl_collect_{md5(ip)}`，60/60s，`gr_collect_rate` 过滤器可调；**被拒请求只读不写**，实测 429 后计数停在 60）。令牌 = `Gr_Secrets::sign_hmac('collect|' . 日期, wp_salt 派生键)`，非秘密、跨日必变（实测）。每请求 SQL 实测见 `09` §1.1 落地记录。

### 2.6 模块加载：零闲置成本 + 通用化集成（ADR-0007）
每个集成适配器实现 `GreenPNG\Integrations\Adapter_Interface`：
```php
interface Adapter_Interface {
    public static function get_id(): string;
    public static function is_available(): bool;  // 检测目标插件是否存在且启用
    public function register_hooks(): void;       // 只有 is_available() 为真才被调用
}
```
- 未安装 WooCommerce 的站点，WooCommerce 适配器**连类文件都不加载**。
- 每个适配器的钩子回调包裹 `\Throwable` 隔离：第三方插件抛错不得影响 greenpng 与站点。
- **通用化三原则**（ADR-0007，与铁律同级）：
  1. 只通过目标插件的**公开 Hook / 公开 API** 挂接（这是"底层函数"），不读其私有结构、不做版本号锁定分支；
  2. **禁止样本库式检测**（以代码样本/签名匹配方式识别或适配插件）——目标插件升级即失效；
  3. 每个**主 Hook 配回退 Hook**，Hook 漂移时状态页可见告警，绝不静默失效。
（三原则继承自 wp-plug 文档 27 的适配器原则并按站长 2026-09-09 指示强化。）

> 落地形态（2026-09-10，C10 实装）：契约 = `GreenPNG\Integrations\Adapter_Interface`（`includes/integrations/class-gr-adapter-interface.php`，autoloader 的 Gr_ 前缀剥离对无前缀名同样产出 `class-gr-adapter-interface.php`）。首个适配器 = `Gr_Woocommerce_Adapter`（`integrations/ecosystem/`）：三挂载（经典 checkout meta + Store API + payment_complete），HPOS 两栖 CRUD 写法，回写仅 cookie 轨 visitor_id（write-once），绑定走 meta 锁 + UNIQUE 双防线 + `Gr_Consent` 门控，回调 `\Throwable` 隔离上报 `gr_adapter_error`。加载纪律：`Gr_Plugin::register_hooks()` 中 `class_exists('WooCommerce', false)` **先于**适配器类引用，无目标站点不加载适配器文件。实测注：Woo 自带的 Store API 监听器对 null request 会 fatal（真 Store API 恒传 request 对象）；探针/集成测试必须传真对象。

### 2.7 失败开放（Fail-Open），且明确边界
- 分析/归因/行为链路：任何异常 → 记录日志、静默跳过，绝不影响前台渲染。
- 安全拦截链路：同样失败开放，但**默认仅记录不拦截**（见 `10-security-engineering.md` §4 的拦截成熟度模型）。
- **明确不做的**：wp-plug 文档中 `-9999` 优先级卸载密码校验之类的"性能技巧"，永远禁止。

## 3. 目录结构（与 `04-naming-and-coding-standards.md` §2 一致，此处给职责）

| 目录 | 职责 | 禁令 |
| :--- | :--- | :--- |
| `plugin/includes/core/` | Plugin 主控、Autoloader、Event DTO、Database 表名解析、Secrets 加密、Http_Client（熔断+超时）、**Gr_Queue** | 不含业务逻辑 |
| `plugin/includes/security/` | IP 解析、CIDR、允许/封禁、FCrDNS、蜜罐（含时间差）、登录保护、浪涌折叠日志、UA 引擎（本地数据文件）、Blackhole 陷阱、威胁规则（默认仅记录） | 不直接 echo |
| `plugin/includes/attribution/` | UTM/点击 ID 解析、触点持久化、5 种归因模型计算 | 不出网 |
| `plugin/includes/funnel/` | 漏斗状态机、目标、A/B 分流与 Z 检验、弃购捕获 | 弃购邮件默认关闭 |
| `plugin/includes/behavior/` | 停留/滚动/怒点计算、线索评分、RFM、用户质量 | 纯计算，不碰 $_POST |
| `plugin/includes/integrations/` | capi/（GA4、Meta、TikTok、Webhook）+ ecosystem/（WooCommerce、表单插件适配器）+ geoip/（DB-IP Lite 本地查询） | 全部 opt-in（GeoIP 查询本地零外呼） |
| `plugin/includes/rest/` | REST 控制器，一个资源一个类 | 禁止 `__return_true`（collect 除外） |
| `plugin/includes/privacy/` | 同意门控、IP 匿名化（营销轨）、导出/擦除回调 | — |
| `plugin/includes/storage/` | 每表一个 Repository；表结构迁移 | 唯一允许 `$wpdb` 的目录 |
| `plugin/admin/` | 菜单、20 页渲染、WP_List_Table 子类、Settings API | 视图禁止查询数据库 |

## 4. 数据流（一次带 UTM 的访问 → 归因 → 转化）

```
访客请求 /?utm_source=google&utm_medium=cpc&gclid=xxx
  │
  ├─ plugins_loaded@10   GR_Plugin 构造服务；允许/封禁列表读 L1 静态缓存
  ├─ init@10             Security_Request_Inspector：默认仅记录模式
  ├─ template_redirect@10 Attribution_Listener：
  │     解析 UTM/gclid → 同意门控检查（wp_has_consent('marketing')）
  │     → 通过：写 gr_attr cookie（签名 visitor_id，30 天，HttpOnly，SameSite=Lax）
  │       并以 visitor_id 落触点行（跨天多触点归因的主链路）
  │     → 无 cookie/无同意：会话身份回退为每日旋转盐哈希（无跨天关联，ADR-0007）
  ├─ wp_enqueue_scripts  注册 gr-probe.js（defer）
  │     • 安全模块（v1.0，默认开）：自动化环境信号 → bot_score
  │     • 行为模块（v1.1，同意门控）：停留/滚动/怒点
  │     • 均经 sendBeacon → POST greenpng/v1/collect（bot_score 仅结论值）
  │
  ... 用户下单 ...
  │
  └─ woocommerce_payment_complete  WooCommerce_Adapter（Throwable 隔离）
        → 幂等检查（$order->get_meta('_gr_attributed')；HPOS 安全写法
          wc_get_order + update_meta_data + save，双轨通用）
        → 读 gr_attr cookie 的 visitor_id → Attribution_Service 计算 5 模型
        → Conversion_Repository 写入（UNIQUE 键防重）
        → 若站长启用了 Meta CAPI：Gr_Queue::enqueue('gr_capi_dispatch')
              →（AS 或 WP-Cron 后端）异步任务中 Http_Client（5s 超时+熔断）
                发出，失败按 30s/2m/15m 退避重试 3 次后放弃并记录
```

> 落地形态（2026-09-10，C7 实装）：监听器 = `Gr_Attribution_Listener`（`template_redirect@10`）。门控两级——`attribution_enabled` 管所有营销写（cookie、触点、会话落地属性），`Gr_Consent::allows('marketing')`（DNT/Sec-GPC 一票否决 → Consent API → 回落开关）为同意检查点；**技术性会话滑动（last_active/pageviews）不经门控**（在线数为聚合计数）。参数解析 = `Gr_Attribution_Params` 纯函数（utm 五键 + 六种点击 ID 择一，渠道闭合词表：点击 ID 定 cpc/social → utm_medium 词表 → 外部引荐把 direct 升 referral（自家域排除在监听器）→ 兜底 direct/other）；`gr_parse_attribution_params()` 门面已就位（C8 仅剩 5 模型计算）。触点 = `Gr_Touchpoint_Repository`（列宽截断 + `get_for_visitor` 显式 UTC 窗口 + visitor_time 序读）。`Gr_Identity` 增请求内备忘：`issue()` 后同请求读 `visitor_id()/session_id()` 即见 cookie 轨身份（`$_COOKIE` 下一请求才回显，否则入口触点会落到每日回退轨、跨天断裂——实测修复项）。无同意路径实测：零 cookie、零触点、会话行仅技术字段。

## 5. 部署与打包

- 仓库内 `plugin/` 目录即发布内容；`tools/build-zip.sh` 产出上传包（排除 `vendor/`（dev）、测试、文档）。
- 版本号三处同步：`greenpng.php` 头、`GR_VERSION`、`readme.txt` 的 `Stable tag`；由 `tools/bump-version.sh` 保证。
- 语言包走 translate.wordpress.org；仓库只提交 `languages/greenpng.pot`。
- 随包数据文件（CrawlerDetect 规则种子、DB-IP Lite 国家库）在包内有 NOTICE/归属声明（`08` §8）。

## 6. 明确废弃的 wp-plug 架构元素

| 元素 | 废弃理由 |
| :--- | :--- |
| PSR-11 容器 + 反射自动装配 | 无消费者、纯开销、有循环检测缺陷 |
| 自建事件总线 + 中间件管道 | 重复 WP 原生机制，中间件从未实现 |
| 六层"企业级"分层 | 三层已能命名每个类；层次多不等于质量好 |
| Pre-WAF（优先级 0 exit + 内存子串匹配封禁文件） | 危险且有正确性缺陷 |
| 独立 collect.php | 审核与兼容性雷区 |
| `uploads/` 下写运行时状态（Koko 式缓冲文件） | 主机兼容性差，可能被备份同步（iss/ 提议，评审见 `14` §4） |
| 端云双轨 / License / 熔断云端 | 免费版无云端（ADR-0002） |
