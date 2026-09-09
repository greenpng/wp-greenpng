# ADR-0006: 本地开发与验证环境（MariaDB 主验证站 + SQLite 兼容站）

- 状态：已接受
- 日期：2026-09-09

## 背景

`docs/11` 规定集成测试经 wp-env（Docker）执行。本机实测（2026-09-09）：无 docker / mysql / mariadb 二进制；唯一 PHP 为 8.5.10（FrankenPHP 构建，`-d` 选项不被该构建解析，需以 `PHPRC` 环境变量注入 php.ini）；既有测试站（`wp-plug/wp-site`，:8090）为 WP 7.1 + SQLite（sqlite-database-integration drop-in），WP-CLI 在 WooCommerce 全量源码下以默认 128M 内存耗尽。

站长决策（2026-09-09）：安装 MariaDB 搭 MySQL 站；agy-suite 原型在 greenpng 首次装入任一测试站时停用；先产出 v1.0 实施清单（`docs/13`）再开始写代码。

**勘误记录**：ADR-0003 曾注记「WordPress 7.1 不存在」。2026-09-08 获取的官方 latest.tar.gz 实测安装后 `wp core version` 返回 7.1，该版本现已发布，此注记不再成立。ADR-0003 的决策（WP 6.0+ 基线）不受影响；该注记原本指向的事实（参考项目文档宣称的测试结论不可信、无实测支撑）仍然成立。按 ADR-0001「只增不改」原则，勘误记录于此而不改动原文。

## 决策

1. 本地经 Homebrew 安装 MariaDB 作为 MySQL 主验证库；主验证站建于 `/Users/macos/greenpng-dev/wordpress`（与仓库分离，仓库不提交任何站点文件与凭据），由 `frankenphp php-server --listen :8091` 提供服务。
2. 主验证站仅激活 v1.0 相关生态插件（WooCommerce、Fluent Forms、Limit Login Attempts Reloaded）；其余生态插件 symlink 就位但不激活，随路线图阶段启用；agy-suite 不装入主验证站。
3. SQLite 站（:8090）保留为次要兼容环境：sqlite-database-integration 是官方插件方向，greenpng 须在其上可运行，但不作为 v1.0 门禁，测试结果如实记录。
4. WP-CLI 统一经 `PHPRC=<php.ini>`（memory_limit=1G）方式调用；命令与凭据位置固化于 `greenpng-dev/NOTES.md` 与 `greenpng-dev/CREDENTIALS.txt`（均在仓库外）。
5. CI 矩阵维持 `docs/11` 原案（wp-env/Docker；PHP 7.4/8.1/8.3 × WP 6.0/latest × MySQL 5.7/8.0），待仓库具备远端 CI 后落地；本地环境不是 CI 的替代品。
6. SQL 取 MySQL 低水位方言（与 ADR-0003 一致）；成本可控处采用可移植写法（如浪涌折叠 upsert 用「先 UPDATE 后 INSERT」而非 `ON DUPLICATE KEY UPDATE`），SQLite 兼容冒烟仅记录不设门禁。

## 后果

- 正面：dbDelta 与 DDL 在 MySQL 真实环境验证，与 `docs/05` 的设计前提一致；不引入 Docker 依赖；主验证站 + 兼容站双环境贴近真实站长的安装面。
- 代价：本地无 PHP 7.4 运行时，7.4 兼容性仅由 PHPCompatibilityWP 静态保证，运行时验证归 CI；MariaDB 与 MySQL 5.7/8.0 的差异（排序规则、新语法可用性等）本地不可见，由 CI 矩阵兜底；WP 6.0 下界本地未覆盖（`docs/13` OQ-3）。
- 复审触发点：仓库接入远端 CI 时；或本机具备 Docker / 多版本 PHP 能力时。
