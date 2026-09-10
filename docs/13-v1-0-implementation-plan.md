# 13. v1.0 实施清单 (v1.0 Implementation Plan)

> 本文把 `12-roadmap-free-v1.md`（2026-09-09 四支柱修订版）的 v1.0 范围拆解为**可独立验收的任务序列**，是 v1.0 开发期的工作真源：任务只做清单内的事，验收只认清单内的标准。
> **修订记录**：2026-09-09 依据 ADR-0007 重构——新增客户端探针（C13）、A/B 引擎（C14–C15）、出网支柱（I1–I5）、独立 Settings 页（U13）、UA 引擎升级（W2）等；任务 61 → 85 项；§8 开放问题全部收口。2026-09-10 §2–§7 任务表补齐状态列（落实 §9 状态列纪律）。2026-09-10 开发顺序调整（站长授权代理排序）：S9 提前使 §3.3 #2–#4 即时生效；S3 顺延至 S4–S7 之后统一接线服务。
> 状态图例：⬜ 未开始 · 🔧 进行中 · ✅ 已验收（AGENTS.md §3.3 四项检查通过 **且** 验收标准实测达标；S1–S8 期间工具链未建（S9 交付），以检查 #1 与任务自身验收标准为准，#2–#4 自 S9 起强制补跑）。
> 更新纪律：每完成一个任务，本表状态列随该任务的代码提交一并更新；禁止提前打勾；验收数字必须实测可复现（`AGENTS.md` §3.4）。

---

## 0. 任务总览

| 阶段 | 内容 | 任务 | 依赖 |
| :--- | :--- | :--- | :--- |
| Phase 0 | 开发与验证环境（ADR-0006/0007） | E1–E9 | — |
| Phase 1 | 插件骨架与存储底座 | S1–S10 | Phase 0 |
| Phase 2 | 核心运行时 + 采集 / 归因 / A-B | C1–C15 | Phase 1 |
| Phase 3 | 流量安全与 RPA 识别 | W1–W14 | Phase 1（W4/W6/W14 依赖 C2/C5） |
| Phase 4 | 汇总与后台 14 页 | U1–U17 | Phase 2、3、5 |
| Phase 5 | 出网支柱 + 隐私合规 | I1–I5、V1–V5 | Phase 1（I2–I4 依赖 C10 转化链路） |
| Phase 6 | 测试收口与发布准备 | T1–T10 | 全部 |

共 85 项。Phase 1 先行；Phase 2 / 3 / 5 三线可并行（互不依赖）；Phase 4 依赖前三者的读接口。

## 1. Phase 0 — 环境准备

| ID | 任务 | 状态 | 验收标准（实测） |
| :--- | :--- | :--- | :--- |
| E1 | MariaDB 安装（Homebrew） | ✅ | `mysql --version` 返回 MariaDB；安装日志无错误 |
| E2 | MariaDB 服务 + 库/账号 | ✅ | 实测 2026-09-09：TCP 127.0.0.1:3306 `SELECT VERSION()` = 12.3.3-MariaDB；`greenpng_site` 库与 `gr_site`/`gr_admin` 账号存在；凭据只存 `greenpng-dev/CREDENTIALS.txt`（仓库外，600 权限，不入 git）。注：MariaDB 12 默认 skip-networking，已用 `/usr/local/etc/my.cnf.d/gr-dev.cnf` 显式 bind 127.0.0.1 + port 3306 开启 TCP |
| E3 | MySQL 主验证站 | ✅ | 实测 2026-09-09：`http://localhost:8091` HTTP 200；`wp core version` = 7.1；站点位于 `/Users/macos/greenpng-dev/wordpress`（与仓库分离，frankenphp php-server :8091） |
| E4 | 生态插件链接与激活 | ✅ | 实测 2026-09-09：9 个生态插件 symlink 就位；激活 woocommerce 11.1.0 / fluentform 6.2.13 / limit-login-attempts-reloaded 3.3.7；automatorwp / cartflows / fluent-crm / wp-slimstat / stream / user-submitted-posts 装入未激活 |
| E5 | WP-CLI 工作方式固化 | ✅ | `greenpng-dev/NOTES.md` 已记录 PHPRC 内存方案、服务启停、环境事实 |
| E6 | agy-suite 停用预案 | ✅ | 实测 2026-09-10：greenpng 装入 :8091 前已在 :8090 执行 `wp plugin deactivate agy-suite` 成功；`wp plugin list` 实测 `agy-suite inactive`；:8090 站点 HTTP 200 |
| E7 | greenpng 插件目录链接 | ✅ | 实测 2026-09-10：symlink `greenpng -> /Users/macos/greenpng/plugin` 就位并经 :8091 `wp plugin list` 可见 |
| E8 | 参考环境保留 | ✅ | `:8090` SQLite 站原样保留，仅作参考环境（ADR-0007：不承诺 SQLite 兼容，不设兼容门禁任务） |
| E9 | 常用插件补装（OQ-4） | ⬜ | 从 wordpress.org 下载安装：Contact Form 7、WPForms、Elementor、WP Super Cache（或等价缓存插件）、Yoast SEO（或等价）；`wp plugin list` 实测就位；激活时机随对应适配器/共存测试任务 |

## 2. Phase 1 — 插件骨架与存储底座

