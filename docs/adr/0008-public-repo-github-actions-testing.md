# ADR-0008: 公共仓库 GitHub Actions 测试体系（wp-greenpng）

- 状态：已接受
- 日期：2026-09-10

## 背景

ADR-0006 第 5 条预留了 CI 矩阵（`docs/11` 原案：wp-env/Docker；PHP 7.4/8.1/8.3 × WP 6.0/latest × MySQL 5.7/8.0），条件是「仓库具备远端 CI」。v1.0 开发完成后（`docs/13` T1–T9 ✅），站长决策：在 GitHub 公共仓库 `wp-greenpng` 上落地该矩阵，版本自 v1.0.0 起；要求覆盖 PHP/WP 版本兼容、安全、以及模拟各类顾客的全功能 E2E（Playwright、单元、API、E2E），并遵守免费 runner 使用限制（不多开 runner）。

GitHub 免费额度事实：公共仓库标准 Linux runner 分钟数免费且不限量，真正的硬约束是**同仓库并发 job 上限 20**；macOS runner 对公共仓库不免费。因此预算设计目标从「省分钟」改为「控制峰值并发与镜像拉取量」。

本地环境无法承接该矩阵的部分（ADR-0006 已记录）：PHP 7.4 运行时、WP 6.0 下界、MySQL 5.7/8.0 差异、以及 dev 站插件目录为 symlink 导致的卸载臂无法真跑（T2/T6 均明记「CI 收尾」）。公开化前置安全检查已完成：已知凭据值与通用密钥模式对全 git 历史与工作树零命中。

## 决策

1. **仓库**：`greenpng/wp-greenpng` 公共仓库，推送现有历史与 v1.0.0 标签；仓库内容即本仓库（含 docs 与 ADR），不含任何站点文件/凭据（`greenpng-dev/` 全程在仓库外）。新增根级 `README.md`（面向公众）与 `package.json`（仅 devDependencies：Playwright 与 @wordpress/env，无运行时依赖，ADR-0003 不受影响）。

2. **四条工作流**（`.github/workflows/`，均设 `concurrency` 组 + `cancel-in-progress`）：
   - `unit-matrix.yml`：纯 PHP 单元矩阵 **7.4 / 8.0 / 8.1 / 8.2 / 8.3 / 8.4 / 8.5**（7 job；stub 化、不需要 WordPress）。8.4/8.5 为实验位（`continue-on-error`），因为上游工具链对 8.5 的支持声明滞后。
   - `static-security.yml`：3 job——`standards`（phpcs + PHPCompatibilityWP 7.4- 顺序合并，附 node 跑 `tests/js` 组件测试）、`phpstan`（标准 PHP 下直接 `vendor/bin/phpstan analyse`，绕开本机 FrankenPHP 包装脚本）、`gitleaks`（全史密钥扫描，公开仓库个人用途免费）。
   - `wp-integration.yml`：`docs/11` 原案全交叉矩阵 **PHP {7.4, 8.1, 8.3} × WP {6.0, 7.1}**（6 job，wp-env/Docker；下界格 (7.4,6.0) 附加 MySQL 5.7 镜像 override，其余默认 8）。`tests/integration/run.sh` 断言：15 表建齐、dbDelta 二次安装零漂移、collect REST 全臂（401/400/413/200/nocache）、限流 429、生命周期（停用保数据/重启调度恢复/设置保真）、**卸载双模式**（保数据 + 删数据，T2/T6 遗留臂就此闭环——CI 里插件是副本挂载而非 symlink，卸载可安全真跑，跑前在 runner 内再留一份源码副本以两模式连跑）。
   - `e2e-playwright.yml`：1 job，wp-env（WP 7.1 + PHP 8.1 + WooCommerce + Contact Form 7 官方 zip），Playwright Chromium 跑 `tests/e2e/`。顾客模拟场景：首次访客、同意门控（gr_attr/gr_session cookie 与 DNT 不落库）、采集 API（token 提取 + 401/400/200 臂）、爬虫 UA（Bot Signals 出结论）、后台设置（探针开关即时反映到前台嵌入）、Access Rules 增删、URL Builder 产出链接、CF7 表单转化入数、Woo 下单支付归因、隐私政策建议文案注册。

3. **峰值并发预算**：4 工作流同发时最多 7+3+6+1 = **17 个并发 job < 20**（GitHub 免费档上限）；只用 ubuntu runner（公共仓库免费）；不使用 macOS/Windows runner；Playwright 浏览器与 npm 依赖走 actions/cache；wp-env 镜像无缓存（每次拉取，分钟数在公共仓库免费，接受代价）。

