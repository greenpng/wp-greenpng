# 04. 命名规范与编码规范 (Naming & Coding Standards)

> **强制级别**：全部条目为强制。代码评审以本文件为准。
> **适用范围**：`plugin/` 下所有 PHP、JS、CSS、SQL、模板文件。
> **修订记录**：2026-09-09 依据 ADR-0007——新增 §3.11 双向兼容规范（PHP 7.4~8.5+ / WP 6.0~7.1+）；修正 §8 红线表（`str_*` 三函数因 WP 核心 polyfill 解禁）。

---

## 1. 前缀总表（唯一真源）

**唯一允许的前缀是 `gr` / `GR_` / `greenpng`。** 任何其他前缀（尤其是 `agy` / `AGY`）一律不得出现。

| 类别 | 规则 | 正确示例 | 错误示例 |
| :--- | :--- | :--- | :--- |
| 插件 slug / 目录 | `greenpng` | `plugin/greenpng.php` | `gr-suite.php` |
| Text Domain | `greenpng`（必须等于 slug） | `__('Blocked', 'greenpng')` | `__('Blocked', 'gr')` |
| PHP 常量 | `GR_` + 大写下划线 | `GR_VERSION`、`GR_PLUGIN_DIR` | `GREENPNG_VERSION` |
| PHP 命名空间 | 根 `GreenPNG\` | `GreenPNG\Security\Firewall` | `Gr\Security\Firewall` |
| 全局函数 | `gr_` + 小写下划线 | `gr_get_client_ip()` | `greenpng_get_client_ip()`` |
| 类文件名 | `class-gr-<slug>.php` | `class-gr-firewall.php` | `Firewall.php` |
| 数据表 | `{$wpdb->prefix}gr_<名词复数>` | `wp_gr_security_logs` | `wp_greenpng_logs` |
| Option 键 | `gr_<模块>_<字段>` | `gr_security_settings` | `security_settings` |
| Transient 键 | `gr_<用途>_<标识>` | `gr_fcrdns_<hash>` | `fcrdns_<hash>` |
| User meta 键 | `_gr_<字段>`（下划线开头=隐藏） | `_gr_lead_score` | `gr_lead_score` |
| Post meta 键 | `_gr_<字段>` | `_gr_ab_variant` | `ab_variant` |
| Cookie 名 | `gr_<用途>` | `gr_attr`、`gr_vid` | `greenpng_attribution` |
| Action 钩子 | `gr_<主语>_<动词过去式>` | `gr_request_blocked` | `gr_block` |
| Filter 钩子 | `gr_<被过滤对象>` | `gr_ip_risk_score` | `gr_filter_score` |
| REST 命名空间 | `greenpng/v1` | `/wp-json/greenpng/v1/security/logs` | `/wp-json/gr/v1/...` |
| Cron 钩子 | `gr_cron_<任务>` | `gr_cron_prune_logs` | `gr_daily` |
| 管理页 slug | `greenpng` / `greenpng-<模块>` | `greenpng-security` | `gr_security` |
| Nonce action | `gr_<操作>` | `gr_save_security_settings` | `save_settings` |
| CSS 类 | `gr-<块>__<元素>--<修饰>` | `gr-kpi-card__value--danger` | `agy-card` |
| CSS 变量 | `--gr-<用途>` | `--gr-color-danger` | `--danger` |
| JS 全局 | `window.GreenPNG`（单一入口） | `window.GreenPNG.DataGrid` | `window.GrDataGrid` |
| `wp_localize_script` 对象 | `grData` | `grData.restUrl` | `gr_vars` |
| DOM id | `gr-<页面>-<组件>` | `gr-security-logs-grid` | `logs-grid` |
| 资源文件名 | `gr-<用途>.<ext>` | `gr-admin.css`、`gr-probe.js` | `admin.css` |
| 缓存组 | `greenpng` | `wp_cache_get($k, 'greenpng')` | `wp_cache_get($k, 'gr')` |