| ID | 任务 | 状态 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- | :--- |
| S1 | 主入口 | ✅ | `plugin/greenpng.php`：插件头（`Requires at least: 6.0`、`Requires PHP: 7.4`、GPLv2+）、`GR_VERSION`、ABSPATH 守卫、PHP/WP 门槛不满足时仅 admin notice 不致命 | `php -l` 通过；主验证站激活成功。实测 2026-09-10：双路语法检查通过（本机 FrankenPHP php 不支持 `-l` 旗标，改用直接执行至 ABSPATH 守卫的全量编译检查 + `token_get_all(TOKEN_PARSE)`，方法记入 NOTES.md）；:8091 `wp plugin activate greenpng` 成功；`wp eval` 实测 `GR_VERSION=1.0.0`、`GR_PLUGIN_DIR`/`GR_PLUGIN_URL` 已定义；前台 HTTP 200；debug.log 无本插件条目（grep 唯一命中为 09-09 旧 WooCommerce JIT 提示中的站点目录名 greenpng-dev，与本插件无关）。phpcs/phpunit 已随 S9 补跑通过（phpcs exit 0；EntryHeaderTest 5 tests 17 assertions OK） | `04` |
| S2 | Autoloader | ✅ | `includes/core/class-gr-autoloader.php`：`GreenPNG\` → `includes/<模块>/class-gr-<slug>.php` 映射，无 Composer | 实测 2026-09-10：AutoloaderTest 7 tests（夹具树真实加载 core / Gr_ 前缀剥离 / 下划线转连字符 / 深层 Ecosystem 路径 / GR_PLUGIN_DIR 回退 / 外部命名空间·无模块·缺文件三种静默路径——phpunit failOnWarning 下零告警）；:8091 `wp eval` 实测 autoloader 在 SPL 链（registered=yes）、`spl_autoload_call` 未知类零输出（silent_call=ok）、前台 HTTP 200、debug.log 零 GreenPNG 类名条目。类名定稿 `Gr_` 前缀（docs/02 §2.1 与 docs/03 §1/§2 返回类型同步修正） | `04`、ADR-0003 |
| S3 | 主控类 | ✅ | `includes/core/class-gr-plugin.php`：`plugins_loaded@10` 构造主控；Phase 1 协作方为静态门面（`Gr_Queue::boot`、`admin_init → Gr_Schema::maybe_upgrade`、`init@10 → load_translations`、`Gr_Cli::register`）于 `register_hooks()` 显式接线，实例服务自 C 阶段起构造注入（落地形态记入 `02` §2.1）；入口文件收薄为 `add_action('plugins_loaded', …, 10, 0)` | 实测 2026-09-10：PluginTest 3 tests（类解析 + 双次 run 幂等/三钩注册/每日排程仅一条、admin_init 回调恰为 `Gr_Schema::maybe_upgrade`、入口文件 plugins_loaded 接线契约），全套 50 tests 702 assertions OK，§3.3 四项全绿。:8091 实测：`has_action` 验证 plugins_loaded=10 / admin_init=10 / init 回调在位；删除 `gr_queue_daily_event` → 下一请求经控制器自愈恢复；`wp greenpng maintenance` 经控制器注册 Success；前台 HTTP 200、debug.log 零 GreenPNG 条目。服务清单静态可查（构造签名 + `register_hooks()`）；PHPStan 全量覆盖按 T4 收口 | `02` §2.1 |
| S4 | 设置服务 | ✅ | `includes/core/class-gr-settings.php`：唯一 autoload=yes 的 `gr_settings`（默认值集中定义，≤8KB），含安全/探针/归因/隐私默认 | 实测 2026-09-10：SettingsTest 6 tests（默认逐项对齐 ADR-0007、serialize 560 字节实测 <8KB、autoload=yes 写入、幂等不重置、默认合并、set 持久化）；:8091 `wp eval` 实测 install 后 `gr_settings` 数组就位（probe_default=1）、`gr_*` autoload=yes 恰 1 行（option list 实测）。"激活后 option 存在"字面闭环已随 S6 收口（:8091 重装重激活实测 gr_settings 在位） | `05` §6 |
| S5 | Schema | ✅ | `includes/storage/class-gr-schema.php`：`DB_VERSION=1`；15 个物理对象 DDL 经 dbDelta；**补索引**：`gr_sessions(last_active)`、`gr_contact_tags(tag_id, contact_id)`、`gr_daily_stats` UNIQUE `(stat_date, metric_type, metric_key)`；`PRIMARY KEY` 双空格保险写法；`gr_db_version` autoload=no；激活 + `admin_init` 双挂载 | 实测 2026-09-10：SchemaTest 10 tests（15 对象及次序对齐 docs/05 §2、双空格 PK、VARCHAR≤191、零外键、行模板+逗号、三补索引、§3.1–§3.3 锚点、`table_names` 派生、版本常量、`store_version` autoload=no 创建/更新双路径），全套 33 tests 640 assertions OK，§3.3 四项检查全绿。:8091 实测：全新安装 0→15 张 `wp_gr_*`；`SHOW INDEX` 实证三补索引落地（last_active / tag_contacts / stat_unique）；二次 dbDelta **零变更**；`gr_db_version=1` autoload=off，且仅在 15 表齐备后写入（`all_objects_present` 闸门；drop 1 表 + 版本滞后 → `maybe_upgrade` 自愈复建 14→15 + 版本回写）；前台 HTTP 200、debug.log 零新增行零插件条目。两项 dbDelta 纪律经首轮实测失败定位并回写 `04` §3.11.8 与 `05` §3：字段行必须逗号分隔（dbDelta 对新表按原文执行 CREATE，无逗号 15 表全败）；整数必须带核心同款显示宽度（`BIGINT(20)`/`INT(10)`/`TINYINT(3)`，MariaDB 宽度失配每轮全量 CHANGE COLUMN；MySQL ≥8.0.17 由 dbDelta 显式忽略纯宽度差，upgrade.php 源码实核）。激活/admin_init 钩子挂载随 S6 落地后按连续激活两次字面复测 | `05`、ADR-0007 |
| S6 | 生命周期 | ✅ | `includes/core/class-gr-activator.php`（激活 = Settings + Schema 幂等安装）/ `includes/core/class-gr-deactivator.php`（清 `DAILY_HOOK`，不删数据）/ `includes/storage/class-gr-uninstall.php` + 根级 `uninstall.php`（无 autoloader 场景直 require 两类文件；`ABSPATH` + `WP_UNINSTALL_PLUGIN` 双守卫） | 实测 2026-09-10：LifecycleTest 5 tests（三类经真实 autoloader 解析、`DAILY_HOOK` 名对齐 docs/05 §5、入口激活/停用钩子注册契约、uninstall 守卫先于 require 的顺序契约、删除旗标默认保留且 '1' 显式开启），全套 38 tests 661 assertions OK，§3.3 四项检查全绿。:8091 全链路实测：伪排 `gr_cron_daily_maintenance` → 停用后 cron 0 条而 15 表 + `gr_db_version`/`gr_settings` 全保留；默认卸载（`wp plugin uninstall` 实跑 uninstall.php）数据全保留；旗标置 1 卸载 → 0 表 + `gr_*` option 清零（含旗标自身），证明 uninstall.php 经 WP-CLI 真执行；重装重激活幂等（S4"激活后 option 存在"字面闭环收口，S4 行同步标记）；前台 HTTP 200。停用清理项将随 Gr_Queue 落地扩展（互斥锁 transient 与 AS 动作） | `05` §4–5 |
| S7 | 自适应队列 | ✅ | `includes/core/class-gr-queue.php`：`enqueue($hook, $args, $delay=0)` 运行时嗅探 AS（组 `greenpng`）/ 否则 `wp_schedule_single_event`；`with_mutex` transient 互斥锁 TTL 300s；内部 `EVENT_HOOK` 每日事件 → 互斥内 `do_action(DAILY_HOOK)`（防重入且不可递归）；`includes/core/class-gr-cli.php` 的 `wp greenpng maintenance`；boot 自愈排程 | 实测 2026-09-10：QueueTest 9 tests（无 AS 后端判定、派发路由+args+delay、负 delay 钳制、每日排程仅一条且缺失才补、boot 注册 runner+自愈、run_daily 单次触发公钩+锁释放、互斥防重入+释放恢复、with_mutex 异常后锁仍释放、teardown 清场），全套 47 tests 693 assertions OK，§3.3 四项全绿。:8091 实测：boot 自愈 `gr_queue_daily_event`（daily）；`wp greenpng maintenance` Success（零参方法经 WP-CLI 实跑可行）；持锁 `gr_queue_lock_daily` → Warning Skipped、释放 → Success；**双后端各一次实测**——AS：动作落 `wp_actionscheduler_actions` 组 `greenpng`（id=11 实查）；WP-Cron：停 woocommerce+fluentform（:8091 双 AS 提供方）后 `backend=wp-cron`，`enqueue(...,120)` 于 cron list 可见并删除；停用清场实测：pending AS 动作 → canceled（`cancel_actions_by_group`，AS 源码 functions.php:312 实读）、`gr_*` cron 0 条、重激活后每日事件自愈；前台 HTTP 200。发现并修复：命名空间内 `WP_CLI::` 解析为 `GreenPNG\Core\WP_CLI` 致 CLI Fatal（`use WP_CLI;` 修复；守卫式 lint 与单测均盲区）；`wp cron event list` 只列未来事件（delay=0 落 t0 下一 tick 执行），两条均记入 NOTES.md | `02` §2.4、ADR-0007 |
| S8 | readme + pot | ✅ | `plugin/readme.txt`（`== External services ==` 五项逐项披露：GA4 / Meta / TikTok / 自定 Webhook / DB-IP 更新按钮——目的·发送数据·时机·政策链接，全部默认关；`== Privacy ==` 双轨 IP·探针两模块·隐私 API·邮箱哈希+加密；`== Attribution ==` DB-IP CC BY 4.0；FAQ 含数据存放位置与卸载保留策略）+ `plugin/languages/greenpng.pot` | 实测 2026-09-10：readme 结构自查通过（Contributors、Tags 4≤5、Requires at least 6.0、Tested up to 7.1、Requires PHP 7.4、Stable tag 1.0.0 与文件头·`GR_VERSION` 三处一致、FAQ 数据存放、External services 逐项、DB-IP 归属可见）；POT 经 WP-CLI 2.12 `wp i18n make-pot` 生成，6 msgid（3 代码串 + 插件名/描述元数据），行号引用实测（greenpng.php:52、class-gr-cli.php:57/61），`X-Domain: greenpng`；`Report-Msgid-Bugs-To` 经 realpath 符号链接陷阱修正为真实 slug（记入 NOTES.md）；text domain 一致性由 phpcs `WordPress.WP.I18n`（ruleset `text_domain=greenpng`）持续把关；§3.3 四项全绿。注：readme 描述 1.0.0 全量功能（docs/12 口径），截图与包内 NOTICE 随 U/I 阶段补齐，POT 发布前随新增字符串再生成 | `08` |
| S9 | 规范工具链 | ✅ | `composer.json`（仅 dev 依赖）+ `phpcs.xml.dist` + `phpcs-compat.xml.dist` + `phpunit.xml.dist` + `tests/` + vendor 安装 | 实测 2026-09-10：§3.3 四项全绿（#2 phpcs exit 0、#3 PHPCompatibilityWP exit 0、#4 phpunit 10 tests 29 assertions OK；#1 见 S1 行）；`str_*` 夹具零误报（tests/fixtures/str_polyfill_fixture.php 实测 exit 0）。本机适配三项均实测定位并记入 NOTES.md：dealerdirect 写 conf 失败→installed_paths 双写 ruleset `<config>`；Generic.PHP.Syntax 因本机 `php -l` 坏死锁→块内 exclude（语法由 #1 覆盖）；PrefixAllGlobals MIN_PREFIX_LENGTH=4 硬拒短前缀→exclude + PrefixDisciplineTest 承接 | `11` §2 |
| S10 | 打包脚本 | ✅ | `tools/build-zip.sh`（plugin/ 直拷 + 防御性排除清单；NOTICE/数据目录检查：默认 warn、`--strict` 失败——数据文件随 geoip/UA 引擎阶段落位，T8 以 `--strict` 全量收口；产物 `dist/greenpng-<ver>.zip`，dist/ 已 gitignore）、`tools/bump-version.sh`（三处同步：文件头 `Version:` / `GR_VERSION` / readme `Stable tag`；perl -pi 跨 BSD/GNU；改后自校验，半改树永不放行） | 实测 2026-09-10：`bash -n` 双脚本通过；bump 负路径实测（无参 usage、同版本 already-at 均 exit 1）；1.0.0→1.0.1 实跑三处同步（对齐空格保留；phpunit 50 tests 702 assertions 于 1.0.1 仍绿——EntryHeaderTest 同步不变式设计生效）、git checkout 还原 1.0.0；build 正路径产出 `dist/greenpng-1.0.0.zip`（13 文件、`unzip -t` 零错误、结构含 greenpng/greenpng.php·readme·uninstall·pot、排除项 vendor/tests/docs/.git/composer/phpcs/phpunit grep 计 0）、NOTICE/数据缺失 warn 实测、`--strict` exit 1 实测。两项脚本缺陷经实测定位修复：header 替换漏 `$1` 致整行塌缩（脚本改后自校验当场拦截）、`GR_VERSION` 匹配漏行首缩进。注：DB-IP/CrawlerDetect 数据文件与包内 NOTICE 随 I/W 阶段落位，T8 `--strict` 收口 | `02` §5 |

## 3. Phase 2 — 核心运行时 + 采集 / 归因 / A-B

| ID | 任务 | 状态 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- | :--- |
| C1 | 事件 DTO 与门面 | ✅ | `Gr_Event`（不可变风格 DTO：私有属性+getter；payload 中 7 个上下文键提升为列并从 payload_json 剔除、按列宽截断防整行插入失败；空名抛 `InvalidArgumentException`；`mark_persisted()` 为包内约定唯一可变点）+ `Gr_Event_Dispatcher`（构造 → `gr_persist_event` 过滤器决定落库 → `do_action('gr_event', $event)`；次序保证订阅者可读到行 id）+ `Gr_Event_Repository`（storage 层，经 `Gr_Database::table()` 解析表名；LIMIT 钳制 1..500；payload_json 读写双向编解码）+ `gr_dispatch_event()` / `gr_get_recent_events()` 门面（各 1 行转发）；`Gr_Plugin` 首个实例服务接线（`events()`） | 实测 2026-09-10：:8091 `wp eval-file`——派发真实落库（persisted_id>0）、上下文键提升与 payload 保留并存、钩子收到**同一** DTO 实例且已带行 id、`gr_get_recent_events` 读回 payload 解码 + visitor/group 列正确、探针行清理 deleted=1/剩余 0、前台 200、debug.log 无新增（1965 行基线）；phpunit 68 tests 773 assertions（EventTest 11 例）；坑位实录：phpcs 插值豁免须用 `WordPress.DB.PreparedSQL.InterpolatedNotPrepared`（`NotPrepared` 不覆盖插值场景）；wpdb 测试桩 `prepare` 需为 `%s` 补引号方与核心行为一致 | `03` §2 |
| C2 | 表名解析 + 容器门面 | ✅ | `Gr_Database::table()`（Core，不触 `$wpdb`，DDL 派生名单校验 + 请求内缓存）+ `Gr_Schema::resolve_table()`（Storage，注入式 prefix，未知名抛 `InvalidArgumentException`，可容忍 `gr_` 前缀两种写法）+ `gr()`（`Gr_Plugin::instance()` 惰性单例、私有构造；钩子注册仍只在 `plugins_loaded@10`）+ `includes/gr-functions.php` 门面文件（入口 require_once 加载，函数无法 autoload） | 实测 2026-09-10：:8091 `wp eval-file`——`table('events')`=`wp_gr_events`、`table('security_logs')`=`wp_gr_security_logs`、`table('gr_events')` 与短键等价、未知名 `evnts` 实抛异常、`gr()` 三重一致返回同一 `Gr_Plugin` 实例、解析名经 `SHOW TABLES LIKE` 实证存在；验收 grep：schema DDL 之外表名字面量与 `prefix . 'gr_` 拼接均 0 处；phpunit 57 tests 717 assertions（DatabaseTest 7 例）；PrefixDisciplineTest 同步升级（正则补 `function_exists` 守卫内的缩进定义 + `gr` 为 docs/03 §1 注册特例）；debug.log 探针残留清理（备份 .bak-c2）。执行序注：C2 先于 C1 提交——C1 仓储依赖本解析器 | `02` §2.3 |
| C3 | IP 解析 | ✅ | `Gr_Ip_Resolver`（security 层；`filter_var` 验证 + 缺失/无效回退 `0.0.0.0`；经 `gr()->settings()` 读 `trust_proxy_headers`/`trusted_proxies`）+ `Gr_Ip_Matcher`（CIDR 原语：inet_pton 字节比较、部分字节掩码、裸 IP 视为 /32|/128、v4/v6 不互配——W 阶段 `gr_match_cidr()` 门面届时一行复用）+ `gr_get_client_ip()` 门面；`Gr_Plugin` 接线 `settings()` 实例服务 | 实测 2026-09-10：phpunit 81 tests 805 assertions（IpResolverTest 13 例——4 场景 + 多跳右扫/全可信回退/断链回退/匹配器 IPv4·IPv6·族不互配·/0·部分字节·垃圾输入零警告）；:8091 `wp eval-file` 四场景全对（伪造 XFF 无效 / 右扫越两可信跳取回客户端 / 未列 REMOTE_ADDR 不激活头 / 无效值回 0.0.0.0），设置改动经 `set()` 备忘失效路径生效、探后还原 0/[]，前台 200，debug.log 1965 行无新增；坑位实录：`Gr_Settings` 备忘 + 容器静态实例跨测试残留 → 增 `Gr_Plugin::reset_instance()` 测试缝（reset 桩内调用）；phpcs 超全局读需 `wp_unslash`，XFF 整头豁免的真实 sniff 码是 `ValidatedSanitizedInput.InputNotSanitized`（默认输出不显码，须 `phpcs -s` 实查） | `10`、`03` §1 |
| C4 | 密钥与签名 | ✅ | `Gr_Secrets`（AES-256-GCM，信封 = base64(iv 12B + tag 16B + 密文)；密钥 `hash('sha256', wp_salt('auth') . '\|greenpng-secrets')` 每次派生永不落盘；`store()`/`reveal()`/`forget()` 走 autoload=no option，openssl 不可用即拒存不降级明文；三原语：`hash_pii`（小写规范化 + 类型域前缀 + 空进空出）、`sign_hmac`（sha256 64hex 确定性）、`generate_event_id`（32hex；有熵 = 确定性哈希幂等收敛，无熵 = 16 随机字节））+ `Gr_Request::user_agent()`（超全局读点唯一：sanitize_text_field + wp_unslash + 512 截断）+ 四门面 | 实测 2026-09-10：phpunit 92 tests 847 assertions（SecretsTest 11 例——含空串信封、篡改 GCM 拒绝、密文不含明文、autoload=no 标记、熵收敛、UA 消毒截断）；:8091 `wp eval-file` 真盐实跑——往返 ok、信封逐次唯一、store 后 DB 原值为信封且不含明文（"密钥明文不落库"验收项实证）、reveal/forget/空回退 ''、hash 归一/类型隔离、HMAC 确定性 64hex（"输出确定性可测"验收项实证）、event_id 形状与熵收敛、UA：CLI 值 13 字符 + 600 字符/CRLF 合成值截 512 无换行；前台 200，debug.log 探针残留清理（备份 .bak-c4）；坑位实录：解密长度闸门 `<=` 误拒空明文信封（恰 28 字节为合法信封）改 `<`；wp eval-file 整文件先解析——链式 `===`（PHP 非结合）即全文件零输出；base64 豁免码实为 `DiscouragedPHPFunctions.obfuscation_base64_*` | `10` |
| C5 | 身份与会话 | ✅ | `Gr_Identity`（attribution 层：`gr_attr` 签名 cookie = `32hex . hmac-sha256(密钥=wp_salt 派生)`，`hash_equals` 验签、形状不符/验签失败即回退；`issue()` 仅在 `Gr_Consent::allows('marketing')` 通过时发送双 cookie——`gr_attr`（30 天，`attribution_cookie_days` 可调 1..365）+ `gr_session`（UUID，30 分钟滑窗）；标志位 HttpOnly/SameSite=Lax/Secure(is_ssl)/path=/；回退轨 = `sha256(wp_salt . 日期 . 域分隔符 . 匿名化IP . UA)`，跨天必然变化）+ `Gr_Consent`（privacy 层单检查点：DNT/Sec-GPC='1' 一票否决 → 有 WP Consent API 用之 → 无则回落 `marketing_consent_fallback` 设置开关，默认 0）+ `Gr_Privacy::anonymize_ip()`（IPv4 /24、IPv6 /48）+ `Gr_Session_Repository`（`INSERT ... ON DUPLICATE KEY UPDATE` 原子 upsert——落地属性仅首触写入、重复 touch 只滑 `last_active`/`pageviews+1`；`count_online()` 走 `last_active` 范围 COUNT，窗口钳制 30..3600s，cutoff 用显式 UTC DateTime 计算对运行时时区免疫）+ `Gr_Plugin` 接线 `identity()`/`sessions()` | 实测 2026-09-10：phpunit 110 tests 911 assertions（IdentityTest 14 例 + SessionRepositoryTest 4 例——含跨天旋转、/24 归一聚合、匿名化开关两态、DNT 否决、篡改 cookie 回退、落地白名单丢弃未知键）；:8091 实测——回退轨形状/稳定性/域分隔 ✓、签名 cookie 验证 ✓、篡改回退 ✓、**EXPLAIN type=range key=last_active（1200 陈旧行诱导，验收项实证）**、upsert insert=1/update=2/pageviews=2/落地 channel 保持、cleanup 1201 行删净后 online=0；**本站 WP 7.1 无 `wp_has_consent`** → 无 CMP 回落开关路径真站实测（denied→on=allowed→还原 denied）；前台 200，debug.log 1965 行零新增；坑位实录：`setcookie` 为内建函数全局不可重定义 → 命名空间级覆盖桩（`GreenPNG\Attribution\setcookie`）；stub 时钟覆盖未进 reset 跨用例泄漏（可覆盖旋钮统一 unset）；多行 prepare 字符串中 `{$table}` 行需把 ignore 注释放进参数列紧贴字符串行；`date()` 被嗅探禁止 → 显式 UTC `DateTime` 运算 | ADR-0007、`05` §3.2 |
| C6 | REST 采集端点 | ✅ | `Gr_Collect_Controller`（Rest 层，`rest_api_init` 接线）+ `Gr_Rate_Limiter`（Core 层）：permission 阶段三重防护（日盐令牌 `hash_equals`——`sign_hmac('collect\|日期', wp_salt 派生)` 非秘密跨日必变 → 每 IP 限流 60/60s `gr_collect_rate` 可调、`wp_using_ext_object_cache()` 真走 `wp_cache_add/incr` group `greenpng` 否则 per-key transient `gr_rl_collect_{md5(ip)}`、**被拒只读不写** → 8KB body）；严格 schema = 事件词表白名单（`pageview`/`signal`，`gr_collect_events` 过滤器可扩展）+ 字段白名单 9 键硬编码（**身份键不在白名单，服务端 `gr()->identity()` 双轨解析注入，客户端断言身份键按未知字段拒**）+ `signal` 要求 `bot_score` 整数 0..100 与四布尔标志、`probe_enabled=0` 时 400；`nocache_headers()`；token 刻意不标 `required`（核心必填 args 检查先于 permission 回调，401 归口 gate）；token 入口 `script_data()` 供 C13 enqueue | 实测 2026-09-10：phpunit 132 tests 1003 assertions（CollectControllerTest 17 例 + RateLimiterTest 4 例——transient/对象缓存双路径计数、键分散、被拒不写、401/429/413/未知事件名/未知字段/缺 bot_score/非布尔标志/probe 关闭/超长 path 全分支）；:8091 实测——无令牌 401 + 错令牌 401、超频 429（预置 ::1 键 60 后单发即拒、拒后计数停 60）、合法 JSON 8.3KB 413、未知事件名 400（args enum）、客户端 `visitor_id` 400 `field=visitor_id`、pageview/signal 200 `stored:true` + `Cache-Control: no-store` + `Content-Type: application/json`（sendBeacon 形态闭环）；SQL 预算 SAVEQUERIES 实测 6 条/请求（裸主机）/ 有对象缓存 3 条（见 `09` §1.1 落地记录与 `13` §8 张力待议）；清理 events 10→0 sessions 2→0 transients 0、debug.log 剔除探针残留 1988→1967（备份留档）；坑位实录：核心必填 args 先于 permission 回调 → token 不标 required 否则无令牌得 400 而非 401；`rest_do_request` 探针须显式 `set_header('Content-Type','application/json')` 否则核心不解析 JSON body；curl 本机回环走 IPv6 `::1`（限流键 ≠ `127.0.0.1`）；SQL 直删 transient 残留过期 `_transient_timeout_` 行会毒化后续 `set_transient`（add_option 失败 → get_transient 判过期删新值） | `02` §2.5、`09` §1.1、`11` §3.1 |
| C7 | 归因监听 | ✅ | `Gr_Attribution_Listener`（attribution 层，`template_redirect@10` 经 `Gr_Plugin` 接线，`listener()` getter）+ `Gr_Attribution_Params`（纯解析器：utm 五键 + gclid/gbraid/wbraid/msclkid→`cpc`、fbclid/ttclid→`social` 点击 ID 择一、utm_medium 词表映射 channel 闭合词表、`apply_referrer()` 纯函数把 direct 升级 referral——自家域排除归监听器）+ `Gr_Touchpoint_Repository`（storage 层：`record()` 全列宽截断 insert、`get_for_visitor()` 窗口钳制 1..365 天 + 显式 UTC cutoff + LIMIT 500 + `visitor_time` 索引序读）+ 门面 `gr_parse_attribution_params`/`gr_record_touchpoint`/`gr_get_touchpoints`。门控：`attribution_enabled` 管所有营销写（cookie/触点/会话落地属性）；`Gr_Consent::allows('marketing')` 一票否决（DNT/Sec-GPC）；**技术性会话滑动（last_active/pageviews）不受门控**——在线数是聚合计数；`Gr_Identity` 增请求内备忘（`issue()` 后同请求读 `visitor_id()/session_id()` 见 cookie 轨身份，修复入口触点落回退轨的结构性断裂） | 实测 2026-09-10：phpunit 149 tests 1093 assertions（AttributionParamsTest 7 + TouchpointRepositoryTest 3 + AttributionListenerTest 9——含无同意零 cookie 零触点、DNT 否决回落开关、直接访客发 cookie 不落触点、disabled 抑制营销写、纯引荐入触点、自家域不算、**跨天同 visitor_id（时钟旋钮）**、列宽截断、窗口序读 SQL 形状）；:8091 实测——DNT 请求零 gr_ cookie + 会话行 channel direct 零营销字段（**无同意不写 cookie 不落触点实证**）、同意入口双 Set-Cookie（gr_attr+gr_session）、触点行 channel=cpc/gclid/landing_url、会话行带落地属性且二访 pageviews=2 同 visitor_id（含 404 页与 cookie 回放两链）、**归因落地 SQL=3 条**（会话 upsert 1 + 触点 insert 2，≤4 预算达标，规则/cookie 0 SQL）；清理 touchpoints 3→0 sessions 4→0、回落开关还原 0、debug.log 1974→1967（备份留档）；坑位实录：测试 tearDown 不得 `unset($_GET/$_COOKIE)`（删全局变量本身 → 后续用例 Undefined variable，应赋空数组）；单测环境有 `wp_has_consent` 桩 → `Gr_Consent` 走桩而非回落开关，测试要用 `gr_stub_consent`；多行 SQL 字符串 `{$table}` 必须在首字符串行（ignore 只覆盖紧邻行，够不到字符串内部）；wp-cli 进程内驱动 `issue()` 走真 `setcookie` 产生 headers 警告（HTTP 语境不存在）；eval-file 探针别 unset `REQUEST_URI`（wp-cli cron shim 崩）也别 `(string)` 强转 SAVEQUERIES 行（是数组） | `02` §4、ADR-0005/0007 |
| C8 | 归因参数与模型 | ✅ | `Gr_Attribution_Models`（attribution 层纯静态）：`calculate(touchpoints, amount)` 一次算齐 5 模型——first/last（单点全取）、linear（均分）、position-based 40/20/40（n=1 全取、n=2 五五、n≥3 首尾各 40% 中间均分 20%）、time-decay（权重 `0.5^(age_days/7)` 相对最新触点，7 日半衰）；**分币对账**：逐触点 floor 到分、缺分按权重降序（平手给更晚触点）补齐 → 每个模型合计恒等于转化金额（含 `round(…,6)` 护浮点尘埃再 floor——0.4+0.1+0.1+0.4 二进制和为 1.0000000000000002 曾把 20.00 floor 成 19.99）；输入内排序（created_at, id）使调用方顺序不影响结果；空序列/单触点退化路径全覆盖；门面 `gr_calculate_attribution()`。**`gr_parse_attribution_params()` 已随 C7 落地**（`Gr_Attribution_Params` 纯解析器 + 渠道闭合词表，见 C7 行） | 实测 2026-09-10：phpunit 159 tests 1138 assertions（AttributionModelsTest 10 例——5 模型 × 手工序列全对：三触点 14d/7d/0d 的 time-decay 权重恰 1/7、2/7、4/7、金额 14.28/28.57/57.15；linear 33.33/33.33/33.34；position 40/20/40 与四触点 80/20/20/80；退化 n=1 全模型 99、n=2 五五；乱序输入与正序 assertSame；同时间戳按 id 决 first；空序列全空；门面转发）；:8091 实测——`gr_calculate_attribution()` 真站可调，canonical 序列五模型输出与单测逐位一致、各模型 sum=100；debug.log 1967 零新增（纯计算无落库）；坑位实录：浮点和尘埃必须 round(,6) 后再 floor（19.99 vs 20.00） | `03` §4 |
| C9 | 转化幂等绑定 | ✅ | `Gr_Conversion_Repository`（storage 层：`bind()` = `INSERT IGNORE` 打 `source_unique` UNIQUE 键 + insert_id>0 直返 / 否则按 source 回查既存 id——重放恒返同 id；amount 两位小数字符串化、currency 截 3 位大写、source_type 截 16；`id_for_source()` 点查）+ `Gr_Attribution_Service`（attribution 层组合：`get_for_visitor(30 天回看)` → `Gr_Attribution_Models::calculate()` 五模型随行 `model_weights` JSON + first/last_touch_id（直连 0/0 + 空模型）+ 当前 `identity()->session_id()` 侧行接）+ 门面 `gr_bind_conversion(order_id, visitor_id, amount, currency, source_type='woocommerce')`（可选第 5 参供 C11 表单源；**调用方持有同意门**——v1.0 适配器仅传 cookie 轨 id） | 实测 2026-09-10：phpunit 163 tests 1167 assertions（ConversionBindingTest 4 例——新绑定 INSERT IGNORE 形状/五模型随行/两位小数/币种归一、重放双查同 id（计数×2 因桩 prepare+执行双记录）、直连 0/0+空模型、门面 4 参文档签名）；:8091 实测——探针触点 + 同一 order 双绑定 **first_bind=1 second_bind=1 same_id=true conversion_rows=1（真 MariaDB UNIQUE 幂等实证，双回调仅一条记录）**、行内 amount '100.00'/currency 'USD'（小写入参归一）/first=last=touch_id/model_weights 五模型 JSON（单触点全模型 weight 1）；直连访客绑定 touch 0/0 + 五空模型 + fluentform 源类型；清理 2+1 行删净、debug.log 1967 零新增；坑位实录：INSERT IGNORE 被吞的插入仍消耗自增值（1→跳 2→3，非缺陷）；wpdb 桩 prepare 会 addslashes JSON 引号（断言要带 `\"`）；门面新仓储必须同步 use 导入（类名解析无全局回退） | `05` §3.3、`11` §3.2 |
| C10 | WooCommerce 适配器 | ✅ | `Adapter_Interface`（integrations 层契约：get_id/is_available/register_hooks）+ `Gr_Woocommerce_Adapter`（ecosystem 子层，实现接口）：**三挂载** = `woocommerce_checkout_update_order_meta`（经典，$order_id+$posted）+ `woocommerce_store_api_checkout_update_order_from_request`（Blocks/Store API，$order+$request——WC 11.1 实核存在）+ `woocommerce_payment_complete`；**HPOS 安全写法** = `wc_get_order` + `update_meta_data`/`get_meta` + `save()`（零 post meta 分支，两栖 posts/HPOS store）；`capture_visitor` 仅存 **cookie 轨** visitor_id（回退轨无跨天价值，ADR-0005）且 write-once（双路径先到先得）；`bind_order` = meta 锁 `_gr_attributed` + gr_conversions UNIQUE 双防线、无捕获时回落当次请求 cookie 身份、`Gr_Consent` 门控；每个回调 `\Throwable` 隔离 → `do_action('gr_adapter_error', id, $e)` 静默放行（§2.7 fail-open）；`is_available` = `class_exists('WooCommerce', false)`（公开面探针，无版本锁定/样本匹配，铁律 6）；Gr_Plugin 接线：`class_exists('WooCommerce', false)` **先于** 适配器类引用 → 无 WC 站点连类文件都不加载 | 实测 2026-09-10：phpunit 172 tests 1193 assertions（WooCommerceAdapterTest 9 例——**absent 目标零挂载 + Gr_Plugin 级跳过**（标记类未定义态、方法序 absent-first）、present 三挂载、经典/Store API 双形状捕获、双路径 write-once、回退轨不落单、支付绑定一次+锁、重放锁先于任何写、无同意跳过且不锁、爆炸 order 下 Throwable 隔离 + gr_adapter_error 上报）；:8091 实测（WC 11.1.0，**HPOS 功能在位但站点走 posts store**——写法两栖如实记录）——真钩子经典路径 `_gr_visitor_id` 落成、Store API 路径（真 `WP_REST_REQUEST`）write-once 不改写、`woocommerce_payment_complete` ×2 → `_gr_attributed`='1' + **conversion_rows=1（锁+UNIQUE 双防线真站实证）**、conversion 行 source=woocommerce/订单号/amount '100.00'/USD；清理订单 delete(true)+conversion 删净+回落开关还原 0；debug.log 剔除探针 fatal 块 1990→1967（备份留档）；坑位实录：**Woo 自带的 Store API 监听器（OrderAttributionBlocksController）对 null request 直接 fatal**——探针必须传真 `WP_REST_Request`（我们适配器对 request 参数零依赖故无恙）；直发 `do_action('woocommerce_payment_complete')` 只走钩子链不走 Woo 方法级状态流转（order status 保持 pending，对监听器验证无碍）；present 态测试用 eval 定义标记类 + absent 用例必须排前（进程内类定义不可撤销） | `02` §2.6、ADR-0007、`14` §1 |
| C11 | 表单适配器 | ⬜ | Fluent Forms / CF7 / WPForms 三个适配器（主 Hook + 回退 Hook，漂移告警） | 主验证站实测 Fluent Forms；CF7/WPForms（E9 装入后）各实测；未安装时零加载 | `12` v1.0、ADR-0007 |
| C12 | 语义提取与生态检测 | ⬜ | `gr_uif_extract_fields()` + `gr_uif_detect_ecosystem()` | 夹具样本（含 `auto:email` 等）提取正确 | `03` §7 |
| C13 | 客户端安全探针 | ⬜ | `gr-probe.js` 安全模块（v1.0 部分）：`navigator.webdriver`、WebGL 渲染器类别（SwiftShader/llvmpipe/Software）、无头窗口特征、语言栈异常 → bot_score；**默认开、合法利益、设置页可关、readme 披露**；仅结论值经 C6 回传；无持久标识符、无指纹串 | 禁 JS 后站点正常；探针 gzip ≤8KB；bot_score 服务端校验拒绝越界值；关闭开关后零输出 | ADR-0007、`08`、`09` §1.1 |
| C14 | A/B 分流引擎 | ⬜ | `gr_ab_assign_variant()` 一致性哈希稳定分桶 + URL 参数（`?gr_variant=`）+ 短码分流；实验定义存非 autoload option | 同 visitor 多次请求分桶稳定；URL 参数强制分桶可测 | `03` §5、`05` §2 |
| C15 | A/B 记录与显著性 | ⬜ | `gr_ab_record()`（impression/conversion 落 `gr_events`）+ `gr_ab_significance()`（双比例 Z 检验，n<30 返回样本不足） | 手工期望值单测；显著/不显著/样本不足三态正确 | `03` §5、`11` §3 |

