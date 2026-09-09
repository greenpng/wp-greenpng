# COMPAT-01: PHP 与 WordPress 双向兼容工程规范白皮书

> **文档定位**：针对本项目专注于**纯免费开源插件**的战略定位，彻底解决既有老站点（老版本 PHP 7.4 / WP 6.0）与未来现代站点（PHP 8.2~8.5+ / WP 7.1+）的**双向兼容工程难题**。  
> **核心原则**：向下兼容不产生语法错误，向上兼容不产生废弃警告与运行时异常；零外部依赖，纯本地原生实现。

---

## 一、 为什么必须做“双向兼容”？

在 WordPress 官方插件生态中，免费插件的安装基数极大：
1. **向下兼容（兼容老用户）**：
   - 仍有约 15%~20% 的长尾独立站运行在 PHP 7.4 与 WordPress 6.0~6.2 环境（受限于虚拟主机或老旧模板）。如果插件出现 PHP 8+ 特有语法（如 `match`、`readonly`），这些站点在激活插件瞬间就会发生 **PHP Parse Error (语法解析错误)**，导致白屏或致命崩溃。
2. **向上兼容（兼容现代与未来版本）**：
   - 当前主流云主机（WP Engine、Kinsta、SiteGround）已默认采用 PHP 8.2 / 8.3，且测试环境已演进至 **PHP 8.5** 与 **WordPress 7.1**；
   - PHP 8.1~8.4 引入了极其严格的类型校验和运行时废弃策略（如动态属性废弃、内部函数传 null 报错等）。如果不做防御性设计，插件在现代站点上会产生海量 Deprecated 告警撑爆日志，甚至在未来的 PHP 9.0 变为 Fatal Error。

---

## 二、 PHP 7.4 ~ PHP 8.5+ 运行时代码级兼容规范

### 1. 动态属性防御（PHP 8.2+ 关键致命坑点）

从 PHP 8.2 起，任何未在类定义中显式声明的属性被直接赋值，都会触发 `E_DEPRECATED: Creation of dynamic property is deprecated`。

#### 错误写法（PHP 7.4 常见坏习惯）：
```php
class Gr_Event {
    // 没声明 $custom_meta
}
$event = new Gr_Event();
$event->custom_meta = 'lead_captured'; // PHP 8.2+ 触发 Deprecated 告警！
```

#### 标准双向兼容写法：
在所有核心 DTO、数据模型基类中，必须统一使用显式属性声明，或者在容器/配置类上显式标记原生属性注解：
```php
// includes/core/class-gr-base-model.php
namespace GreenPNG\Core;

#[\AllowDynamicProperties] // 允许动态属性注解：PHP 8.0/8.1/8.2+ 原生解析，PHP 7.4 视为普通注释（完全兼容！）
abstract class Base_Model {
    /** @var array 明确的属性字典 */
    protected $data = array();

    public function __get(string $key) {
        return isset($this->data[$key]) ? $this->data[$key] : null;
    }

    public function __set(string $key, $value): void {
        $this->data[$key] = $value;
    }

    public function __isset(string $key): bool {
        return isset($this->data[$key]);
    }
}
```
> **原理**：`#[\AllowDynamicProperties]` 采用 PHP 8 的属性语法，但在 PHP 7.4 中由于以 `#` 开头，被解释器直接作为单行注释跳过，**天然实现跨版本双向静默兼容**！

---

### 2. 内部函数传 Null 严格校验（PHP 8.1+ 破坏性变更）

PHP 7.4 中 `strlen(null)`、`trim(null)`、`strpos(null, 'a')` 会隐式将 null 转为 `""`；在 PHP 8.1+ 会触发 `Deprecated: Passing null to parameter ... is deprecated`。

#### 标准双向防御函数库：
在 `includes/core/compat-functions.php` 中封装安全门面：
```php
if (!function_exists('gr_safe_strlen')) {
    function gr_safe_strlen($str): int {
        return strlen((string) ($str !== null ? $str : ''));
    }
}

if (!function_exists('gr_safe_trim')) {
    function gr_safe_trim($str): string {
        return trim((string) ($str !== null ? $str : ''));
    }
}

if (!function_exists('gr_str_contains')) {
    function gr_str_contains(string $haystack, string $needle): bool {
        if ($needle === '') {
            return true;
        }
        if (function_exists('str_contains')) {
            return str_contains($haystack, $needle); // PHP 8+ 走极速 C 底层
        }
        return strpos($haystack, $needle) !== false; // PHP 7.4 安全回落
    }
}

if (!function_exists('gr_str_starts_with')) {
    function gr_str_starts_with(string $haystack, string $needle): bool {
        if (function_exists('str_starts_with')) {
            return str_starts_with($haystack, $needle);
        }
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
```

---

### 3. 可数类型 `count()` 防御

在 PHP 7.4~8.5 中对非 Countable 对象或 null 执行 `count($var)` 会产生 Warning。必须统一使用：
```php
$total = (is_array($items) || $items instanceof \Countable) ? count($items) : 0;
```

