# ADR-0003: PHP 7.4+ 基线与零 Composer 运行时依赖

- 状态：已接受
- 日期：2026-09-09

## 背景

用户决策：技术基线为 PHP 7.4+ / WP 6.0+ / 不用 Composer / 手写 autoloader（覆盖面最广）。
参考项目 agy-suite 原型实际使用 PHP 8.0+ 语法（`mixed`、`str_contains`、`str_starts_with`、构造器属性提升的 DTO 设计）；其文档甚至宣称 PHP 8.5 / WordPress 7.1 环境（后者不存在）。
架构审计的独立建议原本是 PHP 8.1+；用户基于共享主机覆盖率选择了更保守的 7.4+，本 ADR 按用户决策执行并记录其工程后果。

## 决策

1. 插件头：`Requires at least: 6.0`、`Requires PHP: 7.4`。
2. **零 Composer 运行时依赖**：`vendor/` 仅供开发工具（PHPUnit/PHPCS/PHPStan），构建 ZIP 时排除。
3. 类加载：手写 autoloader（`class-gr-autoloader.php`），`GreenPNG\` 命名空间 → `includes/<模块>/class-gr-<slug>.php` 映射。
4. PHP 8.0+ 语法全部禁用（`AGENTS.md` §4 给出替代写法表），由 PHPCompatibilityWP（testVersion 7.4-）在 CI 强制。
5. SQL 同样取低水位：不用窗口函数（`NTILE` 等）、不用生成列（`dbDelta` 不支持）、不用 `FOR UPDATE`。RFM 等分位数计算在 PHP 内基于预聚合数据完成。
6. 许可证因此保持 GPLv2+（不打包任何 Apache-2.0/LGPL-3 运行时库）。

## 后果

- 正面：覆盖最广的共享主机安装面；供应链面最小；WP.org 审核最简单。
- 代价：失去 `readonly`/枚举/`match`/命名参数等现代语法；参考项目原型的 DTO 与类型声明需降级改写；无法打包 Matomo DeviceDetector 等现成库（UA 解析用自维护轻量规则集）。
- 风险与缓解：PHP 7.4 已停止官方安全支持——这是站长的环境风险而非插件缺陷；readme 注明推荐 PHP 8.1+，CI 同时在 7.4 与 8.x 跑测试。
- 复审触发点：当 WordPress 官方统计中 PHP < 8.1 占比降至可忽略时，可用新 ADR 提升基线。