### 1.1 为什么函数用 `gr_` 而常量用 `GR_`
`gr_` 作为全局函数前缀足够短且在 WordPress 生态中未被占用（已核对 wp-plug 下 226 个插件，无冲突）。常量用 `GR_` 与 WordPress 核心（`WP_`、`ABSPATH`）风格一致。

### 1.2 命名空间为什么用 `GreenPNG\` 而不是 `Gr\`
两字符命名空间过短，与他人插件碰撞风险高，且不可读。`GreenPNG\` 唯一且自描述。**函数前缀短、命名空间长**是有意的分工。

---

## 2. 目录与文件结构

```
plugin/                                  # 插件根，打包时此目录内容即 ZIP 内容
├── greenpng.php                         # 唯一入口：头注释、常量、autoloader、boot
├── readme.txt                           # WordPress.org readme（含 External services 段）
├── uninstall.php                        # 卸载清理（必须存在）
├── LICENSE                              # GPLv2
├── includes/
│   ├── gr-functions.php                 # 全局过程函数门面（薄封装，禁止写业务逻辑）
│   ├── class-gr-autoloader.php          # 手写 autoloader
│   ├── class-gr-plugin.php              # 主控：注册钩子，不含业务逻辑
│   ├── class-gr-activator.php           # 激活/升级：dbDelta、默认 option、cron 注册
│   ├── class-gr-deactivator.php         # 停用：清 cron，不删数据
│   ├── core/                            # 基础设施：DB、缓存、日志、加密、HTTP、队列
│   ├── security/                        # 域：流量安全（含 UA 引擎数据文件）
│   ├── attribution/                     # 域：营销归因
│   ├── funnel/                          # 域：转化漏斗
│   ├── behavior/                        # 域：行为与 CRM 评分
│   ├── integrations/                    # 域：第三方与生态集成
│   │   ├── capi/                        #   出网 CAPI
│   │   ├── geoip/                       #   DB-IP Lite 本地查询
│   │   └── ecosystem/                   #   WP 插件桥接
│   ├── rest/                            # REST 控制器，一个资源一个类
│   └── privacy/                         # WP 隐私 API 导出/擦除
├── admin/
│   ├── class-gr-admin-menu.php          # 菜单注册（唯一处）
│   ├── views/                           # 模板：只做 echo + esc_*，禁止查询
│   ├── list-tables/                     # WP_List_Table 子类
│   └── settings/                        # register_setting + sanitize 回调
├── assets/
│   ├── css/  (gr-admin.css, gr-front.css)
│   ├── js/   (gr-admin.js, gr-probe.js)
│   ├── data/ (geoip/ DB-IP 国家库文本数据 + NOTICE)
│   └── vendor/                          # 第三方前端库 + 同名未压缩源码
└── languages/
    └── greenpng.pot
```

### 2.1 文件结构强制约束
- **一个文件一个类**，文件名 = `class-gr-` + 类名小写、下划线转连字符。
  例：`GreenPNG\Security\Login_Protection` → `includes/security/class-gr-login-protection.php`
- `includes/gr-functions.php` **只允许**放薄门面（≤ 3 行转发）。业务逻辑一律在类里。
- `admin/views/` 下的模板**禁止**出现 `$wpdb`、`get_option`、`wp_remote_*`。数据由控制器准备好后传入。
- 每个 PHP 文件第一行是 `<?php`，紧接 `declare(strict_types=1);`，然后 `namespace`，然后：
  ```php
  if (!defined('ABSPATH')) {
      exit;
  }
  ```

---

## 3. PHP 编码规范

基线：**WordPress Coding Standards (WPCS)**，由 `phpcs.xml.dist` 强制。以下为本项目额外收紧或放宽的条目。

### 3.1 与 WPCS 的差异（本项目决定）
| 项 | WPCS 默认 | greenpng | 理由 |
| :--- | :--- | :--- | :--- |
| 缩进 | Tab | **4 空格** | 与 JS/CSS 统一，diff 更稳定 |
| `declare(strict_types=1)` | 不要求 | **必须** | 消除隐式类型转换类 bug |
| 命名空间 | 不鼓励 | **必须** | 域隔离，避免类名冲突 |
| Yoda 条件 | 要求 | **不要求** | 有 `strict_types` 后无必要，可读性优先 |
| 类命名 | `Class_Name` | **`Class_Name`（保留）** | 与 WP 生态一致 |
| 文件命名 | `class-name.php` | **`class-gr-name.php`** | 前缀化，避免与他人插件同名 |

### 3.2 类型声明
- 所有方法必须有参数类型与返回类型。无返回值写 `: void`。
- **PHP 7.4 禁止 `mixed`**。需要任意类型时省略类型声明，并在 docblock 用 `@param mixed`。
- 属性必须声明类型（PHP 7.4 支持 typed properties）。

```php
// 正确
public static function match(string $ip, string $cidr): bool { }
private int $retry_count = 0;

