# 12. 免费版路线图 (Free-Only Roadmap)

> **前提**：五大业务域全部在范围内（用户已批准，见 `00` §6）。本路线图解决的是**交付顺序**——让每一版都可独立上架 WordPress.org，而不是全量一次憋大招。
> **里程碑的完成定义**：通过 `08` 合规清单 + `09` 性能预算 + `11` 测试门禁。

---

## v1.0 —— 立足版（流量看清 + 归因闭环）

**目标用户价值**：装上即看清真实流量构成与广告来源，数据全在本地。

| 交付 | 内容 |
| :--- | :--- |
| 核心底座 | 三层架构、autoloader、`gr_settings`、14 表 + 汇总表、版本迁移、uninstall |
| 流量安全（保守档） | UA/扫描器识别、IP/CIDR 封禁与允许列表、FCrDNS 爬虫验证（仅记录模式）、浪涌折叠日志、登录防爆破、蜜罐（opt-in） |
| 营销归因 | UTM+点击 ID 捕获（同意门控）、会话与触点、first/last-touch、WooCommerce + CF7/Fluent Forms/WPForms 桥接、转化幂等绑定 |
| 管理页 | Dashboard、Traffic & Security（3 标签）、Access Rules、Login Protection、Campaigns（4 标签）、URL Builder、Tools×3、Audit Log |
| 合规 | WP 隐私 API、IP 匿名化、readme.txt 全披露、零外呼 |

**v1.0 不做**：CAPI 出网、漏斗画布、RFM 界面、行为探针（代码可存在但功能开关默认关且 UI 不露出）。

## v1.1 —— 运营版（行为 + CRM + 出网）

| 交付 | 内容 |
| :--- | :--- |
| 行为洞察 | gr-probe.js（停留/滚动/怒点），同意门控，落 `gr_events` |
| CRM | 联系人列表/档案/标签、线索评分（权重集中配置）、RFM 分层 |
| 出网 | 统一 Http_Client（熔断）、Meta CAPI、GA4 MP、通用 Webhook、AbuseIPDB（opt-in）、GeoIP（站长自供数据文件） |
| 漏斗 | 漏斗定义（非画布：步骤表单）+ 步骤流失 + 转化目标页 |

## v1.2 —— 增长版（实验 + 挽回 + 生态）

| 交付 | 内容 |
| :--- | :--- |
| A/B 测试 | 一致性哈希分流 + Z 检验显著性 |
| 弃购挽回 | 失焦捕获（默认关 + 同意勾选）+ 15 分钟调度 + 令牌核销 |
| 生态 | hCaptcha、Matomo 回传、动态事件嗅探工具页、适配器公开 API（`gr_registered_adapters` filter + 文档） |
| Turnstile 渐进验证 | 登录/注册/表单连续失败后触发 |

## v1.3 —— 打磨版

- Bot & Device Signals 页的 opt-in 设备信号（有限信号检测，独立隐私评审）。
- TikTok Events API（有真实用户需求再提前）。
- 多触点归因界面（5 模型对比已在 v1.0 底层就绪）。
- 生态适配器社区征集；仍只宣称实测数量。

## 明确不在路线图内

Pro 版、License、云端、跨站情报、会话回放、Google Ads 离线转化 API、设备指纹营销画像、"156 插件兼容"宣称。

## 排期风险登记

| 风险 | 缓解 |
| :--- | :--- |
| WooCommerce Blocks/经典双结账路径 × HPOS 开关的组合测试成本 | 适配器层 `Throwable` 隔离 + 矩阵 CI；Blocks 路径用 Store API 钩子 |
| Meta/TikTok 等 API 版本漂移 | 版本号常量化 + 设置页连通性自检 |
| 表单插件 Hook 漂移（参考项目档案内部就自相矛盾） | 每适配器主 Hook + 回退 Hook；静默失败改为状态页可见告警 |
| 共享主机性能 | `09` 预算为 CI 门禁，不达标不发版 |