---

### 4. 避免使用 `match` 与 `enum`

- 禁止使用 PHP 8.0 `match` 表达式，统一使用经过类型约束的 `switch...case`；
- 禁止使用 PHP 8.1 `enum`，统一使用常量类：
```php
final class Gr_Rule_Action {
    const ALLOW  = 'allow';
    const BLOCK  = 'block';
    const LOG    = 'logged';
    
    private function __construct() {} // 防止实例化
}
```

---

## 三、 WordPress 6.0 ~ WordPress 7.1+ 核心版本双向兼容规范

### 1. WooCommerce 订单数据存储双轨兼容（HPOS vs 经典 wp_posts）

从 WooCommerce 8.2 起，官方推出了高性能订单表 HPOS（Custom Order Tables），订单数据不再存入 `wp_posts` 和 `wp_postmeta`，而是存入 `wp_wc_orders`。

#### 参考 `handl-utm-grabber` 的黄金标准兼容写法：
```php
// includes/integrations/ecosystem/class-gr-woocommerce-adapter.php
namespace GreenPNG\Integrations\Ecosystem;

final class WooCommerce_Adapter {
    public static function is_hpos_active(): bool {
        return class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    public static function save_order_meta(int $order_id, string $key, $value): void {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        
        if (self::is_hpos_active() && $order) {
            // HPOS 现代路径：使用订单对象抽象
            $order->update_meta_data($key, sanitize_text_field($value));
            $order->save();
        } else {
            // 经典传统路径：兼容老版本 WooCommerce 与老 WP 站点
            update_post_meta($order_id, $key, sanitize_text_field($value));
        }
    }
}
```

---

### 2. 古腾堡现代区块结账（React Blocks）与经典短码结账双挂载

现代 WooCommerce 默认启用 React Blocks 结账页，传统的 `woocommerce_checkout_update_order_meta` 钩子不会被触发！

#### 双挂载标准模式：
```php
public function register_hooks(): void {
    // 1. 经典短码结账页钩子（传递 order_id）
    add_action('woocommerce_checkout_update_order_meta', array($this, 'on_classic_checkout'), 10, 1);

    // 2. 现代 React Blocks 结账页钩子（传递 order 对象，Store API）
    add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'on_store_api_checkout'), 10, 2);

    // 3. 终态支付成功钩子（幂等防重锁）
    add_action('woocommerce_payment_complete', array($this, 'on_payment_complete'), 10, 1);
}

public function on_store_api_checkout($order, $request): void {
    if (is_object($order) && method_exists($order, 'get_id')) {
        $this->on_classic_checkout($order->get_id());
    }
}
```

---

### 3. 数据表创建 `dbDelta()` 语法红线（兼顾 MySQL 5.7/8.0、MariaDB 10/12 与 SQLite）

`wp-admin/includes/upgrade.php` 的 `dbDelta()` 函数对 SQL 语法有极其死板的格式要求，稍有不慎就会导致字段更新失败或索引重复创建：
- **每个字段必须独占一行**；
- **`PRIMARY KEY` 必须有两个空格**：`PRIMARY KEY  (id)`（官方 upgrade.php 正则强制要求）；
- **所有关键字必须大写**（`NOT NULL DEFAULT 0`）；
- **索引字符列长度 ≤ 191**（防止 MySQL 5.7 utf8mb4 键长度超过 767 字节溢出报错）；
- **严禁在建表语句中使用外键约束（FOREIGN KEY）**（`dbDelta` 不支持，且在跨库迁移时极易报错）。

---

### 4. 国际化语言包即时加载（JIT i18n 兼容）

- **WP 6.5 以前**：必须显式调用 `load_plugin_textdomain('greenpng', false, dirname(plugin_basename(__FILE__)) . '/languages');`；
- **WP 6.5+ 起**：WordPress 引入了即时翻译加载（Just-in-Time Translation Loading），若过早调用 `load_plugin_textdomain` 会触发 `_doing_it_wrong` 告警；
- **兼容策略**：统一挂载在 `init` 钩子优先级 10 执行，同时满足老版本加载与新版本合规。

---

## 四、 本地与 CI 自动化兼容性保障机制

| 检验环节 | 工具与手段 | 校验目标 |
| :--- | :--- | :--- |
| **PHP 7.4 语法底线** | `PHPCompatibilityWP` (testVersion 7.4-) | 确保不包含任何导致 PHP 7.4 发生 Parse Error 的语法 |
| **PHP 8.2+ 动态属性** | `phpstan` level 6 | 静态检查全仓对象赋值，防止动态属性 Deprecated 发生 |
| **真实运行时测试** | 本机 MariaDB + FrankenPHP (PHP 8.5) 实测 | 确保最新版本 PHP 与 WP 7.1 无任何警告与异常 |
| **低版本容器测试** | GitHub Actions 矩阵（PHP 7.4/8.0/8.2 × WP 6.0/latest） | 双向确保老站点与新站点 100% 顺畅激活与运行 |