/**
 * @param mixed $payload
 */
public static function extract($payload): array { }

// 错误：mixed 是 PHP 8.0 语法
public static function extract(mixed $payload): array { }
```

### 3.3 数据库访问
- **所有** SQL 走 `$wpdb->prepare()`。唯一例外：完全无变量的静态 SQL。
- 表名通过 `GreenPNG\Core\Database::table('security_logs')` 获取，返回 `$wpdb->prefix . 'gr_security_logs'`。**禁止**在业务代码里手写表名字符串。
- `IN (...)` 占位符必须动态生成：
  ```php
  $placeholders = implode(',', array_fill(0, count($ids), '%d'));
  $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids);
  ```
- 建表**只能**在 `class-gr-activator.php` 里，**只能**用 `dbDelta()`，并带 `$wpdb->get_charset_collate()`。
- 每张表必须有 `gr_db_version` 版本号驱动的升级例程。
- **禁止**运行时 DDL（`CREATE` / `ALTER` / `OPTIMIZE` / `TRUNCATE`）。

### 3.4 输入与输出
```php
// 输入：读取即消毒，且必须先做 nonce + 能力校验
$ip = isset($_POST['ip']) ? sanitize_text_field(wp_unslash($_POST['ip'])) : '';

// 输出：按上下文选转义函数
echo esc_html($label);                          // 文本节点
echo '<a href="' . esc_url($url) . '">';        // URL
echo '<input value="' . esc_attr($value) . '">';// 属性
echo wp_kses_post($rich_text);                  // 允许受限 HTML
```
- **禁止** `echo $var` 不转义。
- **禁止** JS 里 `innerHTML = \`...${data}...\``。用 `textContent`，或先经 `escapeHtml()` 工具函数。
  这是 wp-plug 原型的实际缺陷：User-Agent、请求 payload 等攻击者可控数据被直接拼进 `innerHTML`，构成后台存储型 XSS。

### 3.5 能力与 nonce（成对出现，缺一不可）
```php
// 表单提交
if (!current_user_can('manage_options')) {
    wp_die(esc_html__('Insufficient permissions.', 'greenpng'));
}
check_admin_referer('gr_save_security_settings');

// REST 路由
register_rest_route('greenpng/v1', '/security/logs', [
    'methods'             => WP_REST_Server::READABLE,
    'callback'            => [$this, 'get_logs'],
    'permission_callback' => static function () {
        return current_user_can('manage_options');
    },
    'args'                => [ /* 每个参数都要 sanitize_callback + validate_callback */ ],
]);
```
- `permission_callback` **禁止**写 `__return_true`。
- 所有接口统一走 REST。**禁止**新增 `wp_ajax_*`。

### 3.6 国际化
- text domain 固定 `greenpng`，**必须是字面量**，不得用常量或变量。
- 带变量的文案用 `printf` + 占位符，禁止字符串拼接：
  ```php
  // 正确
  printf(
      /* translators: %s: IP address */
      esc_html__('Blocked IP %s.', 'greenpng'),
      esc_html($ip)
  );

  // 错误
  echo esc_html__('Blocked IP ', 'greenpng') . $ip;
  ```
- 多个占位符必须用带序号形式 `%1$s` / `%2$d`。
- 复数用 `_n()`。带上下文用 `_x()`。
- 面向开发者的日志/异常消息**不翻译**。