## 4. Phase 3 — 流量安全与 RPA 识别（保守档：默认仅记录不拦截）

| ID | 任务 | 状态 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- | :--- |
| W1 | 请求检查器 | ⬜ | Security_Request_Inspector（`init@10`，默认仅记录） | 检查器异常时静默跳过，前台渲染不受影响 | `02` §2.7 |
| W2 | UA 引擎升级 | ⬜ | `gr_is_scanner_ua()` 数据层升级：以 CrawlerDetect（MIT，1492 行规则实核）规则为种子本地化为自维护数据文件（保留 MIT 版权头 + NOTICE）；Exclusions 白名单短路；**无外呼** | 夹具 UA 全命中且白名单不误伤；单请求匹配耗时实测入报告；数据文件入包 | `07` §3、`14` §1 |
| W3 | CIDR 匹配 | ⬜ | `gr_match_cidr()`（IPv4 位运算 + IPv6 前缀） | /32、/0、/24 跨界与 IPv6 前缀单测全过 | `11` §3.1 |
| W4 | 访问规则 | ⬜ | `gr_access_rules` 仓储 + `gr_is_trusted_ip()` / `gr_is_ip_blocked()` / `gr_is_url_allowed()`；`plugins_loaded` 读一次 L1 静态缓存 | 前台稳态总 SQL 在分层预算内（`09` §1.1） | `05` §2、`09` |
| W5 | 临时封禁 | ⬜ | `gr_block_ip()` / `gr_unblock_ip()` | transient TTL 生效；WP-CLI 可解锁 | `03` §3 |
| W6 | 浪涌折叠日志 | ⬜ | `gr_log_security_event()`：fold_key = md5(ip+rule+小时窗)，**MySQL 原生 `INSERT ... ON DUPLICATE KEY UPDATE` 原子自增**（ADR-0007，弃可移植写法） | 并发 N 次命中 = 1 行且 `hit_count=N`（实测断言行数，不宣称压缩率）；并发压测无 1062 错误 | `05` §3.1、`11` §3.1 |
| W7 | 登录保护 | ⬜ | `gr_check_login_lockout()` / `gr_record_login_failure()`（`wp_login_failed`；梯度递增锁定） | 阈值、递增锁定时长、允许列表恢复单测；默认仅记录模式明确 | `03` §3、PEER-01 |
| W8 | 蜜罐（opt-in） | ⬜ | `gr_render_honeypot()`（动态混淆字段名 + `aria-hidden="true"` + `tabindex="-1"`）+ `gr_check_honeypot()`（陷阱字段非空 **或提交耗时 <2s** 判自动化，默认仅记录） | 默认关；开启后登录/注册表单生效；屏幕阅读器不朗读；时间差夹具单测 | `03` §3、`06` §4、PEER-01 |
| W9 | 载荷检查 | ⬜ | `gr_inspect_request_payload()` 保守高置信规则集 | 命中默认仅标记不拦截；误报样本不命中 | `03` §3、`10` §4 |
| W10 | FCrDNS | ⬜ | `gr_verify_crawler()`（PTR + 正查含 DNS_AAAA；24h transient 缓存；失败=「无法验证」） | **绝不在前台请求路径同步 DNS**：首见入队（Gr_Queue），日维护任务/工具页触发验证 | `03` §3、铁律 3 |
| W11 | 安全总熔断 | ⬜ | `gr_settings` 安全总开关 + `GR_SECURITY_OFF` 常量紧急逃生 | 开启后全部 inspect 跳过 | `10` §4 |
| W12 | Blackhole 陷阱（opt-in） | ⬜ | robots.txt 声明陷阱路由 + 虚拟陷阱端点；命中记日志 + 可选封禁（默认关、默认仅记录） | 合法蜘蛛（遵守 robots.txt）不受影响；默认关闭；开启后命中实测 | PEER-01、`10` §4 |
| W13 | 安全→质量结论通道 | ⬜ | `gr_event` 携带安全**结论**（`suspected_bot` 布尔 + 分数档位）；v1.1 CRM 消费打标 `sys:suspected_bot`；**原始信号/指纹明细永不进联系人画像** | 事件payload 仅含结论字段（schema 断言）；单测 | ADR-0007、`07` §4 |
| W14 | 完整 IP 与脱敏 | ⬜ | `gr_security_logs` 存完整 `VARBINARY(16)`（`inet_pton`）；后台展示默认脱敏（末段遮蔽）；`gr_security_log_anonymize` 开关（默认 0=完整存储，开启则入库存截断值且 UI 明示封禁降级为网段级） | 完整/匿名两模式入库实测；展示脱敏截图自查；封禁联动实测 | ADR-0007、`05` §3.1、`10` |

