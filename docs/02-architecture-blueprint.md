# 02. 架构蓝图 (Architecture Blueprint)

> **本文档是 greenpng 的唯一架构真源。** 与参考项目 wp-plug 的六层企业架构的差异及理由见 `01-wp-plug-analysis-and-assessment.md` §5.2 与 ADR-0003。

---

## 1. 架构总览：三层 + 两个横切面

```
┌────────────────────────────────────────────────────────────────┐
│                        WordPress 核心                          │
│   do_action / apply_filters / REST / WP-Cron / WP_List_Table   │
└──────────────┬─────────────────────────────┬───────────────────┘
               │                             │
┌──────────────▼──────────────┐   ┌──────────▼───────────────────┐
│   采集层 (Collect)          │   │   管理后台 (Admin)            │
│   • WP 钩子监听             │   │   • 19 页，WP 原生组件        │
│   • REST 采集端点 (1个)     │   │   • WP_List_Table + form-table│
│   • 前台探针 gr-probe.js    │   │   • Settings API              │
│   • 生态插件适配器          │   │   • REST 读接口 (greenpng/v1) │
└──────────────┬──────────────┘   └──────────┬───────────────────┘
               │  gr_event（do_action + DTO）  │  只读仓储
┌──────────────▼─────────────────────────────▼───────────────────┐
│                     领域层 (Domain)                             │
│   Security │ Attribution │ Funnel │ Behavior/CRM │ Integrations │
│   每个域是纯 PHP 类集合：输入数组/DTO → 计算 → 仓储写入          │
└──────────────┬─────────────────────────────────────────────────┘
               │
┌──────────────▼─────────────────────────────────────────────────┐
│                     存储层 (Storage)                            │
│   Repository 类（每表一个，唯一允许碰 $wpdb 的地方）            │
│   gr_* 自定义表 + 1 个 autoload 设置项 + WP-Cron 任务           │
└─────────────────────────────────────────────────────────────────┘

横切面 A：Observability —— 结构化日志、健康检查（只读诊断，不外发）
横切面 B：Privacy —— 同意门控、IP 匿名化、WP 隐私 API 导出/擦除
```

## 2. 关键架构决策（每条附理由）

### 2.1 不要 DI 容器，显式构造
`GR_Plugin`（`class-gr-plugin.php`）在 `plugins_loaded` 优先级 10 显式构造约 8~12 个服务并注入构造函数。
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

### 2.3 仓储模式隔离 $wpdb
- 只有 `includes/storage/` 下的 Repository 类允许出现 `$wpdb`。
- 领域层只面对 Repository 的方法签名（返回数组或 DTO），可脱离 WordPress 单测。
- 表名统一由 `GreenPNG\Core\Database::table('security_logs')` 解析。

### 2.4 异步只用 WP-Cron + 单次事件，不打包 Action Scheduler（v1）
- 出网 CAPI、邮件挽回等延迟任务：`wp_schedule_single_event()`。
- 日常维护（汇总、瘦身）：单一每日钩子 `gr_cron_daily_maintenance`。
- 提供 WP-CLI 命令 `wp greenpng maintenance` 供真实系统 cron 驱动，并在设置页引导禁用 WP-Cron 伪定时。
**理由**：Action Scheduler 带来 4 张表、GPLv3 传染、全站版本协商问题，换 v1 的 3 个后台任务不值得。若 v2 出现"必须可靠重试的出网队列"，再按 ADR 流程重新评估。

### 2.5 前台采集端点：一个 REST 路由，签名令牌 + 限流
```
POST /wp-json/greenpng/v1/collect
```
- `permission_callback => '__return_true'`（公开端点），但三重防护：
  1. **旋转日盐令牌**：服务端每日派生令牌，经 `wp_localize_script` 注入页面；验证用 `hash_equals`。令牌不是秘密，作用是抬高脚本批量灌水的成本并支持按天失效。
  2. **每 IP 限流**：对象缓存优先、transient 兜底（短 TTL，不长驻）。
  3. **严格 schema + 8KB body 上限**：REST `args` 校验全部字段。
- `nocache_headers()` + 对缓存插件声明不缓存（`data-no-optimize` 等属性）。
- 探针用 `navigator.sendBeacon(url, new Blob([json], {type:'application/json'}))`，保证 WP REST 能解析 JSON body。
**理由**：wp-plug 的独立 `collect.php`（绕过 WP 引导）在审核与主机兼容性上都是雷区；无防护的公开写端点是数据库灌水通道。

### 2.6 模块加载：零闲置成本
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
（继承自 wp-plug 文档 27 的三原则，这是该档案中质量最高的部分。）