### 3.7 出网请求
```php
$response = GreenPNG\Core\Http_Client::post('meta_capi', $endpoint, $payload);
// 内部：wp_safe_remote_post()，timeout ≤ 5s，熔断 + 退避重试
```
- 业务代码**禁止**直接调用 `wp_remote_*` / `wp_safe_remote_*` / cURL / `file_get_contents`——统一经 `Http_Client`（`07` §2）。
- **禁止**在前台请求生命周期内同步出网。出网经 `Gr_Queue::enqueue()`（自适应 AS / WP-Cron，ADR-0007）。
- 未配置凭据时必须返回明确的"未配置"错误。**禁止**像 wp-plug 原型那样用 `mock_` 默认值让调用静默返回成功。

### 3.8 密钥存储
第三方 API token 不得以明文存入 `wp_options`。统一走 `GreenPNG\Core\Secrets`：
```php
GreenPNG\Core\Secrets::set('meta_capi_token', $token);  // AES-256-GCM，密钥派生自 wp_salt()
$token = GreenPNG\Core\Secrets::get('meta_capi_token');
```
> 说明：这不是绝对安全（同库可读盐），但能避免数据库导出、备份文件、`wp_options` 误查询造成的明文泄露，是行业常规做法。

### 3.9 Option 与自增长数据
- 任何可能持续增长的数据**禁止**放 option。
- 必须显式指定 autoload：`add_option($k, $v, '', 'no')`。
- 全插件 autoload option 总量 ≤ 8 KB。
- 计数器、在线访客集合等高频写入数据**禁止**用"读-改-写单个 transient"模式（wp-plug 原型的 `agy_live_visitors` 就是此错误：并发下丢失更新，且无对象缓存时造成 `wp_options` 写争用）。改用独立表 + `INSERT ... ON DUPLICATE KEY UPDATE`（MySQL 原生，ADR-0007）。

### 3.10 钩子优先级约定
| 优先级 | 用途 |
| :--- | :--- |
| `plugins_loaded` / 1 | 读取允许列表与阻断列表（最早期放行判断） |
| `plugins_loaded` / 5 | 插件 boot |
| `init` / 10 | 常规注册（默认，不要随意提前）；`load_plugin_textdomain`（JIT i18n 兼容，见 §3.11） |
| `template_redirect` / 10 | 前台归因捕获、探针注入 |
| `admin_menu` / 10 | 菜单注册 |
| `shutdown` / 10 | 批量落盘 |

**禁止**使用 `PHP_INT_MIN`、`-9999` 等极端优先级。

### 3.11 双向兼容规范（ADR-0007：PHP 7.4~8.5+ / WP 6.0~7.1+）

**向下兼容（不产生语法错误）**：`mixed` / `match` / `enum` / `readonly` / 构造器属性提升 / 命名参数 / 联合类型 / `?->` 一律禁用（§8、`AGENTS.md` §4）。

**向上防御（不产生废弃警告与运行时异常）**：