## 5. Phase 4 — 汇总与后台页面（v1.0 页面子集 = 14 页）

v1.0 页面：Dashboard、Traffic & Security（3 标签）、Access Rules、Login Protection、Bot & Device Signals（爬虫验证标签）、Campaigns（4 标签）、URL Builder、Funnels & Goals（A/B 标签先行）、Settings（独立页）、Analytics & CAPI、IP Intelligence（GeoIP 部分）、Audit Log、Data Retention、Status & Diagnostics。其余 6 页随 v1.1/v1.2 交付。

| ID | 任务 | 状态 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- | :--- |
| U1 | 日汇总 | ⬜ | `gr_daily_stats` 聚合任务（Gr_Queue 每日；UNIQUE 键幂等 upsert） | 瘦身后报表读数不变（单测）；重复执行不翻倍 | `05` §1 |
| U2 | 图表库 | ⬜ | uPlot 本地打包（≤20KB min + 未压缩源码同目录） | 仅图表页 enqueue；零 CDN | `06` §2.3 |
| U3 | Dashboard | ⬜ | KPI + 趋势图 + 国家分布（DB-IP 开箱即有）+ `screen-reader-text` 表格视图 | 只读 `gr_daily_stats`，零直查原始表 | `06`、I5 |
| U4 | gr-datagrid.js | ⬜ | 由参考项目组件修复移植：fetch + `X-WP-Nonce`；`textContent` 替代 innerHTML；无 JS 服务端降级 | XSS 注入夹具不执行；禁 JS 后页面可用 | `06` §2.2 |
| U5 | Traffic & Security | ⬜ | 实时流 / 威胁事件 / 欺诈审计 3 标签 | 全部 WP 原生组件；只读 REST 亦需 `manage_options` | `06` |
| U6 | Access Rules | ⬜ | 封禁 / 允许列表（`WP_List_Table` + Settings 表单） | 每个写操作 nonce + 能力双校验 | `06` §2.1 |
| U7 | Login Protection | ⬜ | 爆破审计 + 会话管理 | CLI 解锁指引在页面可见 | `06` |
| U8 | Campaigns | ⬜ | 战役 / UTM / 点击ID / 归因模型 4 标签 | 归因对比只显示实测计算值 | `06` |
| U9 | URL Builder | ⬜ | 本地 UTM 链接构建器 | 零外呼；输出 `esc_url` | `06` |
| U10 | Audit Log | ⬜ | `gr_audit_diff()` / `gr_audit_log()` / `gr_audit_query()` + 页面 | 递归 diff 单测；列表分页服务端 | `03` §8 |
| U11 | Data Retention | ⬜ | 保留期/行数上限设置 + 手动 OPTIMIZE 按钮 | cron 绝不自动 OPTIMIZE | `05` §5 |
| U12 | Status & Diagnostics | ⬜ | `gr_export_diagnostics()`（脱敏导出）、`gr_get_table_stats()`、适配器状态、**队列后端显示（AS / WP-Cron）与真实 cron 引导** | 导出含 PHP/WP/DB 版本，无任何凭据/敏感值 | `03` §10、`09` §4 |
| U13 | **Settings 独立页**（OQ-1 决议） | ⬜ | 顶级菜单第一子页；标签：General（隐私默认/数据保留入口）、Security（安全总开关/可信代理/安全日志匿名化开关/探针开关）、Attribution（cookie 窗口/默认模型）；Settings API + nonce | 每个设置写操作双校验；默认值与 ADR-0007 一致；探针开关实测生效 | ADR-0007、`06` |
| U14 | Bot & Device Signals | ⬜ | 爬虫验证标签：FCrDNS 结果、UA 引擎统计、bot_score 分布（v1.0）；设备信号标签 v1.3 再交付 | 页面 WP 原生；数据只读汇总表 | `06`、`12` |
| U15 | Analytics & CAPI 页 | ⬜ | Meta / GA4 凭据录入（Secrets 加密）+ 连通性自检按钮 + 同意门控与披露文案 | 未配置时明确"未配置"；凭据不明文回显 | `07` §5 |
| U16 | IP Intelligence 页 | ⬜ | GeoIP（DB-IP）管理：归属披露、数据版本显示、**显式"立即更新"按钮**（opt-in 外呼）；AbuseIPDB 区块 v1.2 启用 | 更新按钮需 nonce + 点击才外呼；无任何静默外呼 | `07` §5.7、铁律 1 |
| U17 | Funnels & Goals 页（v1.0 部分） | ⬜ | A/B 标签：实验列表 / 分流预览 / 显著性结果；漏斗/目标标签 v1.1 | A/B 数据读 `gr_events` 聚合；Z 检验结果三态正确显示 | `06`、`03` §5 |

