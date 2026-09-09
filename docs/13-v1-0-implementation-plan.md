# 13. v1.0 实施清单 (v1.0 Implementation Plan)

> 本文把 `12-roadmap-free-v1.md` 的 v1.0 范围拆解为**可独立验收的任务序列**，是 v1.0 开发期的工作真源：任务只做清单内的事，验收只认清单内的标准。
> 状态图例：⬜ 未开始 · 🔧 进行中 · ✅ 已验收（AGENTS.md §3.3 四项检查通过 **且** 验收标准实测达标）。
> 更新纪律：每完成一个任务，本表状态列随该任务的代码提交一并更新；禁止提前打勾；验收数字必须实测可复现（`AGENTS.md` §3.4）。

---

## 0. 任务总览

| 阶段 | 内容 | 任务 | 依赖 |
| :--- | :--- | :--- | :--- |
| Phase 0 | 开发与验证环境（ADR-0006） | E1–E8 | — |
| Phase 1 | 插件骨架与存储底座 | S1–S10 | Phase 0 |
| Phase 2 | 核心运行时 + 采集与归因 | C1–C12 | Phase 1 |
| Phase 3 | 流量安全（保守档） | W1–W11 | Phase 1（W4/W6 依赖 C2） |
| Phase 4 | 汇总与后台 9 页 | U1–U13 | Phase 2、3 |
| Phase 5 | 隐私合规与维护 | V1–V5 | Phase 2、3 |
| Phase 6 | 测试收口与发布准备 | T1–T10 | 全部 |

总体线性推进；Phase 2 与 Phase 3 可交错（归因链路与安全链路互不依赖，但都依赖 Phase 1 底座）。

---

## 1. Phase 0 — 环境准备

| ID | 任务 | 状态 | 验收标准（实测） |
| :--- | :--- | :--- | :--- |
| E1 | MariaDB 安装（Homebrew） | ✅ | `mysql --version` 返回 MariaDB；安装日志无错误 |
| E2 | MariaDB 服务 + 库/账号 | ✅ | 实测 2026-09-09：TCP 127.0.0.1:3306 `SELECT VERSION()` = 12.3.3-MariaDB；`greenpng_site` 库与 `gr_site`/`gr_admin` 账号存在；凭据只存 `greenpng-dev/CREDENTIALS.txt`（仓库外，600 权限，不入 git）。注：MariaDB 12 默认 skip-networking，已用 `/usr/local/etc/my.cnf.d/gr-dev.cnf` 显式 bind 127.0.0.1 + port 3306 开启 TCP |
| E3 | MySQL 主验证站 | ✅ | 实测 2026-09-09：`http://localhost:8091` HTTP 200；`wp core version` = 7.1；站点位于 `/Users/macos/greenpng-dev/wordpress`（与仓库分离，frankenphp php-server :8091） |
| E4 | 生态插件链接与激活 | ✅ | 实测 2026-09-09：9 个生态插件 symlink 就位；激活 woocommerce 11.1.0 / fluentform 6.2.13 / limit-login-attempts-reloaded 3.3.7；automatorwp / cartflows / fluent-crm / wp-slimstat / stream / user-submitted-posts 装入未激活 |
| E5 | WP-CLI 工作方式固化 | ✅ | `greenpng-dev/NOTES.md` 已记录 PHPRC 内存方案、服务启停、环境事实 |
| E6 | agy-suite 停用预案 | ⬜ | greenpng 首次装入任一测试站前执行 `wp plugin deactivate agy-suite`（站长已确认；在 S 骨架可激活时执行） |
| E7 | greenpng 插件目录链接 | ⬜ | S1 完成后创建 `greenpng-dev/wordpress/wp-content/plugins/greenpng -> /Users/macos/greenpng/plugin` |
| E8 | SQLite 兼容站保留 | ✅ | 现有 `:8090` 站（WP 7.1 + SQLite）原样保留为次要兼容环境，不作 v1.0 门禁 |