4. **门禁口径**：push（main 与 v* 标签）、pull_request、workflow_dispatch 全触发；实验位（8.4/8.5 单元）不计入门禁。CI 失败即修复迭代，不降低断言强度来换取绿灯（`AGENTS.md` §3.4）。

## 后果

- 正面：PHP 7.4 与 WP 6.0 下界首次获得**运行时**证据（此前仅静态保证，ADR-0006 明记的缺口闭合）；卸载两模式在真实 `wp plugin delete` 下闭环（T2/T6 遗留臂）；安全扫描（gitleaks）随每次推送持续复查公开面；E2E 顾客模拟覆盖「同意/拒绝、爬虫、下单、填表、后台操作」五类真人行为。
- 代价：wp-env 矩阵每格拉取 Docker 镜像约 3–5 分钟（免费但慢）；MySQL 5.7 override 依赖 wp-env 的 override 机制，如上游格式变动需跟进；E2E 对 WooCommerce store API 的依赖意味着 Woo 大版本重构可能需改剧本；公开仓库使 docs/ 中文文档对公众可见（内容本为项目自身文档，无凭据，已扫描确认）。
- 复审触发点：GitHub 免费政策变更（公共仓库 runner 收费或并发上限下调）；PHP 8.5 工具链成熟后转正实验位；wp-env 弃用时。

## 勘误与修正记录（2026-09-13，首轮实测后）

wp-env 的配置模式**不接受 `services` 键**（`.wp-env.override.json` 报 "services is not a configuration option"），即 wp-env 没有数据库版本旋钮——原第 2 条「下界格用 override 换 mysql:5.7」不可行。修正：MySQL 5.7 地板证明移入独立工作流 `wp-floor-mysql57.yml`，脱离 wp-env，直接用官方 `wordpress:6.0-php7.4` + `mysql:5.7` + `wordpress:cli-php7.4` 镜像的 compose 栈（`tests/integration/docker-compose.floor.yml`）；`tests/integration/run.sh` 经 `CLI_PREFIX` 参数化后两种环境共用全部断言臂。集成矩阵随之改为 5 格（PHP 7.4/8.1/8.3 × WP 7.1 + 8.1/8.3 × WP 6.0，MySQL 8 由 wp-env 默认携带），峰值并发 7+3+5+1+1 = 17，仍在 20 以内。

首轮实测同时揪出两处真实生命周期缺陷（停用与删除式卸载均遗留 gr_ 命名空间的待执行 wp-cron 单次事件，违反两类各自文档声明的「不留工作/干净重来」承诺），修复入 `Gr_Queue::clear_plugin_cron()`（停用清扫 + 卸载清扫共用）——CI 的价值即在此：dev 主站因 WooCommerce 携带 Action Scheduler，单次事件从不落入 wp-cron，该缺陷在本地环境不可复现。

## 勘误与修正记录（2026-09-13，第七、八轮实测后）

E2E 首轮产物（Playwright error-context 页面快照）证实两处真实缺陷并已修复：其一，两个 `WP_List_Table` 子类（访问规则、审计日志）在真实后台渲染为空表——核心在未声明表头时从已注册列表屏解析列头，而本插件页面未注册任何屏，构造器现显式声明 `_column_headers` 元组；其二，全新 WooCommerce 默认以「Store coming soon」占位页替换未登录访客的前台（即全部模拟顾客），seed 现显式关闭该模式。运行时桩同步对齐核心语义（`WP_List_Table` 桩改经 `get_column_info()` 解析，空表头即空渲染），延续「桩不许替被测物遮丑」的既定原则。

第八轮定案：wp-env 的 PHP 7.4 镜像构建在其生成的 Dockerfile 内部执行 `apt-get install $PHPIZE_DEPS`，而基底镜像为 **EOL Debian bullseye**，内置 apt 索引已陈旧（`dpkg-dev_1.20.14` 现于 deb.debian.org 404），且该 RUN 行无法经任何配置项注入 `apt-get update`——上游不可修。修正：移除 (PHP 7.4 · WP 7.1) wp-env 矩阵格；PHP 7.4 运行时证明保留在 compose 地板工作流（`wordpress:6.0-php7.4` 官方镜像、无构建步骤、全断言臂），矩阵定格 4 格（PHP 8.1/8.3 × WP 6.0/7.1）。地板栈卸载臂的「插件应从列表消失」检查在恢复重拷后仍见 inactive 条目（wp-env 各格全过、floor 独有），`run.sh` 已插桩取证：delete 退出码与输出、容器内 `id`/目录清单、`active_plugins`、以及直接的 `rm` 探针——若 rm 成功而 `delete_plugins()` 未删，则故障在 WordPress 层而非文件系统层。