## 6. Phase 5 — 出网支柱与隐私合规

| ID | 任务 | 状态 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- | :--- |
| I1 | Http_Client | ⬜ | `GreenPNG\Core\Http_Client`：每服务独立配置（timeout≤5s、连续 3 次失败熔断 300s、429 读 Retry-After、退避 30s/2m/15m 三次后放弃记审计）；`wp_safe_remote_*` | 未配置返回 `gr_not_configured`；熔断状态机单测；全站无绕过此类的直接出网 | `07` §2 |
| I2 | Meta CAPI | ⬜ | 版本常量 `GR_META_API_VERSION` + filter；PII 经 `gr_hash_pii()`；**仅回传通过流量质量过滤的事件**；经 `Gr_Queue` 异步派发 | 凭据未配置零外呼；payload 构建纯本地可单测；重试路径实测 | `07` §5.1 |
| I3 | GA4 MP | ⬜ | 无 `_ga` client_id 不回传；debug 端点连通性自检按钮 | 自检按钮显式触发；垃圾会话数据防御单测 | `07` §5.2 |
| I4 | event_id 双端去重 | ⬜ | 浏览器 Pixel 事件与服务端 CAPI 使用相同 `event_id`（`gr_generate_event_id` 派生） | 同一转化双端同 ID 实测；文档化 48h 去重机制（不承诺 EMQ 分数） | `07` §5.1、PEER-02 |
| I5 | GeoIP 开箱 | ⬜ | DB-IP Lite 国家库数据文件随包分发（CC BY 4.0，readme + NOTICE 归属声明，数据日期标注）；`gr_geoip_country()` 查询（每请求 L1 静态缓存）；站长显式更新按钮 | 激活后 Dashboard 国家分布非空（实测样本 IP）；查询零外呼；更新按钮 opt-in 外呼披露 | `07` §5.7、ADR-0007 |
| V1 | 隐私双轨落地 | ⬜ | 安全轨：完整 IP + 探针信号（合法利益、展示脱敏、开关）；营销轨：同意门控 + IP 匿名化默认开（营销数据） | 两轨数据流分别实测；设置变更即时生效 | ADR-0007 |
| V2 | WP 隐私 API | ⬜ | 导出器/擦除器注册（会话/触点/转化/联系人） | WP 核心隐私工具界面可见并可执行 | ADR-0005 |
| V3 | 瘦身执行 | ⬜ | `gr_prune_table()`（分批 2000 + 100ms 间隔 + 10s 时间预算） | 构造超龄数据实测删至保留线 | `03` §10、`05` §5 |
| V4 | readme 披露定稿 | ⬜ | `== External services ==` 逐项：Meta CAPI / GA4 MP / DB-IP 更新按钮 /（v1.2 预告：AbuseIPDB、蜘蛛段订阅）；另设隐私披露段：探针（安全用途、合法利益、可关）、安全日志完整 IP 依据 | 与 `07` 清单逐项一致；`08` §3/§4 专项自查通过 | `07`、`08` |
| V5 | 同意门控复核 | ⬜ | 营销链路 `wp_has_consent` 检查点清单化；安全链路开关化 | 逐点实测开/关两态行为 | ADR-0005/0007 |