1. **动态属性零容忍（PHP 8.2+ 废弃）**：所有类显式声明全部属性；PHPStan level 6 强制零动态属性赋值。`#[\AllowDynamicProperties]` 注解（PHP 7.4 下解析为注释、跨版本安全）**仅允许**用于无法显式声明的边界容器类，并须注释说明原因——首选纪律，不首选注解。
2. **内部函数 null 防御（PHP 8.1+ 废弃）**：输入可能为 null 的内部函数调用前先兜底。提供门面 `gr_safe_strlen($str)` / `gr_safe_trim($str)`（`includes/gr-functions.php`），或调用点先 `(string)` 强转。
3. **`count()` 防御**：`(is_array($items) || $items instanceof \Countable) ? count($items) : 0`。
4. **`str_contains` / `str_starts_with` / `str_ends_with` 允许直接使用**：WP 6.0+ 核心自带 polyfill（`wp-includes/compat.php`，`14` §1 实核），无需自建门面；PHPCompatibilityWP 的 WP 规则集认可（S9 实测收口）。其余 PHP 8 函数仍禁用。
5. **JIT i18n**：`load_plugin_textdomain()` 挂 `init@10`——WP 6.5+ 即时翻译加载合规（过早调用触发 `_doing_it_wrong`），WP 6.0 正常加载。
6. **WooCommerce HPOS 通用写法**：一律 `wc_get_order($id)` + `$order->update_meta_data()` + `$order->save()`（经典/HPOS 双轨通用）；**禁止** `update_post_meta` 分支（多余且随 WC 演进漂移）。
7. **WooCommerce 双结账挂载**：经典 `woocommerce_checkout_update_order_meta` + Blocks `woocommerce_store_api_checkout_update_order_from_request`（WC 11.1 实核存在）+ 终态 `woocommerce_payment_complete`（幂等锁在此）。
8. **dbDelta 纪律**：每字段独占一行且**以逗号分隔**（末行除外——dbDelta 对新表按原文执行 CREATE，无逗号即整表建表失败，MariaDB 12.3 实测）、整数类型带核心同款显示宽度（`BIGINT(20)` / `INT(10)` / `TINYINT(3)`；dbDelta 逐字比对 `DESCRIBE` 输出，MariaDB 全版本与 MySQL <8.0.17 宽度失配即每轮 `CHANGE COLUMN`，MySQL ≥8.0.17 由 dbDelta 显式忽略纯宽度差异）、关键字大写、`PRIMARY KEY  (id)` 双空格（WP 7.1 解析器已放宽为 `\s+`，双空格为 6.0 下界零成本保险）、索引字符列 ≤191、建表语句禁用外键。

## 4. JavaScript 规范

- 原生 ES6+，**不引入** React / Vue / jQuery（WP 已有 jQuery，需要时用 `wp_enqueue_script` 声明依赖，不自带）。
- 严格模式 + IIFE 或 ES module，禁止污染全局。唯一全局入口 `window.GreenPNG`。
- **禁止**内联 `onclick` 属性与内联 `<script>`。全部走 `wp_enqueue_script` + 事件委托。
  wp-plug 原型有 587 处 `onclick=` 与 87 个内联 `<script>`，这是 WordPress.org 审核必然驳回的写法。
- 数据通过 `wp_localize_script('gr-admin', 'grData', [...])` 注入，**禁止**把数据拼进 HTML。
- 所有 REST 请求必须带 nonce：
  ```js
  fetch(grData.restUrl + 'security/logs', {
      headers: { 'X-WP-Nonce': grData.nonce },
  });
  ```
- DOM 写入用 `textContent`。必须写 HTML 时，先过 `GreenPNG.escapeHtml()`。
- 前台探针 (`gr-probe.js`) 硬约束：压缩后 ≤ 8 KB（安全+行为模块合计）、`defer` 加载、只用 `navigator.sendBeacon`、不阻塞渲染、无第三方依赖、**无指纹原始串出客户端**（仅结论值，ADR-0007）。

## 5. CSS 规范

- 后台**优先使用 WordPress 核心类**：`wrap`、`wp-header-end`、`notice notice-*`、`form-table`、`postbox`、`wp-list-table widefat fixed striped`、`button button-primary`、`tablenav`、`row-actions`、`subsubsub`、`screen-reader-text`。
- **禁止**重新实现 WP 核心 CSS（`#wpadminbar`、`#adminmenu`、`.nav-tab`、`.button-primary` 等）。wp-plug 原型每页复制 630 行核心样式并用 `!important` 覆盖，会随 WP 版本升级碎裂。
- **禁止** Tailwind、Bootstrap 及任何 utility-first 框架。
- 自有类用 BEM + `gr-` 前缀：`gr-kpi-card`、`gr-kpi-card__value`、`gr-kpi-card__value--danger`。
- **禁止** `!important`（例外：覆盖第三方库，需注释说明原因）。
- 颜色只用 WordPress 官方调色板 CSS 变量或 `--gr-*` 变量，禁止散落的十六进制字面量。
- 后台样式必须限定在 `.gr-page` 作用域内，避免影响其他插件页面。
- 漏斗流失图（v1.1）用纯 CSS Flexbox 阶梯条 + `dashicons`，不引入 JS 图表库（`06` §2.3）。