### 2.7 失败开放（Fail-Open），且明确边界
- 分析/归因/行为链路：任何异常 → 记录日志、静默跳过，绝不影响前台渲染。
- 安全拦截链路：同样失败开放，但**默认仅记录不拦截**（见 `10-security-engineering.md` §4 的拦截成熟度模型）。
- **明确不做的**：wp-plug 文档中 `-9999` 优先级卸载密码校验之类的"性能技巧"，永远禁止。

## 3. 目录结构（与 `04-naming-and-coding-standards.md` §2 一致，此处给职责）

| 目录 | 职责 | 禁令 |
| :--- | :--- | :--- |
| `plugin/includes/core/` | Plugin 主控、Autoloader、Event DTO、Database 表名解析、Secrets 加密、Http_Client（熔断+超时） | 不含业务逻辑 |
| `plugin/includes/security/` | IP 解析、CIDR、允许/封禁、FCrDNS、蜜罐、登录保护、浪涌折叠日志、威胁规则（默认仅记录） | 不直接 echo |
| `plugin/includes/attribution/` | UTM/点击 ID 解析、触点持久化、5 种归因模型计算 | 不出网 |
| `plugin/includes/funnel/` | 漏斗状态机、目标、A/B 分流与 Z 检验、弃购捕获 | 弃购邮件默认关闭 |
| `plugin/includes/behavior/` | 停留/滚动/怒点计算、线索评分、RFM、用户质量 | 纯计算，不碰 $_POST |
| `plugin/includes/integrations/` | capi/（GA4、Meta、TikTok、Webhook）+ ecosystem/（WooCommerce、表单插件适配器） | 全部 opt-in |
| `plugin/includes/rest/` | REST 控制器，一个资源一个类 | 禁止 `__return_true`（collect 除外） |
| `plugin/includes/privacy/` | 同意门控、IP 匿名化、导出/擦除回调 | — |
| `plugin/includes/storage/` | 每表一个 Repository；表结构迁移 | 唯一允许 `$wpdb` 的目录 |
| `plugin/admin/` | 菜单、19 页渲染、WP_List_Table 子类、Settings API | 视图禁止查询数据库 |

## 4. 数据流（一次带 UTM 的访问 → 归因）

```
访客请求 /?utm_source=google&utm_medium=cpc&gclid=xxx
  │
  ├─ plugins_loaded@10   GR_Plugin 构造服务；允许列表/封禁列表读 L1 静态缓存
  ├─ init@10             Security_Request_Inspector：默认仅记录模式
  ├─ template_redirect@10 Attribution_Listener：
  │     解析 UTM/gclid → 同意门控检查（wp_has_consent('marketing')）
  │     → 通过则 setcookie('gr_attr', 签名值, 服务端第一方, SameSite=Lax)
  │     → Session_Repository 写入触点行
  ├─ wp_enqueue_scripts  注册 gr-probe.js（defer；仅当站长启用了行为追踪）
  │
  ... 用户下单 ...
  │
  └─ woocommerce_payment_complete  WooCommerce_Adapter（Throwable 隔离）
        → 幂等检查（$order->get_meta('_gr_attributed')，HPOS 安全写法）
        → 读 gr_attr cookie → Attribution_Service 计算 first/last touch
        → Conversion_Repository 写入（UNIQUE 键防重）
        → 若站长启用了 Meta CAPI：wp_schedule_single_event(+0, 'gr_capi_dispatch')
              → 异步任务中 Http_Client（5s 超时+熔断）发出，失败按 30s/2m/15m 退避重试 3 次后放弃并记录
```

## 5. 部署与打包

- 仓库内 `plugin/` 目录即发布内容；`tools/build-zip.sh` 产出上传包（排除 `vendor/`（dev）、测试、文档）。
- 版本号三处同步：`greenpng.php` 头、`GR_VERSION`、`readme.txt` 的 `Stable tag`；由 `tools/bump-version.sh` 保证。
- 语言包走 translate.wordpress.org；仓库只提交 `languages/greenpng.pot`。

## 6. 明确废弃的 wp-plug 架构元素

| 元素 | 废弃理由 |
| :--- | :--- |
| PSR-11 容器 + 反射自动装配 | 无消费者、纯开销、有循环检测缺陷 |
| 自建事件总线 + 中间件管道 | 重复 WP 原生机制，中间件从未实现 |
| 六层"企业级"分层 | 三层已能命名每个类；层次多不等于质量好 |
| Pre-WAF（优先级 0 exit + 内存子串匹配封禁文件） | 危险且有正确性缺陷 |
| 独立 collect.php | 审核与兼容性雷区 |
| `uploads/` 下写运行时状态 | 主机兼容性差，可能被备份同步 |
| 端云双轨 / License / 熔断云端 | 免费版无云端（ADR-0002） |