## 7. Phase 6 — 测试收口与发布准备

| ID | 任务 | 状态 | 交付物 | 验收标准 | 依据 |
| :--- | :--- | :--- | :--- | :--- | :--- |
| T1 | 单元测试集 | ⬜ | CIDR / 归因 5 模型 / 浪涌折叠并发 / 登录锁定 / 提取器 / 递归 diff / 参数解析 / **A/B 分流稳定性与 Z 检验 / UA 引擎命中 / 队列互斥** | 核心域 ≥70% 门槛；全绿 | `11` §3 |
| T2 | 集成测试集（含常用插件矩阵） | ⬜ | 采集端点五情形 / WC 双回调幂等 / **HPOS 开×关 × 经典/Blocks 结账四组合** / CF7、Fluent Forms、WPForms 桥接 / Elementor 前台共存 / 缓存插件下 collect 端点 nocache / dbDelta 幂等 / 卸载两模式 | MySQL 主验证站实测全绿；矩阵结果如实记录 | `11` §3/§5、ADR-0007 |
| T3 | 性能基准 | ⬜ | `tests/benchmarks/front-request.php` 装前/装后 P95 对比 | **分层预算口径**（`09` §1.1：稳态 ≤2 SQL、归因落地 ≤4、collect 独立口径）；数字写入报告 | `09`、`11` §6 |
| T4 | PHPStan | ⬜ | level 6 + 基线文件 | 零未处理错误；**零动态属性赋值** | `11` §2、ADR-0007 |
| T5 | 兼容性静态检查 | ⬜ | PHPCompatibilityWP（testVersion 7.4-） | 零 PHP 8+ 语法；`str_*` 核心函数不误报（polyfill 白名单，S9 已确认） | ADR-0003/0007 |
| T6 | 生命周期冒烟 | ⬜ | activate / deactivate / uninstall / 升级幂等 | 主验证站 WP-CLI 实测 | `11` §1 |
| T7 | 常用插件共存冒烟（原 SQLite 冒烟任务已废止） | ⬜ | E9 插件集 + 已装 9 生态插件全激活跑 T6 子集 | 共存结果如实记录；适配器漂移告警可见 | ADR-0007、`11` §5 |
| T8 | 版本与打包 | ⬜ | `tools/bump-version.sh` → 1.0.0；`tools/build-zip.sh` | 版本三处同步；包内无 vendor/测试/文档；**DB-IP 与 CrawlerDetect 数据文件入包且 NOTICE/归属就位** | `02` §5、`08` §8 |
| T9 | WP.org 自查 | ⬜ | `08` 清单逐项打勾记录（含新增：探针披露、完整 IP 披露、打包数据归属） | 全项通过记录在案 | `08` |
| T10 | 提交上线 | ⬜ | WordPress.org svn 提交（人工执行） | — | `08` |

