# ADR-0004: 管理后台用 WP 原生组件，不引入 Tailwind/React/CDN

- 状态：已接受
- 日期：2026-09-09

## 背景

参考项目的 40 页原型是自绘设计系统：每页约 630 行内联样式手工重写 WP 核心 CSS（含 `!important`）、Tailwind Play CDN 运行时（407KB 浏览器内 JIT）、全部 40 页无条件加载 Chart.js、587 处内联 `onclick`、87 个内联 `<script>`、100% 硬编码假数据、零 `<form>` 零 nonce 零 i18n。其 WP 风格是伪装（`wrap`/`form-table`/`postbox` 在 40 页中出现 0 次）。

## 决策

1. 管理后台一律使用 WordPress 原生组件：`wrap`、`form-table`、`postbox`、`notice`、`WP_List_Table`、`nav-tab-wrapper`、Settings API、dashicons。
2. 不引入 Tailwind/Bootstrap/React/Vue，不从任何 CDN 加载资源，前端第三方库仅限本地打包且附未压缩源码的 MIT/BSD 资产。
3. 页面默认服务端渲染；仅"实时流量"页允许用修复后的 `gr-datagrid.js` 做 REST 增强（修 `innerHTML` XSS 与假数据源），且保留无 JS 降级。
4. 原型的可复用部分：列定义/筛选器/批量操作配置、信息架构、作为测试夹具的假数据、`agy-datagrid.js` 修复后移植——其余表现层全部重写。

## 后果

- 正面：体积缩小两个数量级；随 WP 版本升级样式不碎；天然满足可访问性与 i18n；WP.org 审核零资产风险。
- 代价：视觉不如自绘系统"现代"；不以视觉效果为竞争点，这是有意取舍。
