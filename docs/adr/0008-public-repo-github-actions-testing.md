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
