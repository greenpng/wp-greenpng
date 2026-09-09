# 06. 管理后台与页面规范 (Admin Pages & UI)

> **本文档定义管理后台的信息架构与 UI 规范。** 与参考项目 40 页原型的差异及理由见 `01-wp-plug-analysis-and-assessment.md` §4。

---

## 1. 信息架构：单一顶级菜单 + 19 个子页面

参考项目用 40 个页面（其中 15 页去重后仅 7 行内容）是页面膨胀。greenpng 收敛为 **19 页**，五大业务域全覆盖，通过页内标签（tab）承载原"一页一功能"：

```
greenpng （顶级菜单，dashicons-chart-area，位置 30）
├── Dashboard                 仪表盘（KPI + 趋势图，读 gr_daily_stats）
├── Traffic & Security        实时流量与安全事件   [标签: 实时流 | 威胁事件 | 欺诈审计]
│   ├── Access Rules          访问规则             [标签: 封禁 | 允许列表]
│   ├── Login Protection      登录保护             [标签: 爆破审计 | 会话管理]
│   └── Bot & Device Signals  爬虫与设备信号       [标签: 爬虫验证 | 设备信号(opt-in)]
├── Marketing
│   ├── Campaigns             营销战役与归因       [标签: 战役 | UTM | 点击ID | 归因模型]
│   ├── URL Builder           链接构建器
│   └── Funnels & Goals       漏斗与目标           [标签: 漏斗 | 步骤流失 | 目标 | A/B]
├── Audience
│   ├── Contacts              联系人               [标签: 列表 | 标签 | RFM 分层]
│   ├── Contact Profile       联系人档案（行内进入，含时间线）
│   ├── Scoring Rules         评分与自动化规则
│   └── Behavior Insights     行为洞察             [标签: 停留/滚动 | 怒点/死点]
├── Integrations
│   ├── Analytics & CAPI      分析回传（GA4/Matomo/Meta/TikTok，全部默认关）
│   ├── IP Intelligence       IP 情报（AbuseIPDB/GeoIP，默认关）
│   ├── Webhooks              出网 Webhook
│   └── Plugin Ecosystem      生态桥接（WooCommerce/表单插件 + 自定义嗅探）
└── Tools
    ├── Audit Log             操作审计
    ├── Data Retention        数据保留与瘦身（仅本地，无任何"云端模式"）
    └── Status & Diagnostics  状态与诊断导出
```

### 页面数对照
- 参考项目 40 页 → greenpng 19 页：17 页保留、14 页合并、5 页废弃（明细见 `01` §4.4）。
- **明确的减法**：无可视化漏斗画布（原型中根本未实现）、无云端授权页、无"本地/云端双模式"切换。

## 2. UI 技术规范（强制）

### 2.1 用 WP 原生组件，禁止自绘设计系统
| 用途 | 正确 | 禁止 |
| :--- | :--- | :--- |
| 页面容器 | `<div class="wrap">` + `<hr class="wp-header-end">` | 自绘 `#wpadminbar`/`#adminmenu` |
| 通知 | `settings_errors()` + `.notice .notice-success` | JS 伪造的 toast 成功提示 |
| 表单 | Settings API + `<table class="form-table">` | 无 `<form>` 的散置 input（原型 68 个 input 没有一个 form） |
| 列表 | `WP_List_Table` 子类（服务端分页/排序/批量） | JS 假分页（原型网格只读内联数组） |
| 按钮 | `.button .button-primary` | `!important` 重写的假按钮类 |
| 标签页 | `nav-tab-wrapper` + `$_GET['tab']` 服务端分发 | 自绘手风琴侧栏 |
| 图标 | `dashicons`（`wp_enqueue_style('dashicons')`） | 打包第三方图标库 |

### 2.2 数据流：服务端渲染优先，REST 增强
- **默认模式**：页面由 PHP 服务端渲染，`WP_List_Table` 处理列表，表单走 Settings API。这天然满足 nonce/能力校验/i18n。
- **增强模式（仅实时流量页）**：`gr-datagrid.js`（由原型 `agy-datagrid.js` 修复移植）对 REST 端点轮询。修复义务：
  1. 数据源从内联数组改为 `fetch` + `X-WP-Nonce`；
  2. 全部 `innerHTML` 拼接改为 `textContent`/先转义（原型的 UA/payload 拼接是后台存储型 XSS）；
  3. 无 JS 时页面有可用的服务端渲染降级。
- **每个状态变更操作**：`current_user_can('manage_options')` + nonce。只读接口至少 `manage_options` 能力校验（数据敏感，不开放给编辑）。

### 2.3 图表
- 一个轻量 MIT 图表库（uPlot 级，≤20KB）本地打包，**附带未压缩源码**。
- 只在有图表的页面 enqueue（原型在 40 页无条件加载 208KB Chart.js，含无图表页）。
- 数据来自 REST 聚合端点（读 `gr_daily_stats`），绝不把数据集硬编码进页面。

### 2.4 假数据纪律
- 原型中 213 条内联假记录、170 个 `alert()`、伪造的 "API 契约面板"（本地拼装假响应冒充接口）——**一律禁止进入 greenpng**。
- 假数据的合法用途只有一个：`tests/fixtures/` 测试夹具与可选的"演示数据生成器"（工具页、明确标注、一键清除）。

## 3. 页面内容红线

- 不出现任何竞品名（"类似 Wordfence"类文案全部删除）。
- 不出现无实测支撑的数字（"99.4% 置信度""3.6ms"）。
- 不出现付费/升级/License/云端任何字样与灰化占位 UI。
- 不出现竞品 CVE 编号作为装饰。
- 第三方品牌（Google、Meta、WooCommerce）仅出现在集成设置标签中，且遵守其商标使用规则。

## 4. 可访问性

- 蜜罐陷阱字段：`aria-hidden="true"` + `tabindex="-1"`，绝不依赖 `display:none` 链接（屏幕阅读器会读出，导致真实用户被误封）。
- 所有交互元素可键盘到达；色彩对比度遵循 WP 管理后台已有标准。
- 图表提供等价的表格视图（`screen-reader-text`）。

## 5. 响应式

- 依托 WP 核心 admin 样式的响应式能力；自有组件在 782px 断点下不横向溢出。
- 不使用任何需要 630 行/页内联样式才能成立的设计（原型的每页内联样式就是反例）。
