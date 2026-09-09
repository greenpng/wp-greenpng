# ISS-05: PHP 与 WordPress 双向版本兼容性策略与落地方案

| 严重级别 | 缺陷分类 | 涉及文档 | 状态 |
| :--- : | :---: | :--- | :---: |
| **P1 严重** | 运行时环境 / 编码规范 | `docs/04-naming-and-coding-standards.md` §4<br>`docs/adr/0003-php74-no-composer-runtime.md`<br>`docs/adr/0006-local-test-environment.md` | **已完成双向兼容方案重构** |

---

## 一、 核心诉求明确：老版本与新版本的双向无缝兼容

根据项目在 WordPress.org 官方目录的定位：
> **必须同时兼容老版本（老用户使用的 PHP 7.4、WordPress 6.0）与现代/未来版本（PHP 8.2~8.5+、WordPress 7.1+）！**  
> 老用户不能因为语法升级而白屏崩溃（Fatal Parse Error）；新用户也不能因为过时的 7.4 写法而在 PHP 8.2+ 主机上产生满屏 Deprecated 警告！

同事在 ADR-0003 中单纯采取了“死守 PHP 7.4 语法底线”的单向被动策略，缺乏对高版本环境破坏性变更的防御。本报告给出**双向主动兼容的工程落地方案**（详见配套白皮书 `COMPAT-01`）。

---

## 二、 双向兼容核心技术关卡与同类成熟插件经验

### 1. 向下兼容关卡：防止老站点（PHP 7.4）发生语法白屏

#### 经验借鉴：
翻阅 `/Users/macos/wp-plug/` 目录下流行开源插件（如 `Antispam Bee`、`Limit Login Attempts Reloaded`、`Wordfence`）：
- **绝对不在插件核心文件使用 PHP 8+ 独占语法**：
  - 不使用 `match`（用严谨类型的 `switch...case` 替代）；
  - 不使用 `readonly`（用私有属性 + Getter 方法替代）；
  - 不使用构造函数属性提升（显式在类体内声明属性并赋值）；
  - 不使用枚举 `enum`（用抽象常量类替代）。
- **通过这种语法约束，老旧的 PHP 7.4 解释器在编译代码时绝对不会触发语法解析错误（Parse Error）！**

---

### 2. 向上兼容关卡：防止现代与未来站点（PHP 8.2 ~ 8.5+）产生废弃告警

#### 致命暗坑与防御方案：
1. **动态属性废弃（Dynamic Properties Deprecation，PHP 8.2+）**：
   - 传统 PHP 7.4 代码喜欢随意给对象绑定临时变量（如 `$session->temp_geo = 'US'`）；
   - 在 PHP 8.2~8.5 下，这会直接触发海量 `E_DEPRECATED` 警告，撑爆 `debug.log`；
   - **防御手段**：在数据对象基类上标记原生属性注解：
     `#[\AllowDynamicProperties]`
     该注解在 PHP 8.2+ 中合法声明允许动态属性，而在 PHP 7.4 中被直接当作注释忽略，完美实现双向静默兼容！
2. **内部函数传 Null 严格限制（PHP 8.1+）**：
   - PHP 7.4 允许 `strlen(null)`、`trim(null)`，但 PHP 8.1+ 会抛出废弃告警并在未来变为异常；
   - **防御手段**：在全局引入安全门面：
     `gr_safe_strlen($str)` 与 `gr_safe_trim($str)`，强制做 `(string) ($str !== null ? $str : '')` 兜底转换。

---

### 3. WordPress 核心版本双向兼容（WP 6.0 ~ WP 7.1+）

1. **WooCommerce HPOS 与古腾堡 Blocks 双挂载**：
   - 参考 `handl-utm-grabber/lite/woocommerce.php` 的成熟做法，通过 `OrderUtil::custom_orders_table_usage_is_enabled()` 动态分支判断：老版本写 `update_post_meta`，新版本调用 `$order->update_meta_data()`；
   - 同时挂载经典短码钩子与现代古腾堡 `woocommerce_store_api_checkout_update_order_from_request` 钩子。
2. **国际化语言包即时加载（JIT i18n）**：
   - 统一在 `init` 钩子优先级 10 执行 `load_plugin_textdomain`，完美避开 WP 6.5+ 的过早加载告警，同时保障 WP 6.0 正常加载语言文件。

---

## 三、 双向兼容测试流水线落地

```
               ┌──────────────────────────────────────────────┐
               │         双向兼容工程自动化质量流水线          │
               └──────────────────────┬───────────────────────┘
                                      │
              ┌───────────────────────┴───────────────────────┐
              ▼                                               ▼
   【向下兼容校验 (PHP 7.4)】                      【向上兼容校验 (PHP 8.2~8.5+)】
   • PHPCS (PHPCompatibilityWP)                   • PHPStan (Level 6 严格类型推导)
   • 严禁 PHP 8+ 专属保留字                        • 严禁未声明动态属性赋值
   • 语法编译通过率 100%                          • 内部函数 null 参数防御性封装
```

具体代码级封装标准请直接参阅：  
[`COMPAT-01-PHP-AND-WP-DUAL-COMPATIBILITY-ENGINEERING.md`](./COMPAT-01-PHP-AND-WP-DUAL-COMPATIBILITY-ENGINEERING.md)