第九轮（E2E 产物取证，规格与环境修正、无插件改动）：①`locator('script', { hasText })` 在本套 Playwright/Chromium 上恒为空——其文本引擎不读 script 体，前两轮的「探针缺失」实为断言方式错误；trace 内响应体自证探针始终在场，`window.GreenPNGProbe` 才是可观察真相，01/05 改断言窗口状态。②wp-env 的参数搬运层会吃掉含方括号/星号/嵌套引号的多词值（CF7 表单模板抵达即空、渲染出无字段表单；同批 db query 类引号多词参数却完好）——`tests/e2e/ci-seed.php` 以 `wp eval-file` + 单词子命令 + PHP 字面量载荷免疫该层，表单/商品/COD/订单完成全部内嵌。③E2E 栈带 WooCommerce 即带 Action Scheduler：队列后端为 AS 而非 wp-cron，`wp cron event run` 必然扑空，04 改 `wp action-scheduler run --group=greenpng`。④WP 7.1 的隐私指南页已迁至 `/wp-admin/options-privacy.php`（旧 `options-general.php?page=privacy-policy-guide` 以权限拒绝收场），10 改新路径并经手风琴交互断言。⑤06 的修复连带：规则表恢复渲染后页面出现第二个 tbody（表单表），裸 `tbody` 定位器触发 strict mode violation，收窄至 `.wp-list-table tbody`。

## 勘误与修正记录（2026-09-13，第十至十三轮实测后）

E2E 剩余三红的取证收敛为两处真实插件缺陷与一处定位歧义，均已修复：

- **第四处真实缺陷（队列静默丢派）**：全新 WooCommerce 站点上 AS 的 API 函数在场而调度表未建（Woo 先携带 API、其安装器后建表），`as_schedule_single_action` 以零值拒绝每次派发、`wp action-scheduler` CLI 报 group 不存在，04 的证据链显示发现已触发、pending transient 已设、AS 拒绝、wp-cron 空——任务凭空消失。`Gr_Queue::enqueue` 从不检查 AS 返回值亦无回退。修复：返回值为正数即留在 AS；否则落入 `wp_schedule_single_event`，派发永不静默丢失（is_numeric 守卫防御运行时 WP_Error——对象转 int 恒为 1）。单测经显式 CLI 子进程覆盖拒绝/接受两臂（`tests/Unit/as-presence-child.php`：装入套件 bootstrap、定义 AS 替身、跑一次 enqueue、向父测试汇报可观察状态）。弃用 `@runInSeparateProcess` 的教训要记录：本机 PHP 构建（FrankenPHP）在进程内报告的 `PHP_BINARY` 是 argv[0]——phpunit 之内即 phpunit 脚本本身，PHPUnit 的隔离子进程以它为解释器，等于让子进程重跑 phpunit，自繁成进程链失控；PATH 上的 `php` 才是全部既有检查使用的同一解释器。运行时桩维持「仅静态分析」定位（缺席即 wp-cron 世界，ADR-0007 不变），静态桩签名同步修正为 AS 3.x 真值 `int|\WP_Error`。
- **第五处真实缺陷（带外支付完成丢归因）**：09 全链通过（checkout、订单、完成、转化表均有行）但 Woo 绑定被跳过——同意状态是请求作用域，`complete_payment` 所在的 CLI 进程（网关 webhook 同理）不携带任何同意，绑定永远缺席；真实卡网关回调同受影响。修复：checkout 捕获时与 visitor 一并快照同意选择（`_gr_marketing_consent`），`bind_order` 接受「快照为同意 OR 当前请求允许」；快照为拒绝时带外完成仍不绑定（结账时的选择治理带外完成）。仅 cookie 轨可持久化的语义（ADR-0005）不变。
- 定位歧义（10）：插件名 `greenpng` 的子串匹配在区块展开后同时命中 WP 自带 "Copy suggested policy text" 按钮触发 strict mode violation，改 `exact: true`。
- 环境事实沉淀：Woo Store API checkout 信封键为 `order_id`（非 `id`）；09 终断言改按 `source_type='woocommerce'` 过滤（CF7 转化可由并行 worker 晚于本规格落库）；CF7 6.1.7 的表单模板存于 `_form` post meta（post_content 永不承载），`_additional_settings` 置 demo_mode 使提交免邮件成功；WP 7.1 的隐私指南在 `privacy-policy-guide.php`（`options-privacy.php` 在未选政策页前只呈现选择器）；08 的断言收敛为转化本身（v1.0 无 CRM 写入，docs/13 V2 明记「诚实空」，规格越界即改规格）。