## 2. Phase 1 — 插件骨架与存储底座

| ID | 任务 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- |
| S1 | 主入口 | `plugin/greenpng.php`：插件头（`Requires at least: 6.0`、`Requires PHP: 7.4`、GPLv2+）、`GR_VERSION`、ABSPATH 守卫、PHP/WP 门槛不满足时仅 admin notice 不致命 | `php -l` 通过；主验证站激活成功 | `04` |
| S2 | Autoloader | `includes/core/class-gr-autoloader.php`：`GreenPNG\` → `includes/<模块>/class-gr-<slug>.php` 映射，无 Composer | 任意注册类可加载；触发 spl_autoload 无警告 | `04`、ADR-0003 |
| S3 | 主控类 | `includes/core/class-gr-plugin.php`：`plugins_loaded@10` 显式构造 ≤12 个服务注入构造函数，无 DI 容器 | 服务清单静态可查（PHPStan 覆盖） | `02` §2.1 |
| S4 | 设置服务 | `includes/core/class-gr-settings.php`：唯一 autoload=yes 的 `gr_settings`（默认值集中定义，≤8KB） | 激活后 option 存在；无第二个 autoload=yes 项 | `05` §6 |
| S5 | Schema | `includes/storage/class-gr-schema.php`：`DB_VERSION=1`；15 个物理对象 DDL 经 dbDelta；`gr_db_version` autoload=no；激活 + `admin_init` 双挂载 | 激活后 15 表存在；连续激活两次零 DDL 变更；前台请求零 DDL | `05` |
| S6 | 生命周期 | `class-gr-activator.php` / `class-gr-deactivator.php` / `uninstall.php` | 停用清 cron 不删数据；卸载默认保留数据，`gr_delete_data_on_uninstall=1` 时清空全部表与 option | `05` §4–5 |
| S7 | WP-Cron + CLI | 每日 `gr_cron_daily_maintenance` 注册；`wp greenpng maintenance` 命令骨架 | 停用后 scheduled event 清除 | `02` §2.4 |
| S8 | readme + pot | `plugin/readme.txt`（含 `== External services ==` 段）、`languages/greenpng.pot` | readme 结构自查通过；text domain 一律 `greenpng` | `08` |
| S9 | 规范工具链 | `composer.json`（仅 dev 依赖）+ `phpcs.xml.dist` + vendor 安装 | AGENTS §3.3 四项命令全部可运行（phpunit 允许空套件通过） | `11` §2 |
| S10 | 打包脚本 | `tools/build-zip.sh`、`tools/bump-version.sh` | 产包排除 vendor/测试/文档；版本三处同步（greenpng.php / GR_VERSION / readme Stable tag） | `02` §5 |

## 3. Phase 2 — 核心运行时 + 采集与归因（v1.0 范围）

| ID | 任务 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- |
| C1 | 事件 DTO 与门面 | `class-gr-event.php`（私有属性+getter）+ `gr_dispatch_event()` / `gr_get_recent_events()` | 派发即 `do_action('gr_event', …)`；门面 ≤3 行转发 | `03` §2 |
| C2 | 表名解析 + 容器门面 | `Database::table()` + `gr()` | 全仓库检索不到硬编码 `{$wpdb->prefix}gr_` 之外的表名拼法 | `02` §2.3 |
| C3 | IP 解析 | `Ip_Resolver` + `gr_get_client_ip()` | 默认仅 `REMOTE_ADDR`；伪造 XFF 无效；可信代理开启后右扫；4 场景单测 | `10`、`03` §1 |
| C4 | 密钥与签名 | `Secrets`（wp_salt 派生）+ `gr_hash_pii` / `gr_sign_hmac` / `gr_generate_event_id` / `gr_get_user_agent` | 密钥明文不落库；HMAC 输出确定性可测 | `10` |
| C5 | 会话与访客哈希 | `gr_sessions` 仓储 + visitor_hash（每日旋转盐 + 匿名化 IP + UA） | 在线数走 `SELECT COUNT`，无 transient 读改写 | `05` §3.2 |
| C6 | REST 采集端点 | `greenpng/v1/collect`：日盐令牌（`hash_equals`）、每 IP 限流（对象缓存优先）、8KB body 上限、严格 schema、`nocache_headers()` | 无/错令牌 401；超频 429；超体 413；未知事件名拒绝；sendBeacon JSON 可解析 | `02` §2.5、`11` §3.1 |
| C7 | 归因监听 | Attribution_Listener（`template_redirect@10`；`wp_has_consent('marketing')` 门控；`gr_attr` 签名 cookie，HttpOnly + SameSite=Lax） | 无同意：不写 cookie、不落触点；有 UTM 且有同意：触点行落库 | `02` §4、ADR-0005 |
| C8 | 归因参数与模型 | `gr_parse_attribution_params()` + `gr_calculate_attribution()`（first/last/linear/40-20-40/time-decay 7 日半衰） | 5 模型在手工算好的期望序列上全对（单测） | `03` §4 |
| C9 | 转化幂等绑定 | `gr_bind_conversion()` | UNIQUE `source_unique` + meta 锁双防线；双回调仅一条记录 | `05` §3.3、`11` §3.2 |
| C10 | WooCommerce 适配器 | `Adapter_Interface` 首个实现（`woocommerce_payment_complete`；HPOS 安全 meta 写法；`\Throwable` 隔离） | WC 不存在时零加载零报错；适配器内抛错不影响站点其余部分 | `02` §2.6、`11` §3.4 |
| C11 | 表单适配器 | Fluent Forms / CF7 / WPForms 三个适配器 | 主验证站实测 Fluent Forms；CF7/WPForms 未安装时零加载 | `12` v1.0 |
| C12 | 语义提取与生态检测 | `gr_uif_extract_fields()` + `gr_uif_detect_ecosystem()` | 夹具样本（含 `auto:email` 等）提取正确 | `03` §7 |

## 4. Phase 3 — 流量安全（保守档：默认仅记录不拦截）

| ID | 任务 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- |
| W1 | 请求检查器 | Security_Request_Inspector（`init@10`，默认仅记录） | 检查器异常时静默跳过，前台渲染不受影响 | `02` §2.7 |
| W2 | 扫描器 UA | `gr_is_scanner_ua()` 本地特征表 | 夹具 UA 全命中；零外呼 | `03` §3 |
| W3 | CIDR 匹配 | `gr_match_cidr()`（IPv4 位运算 + IPv6 前缀） | /32、/0、/24 跨界与 IPv6 前缀单测全过 | `11` §3.1 |
| W4 | 访问规则 | `gr_access_rules` 仓储 + `gr_is_trusted_ip()` / `gr_is_ip_blocked()` / `gr_is_url_allowed()`；`plugins_loaded` 读一次 L1 静态缓存 | 前台总 SQL 在 ≤2 条预算内 | `05` §2、`09` |
| W5 | 临时封禁 | `gr_block_ip()` / `gr_unblock_ip()` | transient TTL 生效；WP-CLI 可解锁 | `03` §3 |
| W6 | 浪涌折叠日志 | `gr_log_security_event()`：fold_key = md5(ip+rule+小时窗)，可移植 upsert（先 UPDATE 后 INSERT） | N 次命中 = 1 行且 `hit_count=N`（实测断言行数，不宣称压缩率） | `05` §3.1、`11` §3.1、OQ-2 |
| W7 | 登录保护 | `gr_check_login_lockout()` / `gr_record_login_failure()`（`wp_login_failed`） | 阈值、递增锁定时长、允许列表恢复单测；默认仅记录模式明确 | `03` §3 |
| W8 | 蜜罐（opt-in） | `gr_render_honeypot()`（`aria-hidden="true"` + `tabindex="-1"`）/ `gr_check_honeypot()` | 默认关；开启后登录/注册表单生效；屏幕阅读器不朗读 | `03` §3、`06` §4 |
| W9 | 载荷检查 | `gr_inspect_request_payload()` 保守高置信规则集 | 命中默认仅标记不拦截；误报样本不命中 | `03` §3、`10` §4 |
| W10 | FCrDNS | `gr_verify_crawler()`（PTR + 正查含 DNS_AAAA；24h transient 缓存；失败=「无法验证」） | **绝不在前台请求路径同步 DNS**：首见入队，日维护任务/工具页触发验证 | `03` §3、铁律 3 |
| W11 | 安全总熔断 | `gr_settings` 安全总开关 + `GR_SECURITY_OFF` 常量紧急逃生 | 开启后全部 inspect 跳过（修复参考项目 WAF 误杀无逃生缺陷） | `10` §4 |

## 5. Phase 4 — 汇总与后台页面（v1.0 页面子集 = 9 页）

v1.0 页面：Dashboard、Traffic & Security（3 标签）、Access Rules、Login Protection、Campaigns（4 标签）、URL Builder、Audit Log、Data Retention、Status & Diagnostics。Bot & Device Signals、Funnels & Goals、Audience、Integrations 各页随 v1.1/v1.2 交付；FCrDNS 记录模式的结果暂在「威胁事件」标签呈现。

| ID | 任务 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- |
| U1 | 日汇总 | `gr_daily_stats` 聚合任务（cron 幂等 upsert） | 瘦身后报表读数不变（单测） | `05` §1 |
| U2 | 图表库 | uPlot 本地打包（≤20KB min + 未压缩源码同目录） | 仅图表页 enqueue；零 CDN | `06` §2.3 |
| U3 | Dashboard | KPI + 趋势图 + `screen-reader-text` 表格视图 | 只读 `gr_daily_stats`，零直查原始表 | `06` |
| U4 | gr-datagrid.js | 由参考项目 `agy-datagrid.js` 修复移植：fetch + `X-WP-Nonce`；`textContent` 替代 innerHTML；无 JS 服务端降级 | XSS 注入夹具不执行；禁 JS 后页面可用 | `06` §2.2 |
| U5 | Traffic & Security | 实时流 / 威胁事件 / 欺诈审计 3 标签 | 全部 WP 原生组件；只读 REST 亦需 `manage_options` | `06` |
| U6 | Access Rules | 封禁 / 允许列表（`WP_List_Table` + Settings 表单） | 每个写操作 nonce + 能力双校验 | `06` §2.1 |
| U7 | Login Protection | 爆破审计 + 会话管理 | CLI 解锁指引在页面可见 | `06` |
| U8 | Campaigns | 战役 / UTM / 点击ID / 归因模型 4 标签 | 归因对比只显示实测计算值 | `06` |
| U9 | URL Builder | 本地 UTM 链接构建器 | 零外呼；输出 `esc_url` | `06` |
| U10 | Audit Log | `gr_audit_diff()` / `gr_audit_log()` / `gr_audit_query()` + 页面 | 递归 diff 单测；列表分页服务端 | `03` §8 |
| U11 | Data Retention | 保留期/行数上限设置 + 手动 OPTIMIZE 按钮 | cron 绝不自动 OPTIMIZE | `05` §5 |
| U12 | Status & Diagnostics | `gr_export_diagnostics()`（脱敏导出）、`gr_get_table_stats()`、适配器状态 | 导出含 PHP/WP/DB 版本，无任何凭据/敏感值 | `03` §10 |
| U13 | 全局设置区块 | 隐私默认（IP 匿名化/同意模式）与安全总开关的统一 UI | **OQ-1 待确认**：建议并入 Status & Diagnostics 页新增 Settings 标签（不突破 19 页上限） | `06` 缺口 |

## 6. Phase 5 — 隐私合规与维护

| ID | 任务 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- |
| V1 | IP 匿名化 | 默认开启；/24 //48 截断或盐哈希（设置可选） | 存储值实测为匿名形态 | ADR-0005 |
| V2 | WP 隐私 API | 导出器/擦除器注册（会话/触点/转化/联系人） | WP 核心隐私工具界面可见并可执行 | ADR-0005 |
| V3 | 瘦身执行 | `gr_prune_table()`（分批 2000 + 100ms 间隔 + 10s 时间预算） | 构造超龄数据实测删至保留线 | `03` §10、`05` §5 |
| V4 | readme 披露定稿 | `== External services ==` 逐项（v1.0 = 声明零默认外呼） | 与 `07` 清单逐项一致 | `07`、`08` |
| V5 | 同意门控复核 | 全链 `wp_has_consent` 检查点清单化 | 逐点实测开/关两态行为 | ADR-0005 |

## 7. Phase 6 — 测试收口与发布准备

| ID | 任务 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- |
| T1 | 单元测试集 | CIDR / 归因 5 模型 / 浪涌折叠 / 登录锁定 / 提取器 / 递归 diff / 参数解析 | 核心域 ≥70% 门槛；全绿 | `11` §3 |
| T2 | 集成测试集 | 采集端点五情形 / WC 双回调幂等 / HPOS 两模式 / dbDelta 幂等 / 卸载两模式 | MySQL 主验证站实测全绿 | `11` §3 |
| T3 | 性能基准 | `tests/benchmarks/front-request.php` 装前/装后 P95 对比 | 实测差值 ≤5ms、≤2 SQL；数字写入报告 | `09`、`11` §6 |
| T4 | PHPStan | level 6 + 基线文件 | 零未处理错误 | `11` §2 |
| T5 | 兼容性静态检查 | PHPCompatibilityWP（testVersion 7.4-） | 零 PHP 8+ 语法（本机无 7.4 运行时，运行时验证归 CI） | ADR-0003/0006 |
| T6 | 生命周期冒烟 | activate / deactivate / uninstall / 升级幂等 | 主验证站 WP-CLI 实测 | `11` §1 |
| T7 | SQLite 兼容冒烟 | 在 `:8090` 站跑 T6 子集 | 结果如实记录，不作门禁 | ADR-0006 |
| T8 | 版本与打包 | `tools/bump-version.sh` → 1.0.0；`tools/build-zip.sh` | 版本三处同步；包内无 vendor/测试/文档 | `02` §5、`08` |
| T9 | WP.org 自查 | `08` 清单逐项打勾记录 | 全项通过记录在案 | `08` |
| T10 | 提交上线 | WordPress.org svn 提交（人工执行） | — | `08` |

## 8. 开放问题（实现前需确认）

| 编号 | 问题 | 当前建议 |
| :--- | :--- | :--- |
| OQ-1 | 全局设置 UI 位置：`06` 的 19 页无独立 Settings 页，但隐私默认/安全总开关需要统一入口 | 并入 Status & Diagnostics 页新增 Settings 标签，不突破 19 页 |
| OQ-2 | SQLite 方言取舍：`ON DUPLICATE KEY UPDATE` 等为 MySQL 方言 | v1.0 以 MySQL 为准（ADR-0006）；折叠 upsert 用可移植「先 UPDATE 后 INSERT」；SQLite 冒烟仅记录 |
| OQ-3 | WP 6.0 下界本地未覆盖（本地两站均 WP 7.1） | 6.0 下界验证归 CI 矩阵；必要时后续增搭 6.0 站 |
| OQ-4 | WooCommerce 11.x 双结账路径（Blocks/经典）× HPOS 组合 | 主验证站从「经典 + HPOS 默认」起步；Store API/Blocks 覆盖程度在 T2 如实记录 |

## 9. 提交与验收纪律

- 一任务一提交，提交信息 `feat|fix|docs: <摘要>`；不提交 `vendor/`（运行时）与构建产物（AGENTS §7）。
- 每提交前跑 AGENTS §3.3 四项检查；验收标准一栏的数字必须来自实测并写入提交说明。
- 本清单的状态列是唯一进度真源；与其他记录冲突时以本表为准。