## 8. 决议记录（原开放问题已全部收口）

| 编号 | 决议（2026-09-09） | 落点 |
| :--- | :--- | :--- |
| OQ-1 | 独立 Settings 页设立，页面上限解除（19→20 页，去重纪律保留） | ADR-0007 / `06` / U13 |
| OQ-2 | MySQL-only，不做 SQLite 兼容承诺；浪涌折叠用 MySQL 原生 upsert | ADR-0007 / `05` / W6 |
| OQ-3 | WP 6.0 下界维持；兼容成本被证实过高时经复审上调（触发点已记录于 ADR-0007） | ADR-0007 |
| OQ-4 | 测试矩阵覆盖站长常用插件（E9 补装 + T2/T7 矩阵） | ADR-0007 / `11` |
| Q-安全IP | 完整 IP 存储 + 展示脱敏 + 匿名化开关（默认完整） | ADR-0007 / `05` / W14 / V1 |
| Q-访客身份 | cookie visitor_id（30 天，同意门控）为主 + 无 cookie 每日盐回退 | ADR-0007 / `05` / C5 |
| Q-任务队列 | `Gr_Queue` 自适应（AS 嗅探 → WP-Cron + 互斥锁回落）；不打包 AS | ADR-0007 / `02` / S7 |
| Q-v1.0 范围 | **四支柱首发**：探针 / A-B / CAPI / GeoIP 全进 v1.0 | ADR-0007 / `12` / 本表 |

仍开放（非 v1.0 阻塞）：DB-IP 数据月度更新与构建流程（T8 前定稿）；Turnstile 渐进阈值（v1.2）；设备信号标签独立隐私评审（v1.3）；**C6 实测暴露**——`09` §1.1 REST collect ≤3 条口径与 `09` §3"约八成用户无对象缓存 + transient 兜底"的内部张力（无缓存主机实测 6 条、其中 §3 指定机制固有 3 条，下限 5 条；可选解：`09` §1.1 行为无缓存主机单列口径，或事件仓储改 prepare 直写省 1 条后仍 5 条，T 阶段统一测量时定夺，见 `09` §1.1 落地记录）。

## 9. 提交与验收纪律

- 一任务一提交，提交信息 `feat|fix|docs: <摘要>`；不提交 `vendor/`（运行时）与构建产物（AGENTS §7）。
- 每提交前跑 AGENTS §3.3 四项检查；验收标准一栏的数字必须来自实测并写入提交说明。
- 本清单的状态列是唯一进度真源；与其他记录冲突时以本表为准。