## 6. 资源加载规范

```php
// 只在本插件页面加载，禁止全后台加载
public function enqueue_admin(string $hook): void {
    if (strpos($hook, 'greenpng') === false) {
        return;
    }
    wp_enqueue_style('gr-admin', GR_PLUGIN_URL . 'assets/css/gr-admin.css', [], GR_VERSION);
    wp_enqueue_script('gr-admin', GR_PLUGIN_URL . 'assets/js/gr-admin.js', [], GR_VERSION, true);
}
```
- 版本号一律用 `GR_VERSION`，保证缓存失效。
- **禁止**从任何 CDN 加载 JS / CSS / 字体 / 图片（含 Google Fonts、Gravatar 硬编码 `<img>`）。头像用核心 `get_avatar()`。
- 图表库只在真正绘图的页面加载。wp-plug 原型在全部 40 页无条件加载 Chart.js（208 KB）与 Tailwind 运行时（407 KB），其中多页一个图表都没有。
- 打包的第三方压缩库必须同目录附带**同版本未压缩源码**，否则 WordPress.org 审核不通过。
- **随包数据文件**（DB-IP 国家库、UA 引擎规则）放 `assets/data/`，包内 NOTICE 声明来源、许可（CC BY 4.0 / MIT）与数据日期（ADR-0007）。

## 7. 提交前必过检查

```bash
# 语法
find plugin -name '*.php' -print0 | xargs -0 -n1 php -l

# 编码规范
vendor/bin/phpcs --standard=phpcs.xml.dist

# PHP 7.4 兼容性
vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4- plugin/

# 单元测试
vendor/bin/phpunit

# i18n（不应有任何输出）
find plugin -name '*.php' -print0 | xargs -0 grep -n "esc_html__\|__(" | grep -v "'greenpng'"
```

---

## 8. 禁止清单（速查）

| 禁止 | 正确做法 |
| :--- | :--- |
| `mixed` / `match` / `enum` / `readonly` / 构造器属性提升 / 命名参数 / 联合类型 / `?->` | PHP 7.4 等价写法，见 `AGENTS.md` §4。**例外**：`str_contains` / `str_starts_with` / `str_ends_with` 因 WP 6.0+ 核心 polyfill 而**允许**（§3.11.4） |
| 未声明属性的动态赋值 | 显式声明属性（PHP 8.2+ 废弃，§3.11.1） |
| 向内部函数传可能为 null 的参数 | `gr_safe_*` 门面或先强转（§3.11.2） |
| 运行时 `CREATE TABLE` | 只在 activator 里 `dbDelta()` |
| 裸 `$wpdb->query("... $var ...")` | `$wpdb->prepare()` |
| `permission_callback => '__return_true'` | `current_user_can()` |
| 内联 `onclick` / 内联 `<script>` / `alert()` | 事件委托 + `wp_enqueue_script` |
| `innerHTML` 拼接不可信数据 | `textContent` 或 `escapeHtml()` |
| CDN 资源 | 本地打包 |
| 硬编码界面文案 | `__()` + text domain `greenpng` |
| 明文存 API token | `GreenPNG\Core\Secrets` |
| `autoload=yes` 存增长型数据 | 独立表 |
| 读-改-写单 transient 做计数 | 独立表 + MySQL 原子 UPSERT |
| 业务代码直接 `wp_remote_*` 出网 | `Http_Client` + `Gr_Queue`（§3.7） |
| HPOS 场景 `update_post_meta` 分支 | `wc_get_order` + `update_meta_data`（§3.11.6） |
| 对目标插件做版本号锁定 / 样本库式检测 | 公开 Hook/API + 回退告警（铁律 6） |
| 未配置凭据时返回成功 | 返回明确"未配置"错误 |
| 公开无鉴权调试/测试端点 | 删除，或 `current_user_can` + nonce |
| 界面中出现竞品名 / 无实测支撑的数字 | 删除 |
