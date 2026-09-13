# 12. 免费版路线图 (Free-Only Roadmap)

> **前提**：五大业务域全部在范围内（用户已批准，见 `00` §6）。本路线图解决的是**交付顺序**——让每一版都可独立上架 WordPress.org。
> **修订记录**：2026-09-09 依据 iss/ 审计（结论见 `docs/14`）与站长决策（ADR-0007）：v1.0 改为**四支柱首发闭环**（探针 / A-B / CAPI / GeoIP 全进首发）；v1.1 聚焦运营增强；v1.2 生态开放。任务分解见 `docs/13`。
> **里程碑的完成定义**：通过 `08` 合规清单 + `09` 性能预算 + `11` 测试门禁。

---

## v1.0 —— 立足版（四支柱首发闭环）

**目标用户价值**：装上即看清真实流量构成（含 RPA/自动化识别）、广告归因闭环、可做落地页 A/B 测试、开箱即见访客国家分布，后台任务可靠执行。数据全在本地。

| 支柱 | 交付 |
| :--- | :--- |
| 1 兼容底座 | 三层架构、autoloader、`gr_settings`、15 物理对象（含补索引）、版本迁移、uninstall；PHP 7.4~8.5+ / WP 6.0~7.1+ 双向兼容规范（`04`）；**自适应任务队列**（AS 嗅探→WP-Cron 回退+互斥锁）；**独立 Settings 页**（General/Security/Attribution） |
| 2 流量安全与 RPA 识别 | UA 引擎升级（CrawlerDetect MIT 数据种子本地化，自维护）；**客户端安全探针** gr-probe.js 安全模块（webdriver / WebGL 软渲染类别 / 无头窗口特征 → bot_score，默认开、合法利益、设置可关、readme 披露）；蜜罐（陷阱字段 + 提交时间差 + 动态字段名）；Blackhole 陷阱（opt-in）；浪涌折叠（MySQL 原子 upsert）；IP/CIDR 封禁与允许列表；登录梯度锁定；FCrDNS（异步验证，仅记录）；安全日志完整 IP + 展示脱敏 + 匿名化开关；安全总熔断 |
| 3 营销归因与 A/B | **30 天同意门控 cookie（`gr_attr` → visitor_id）为主身份 + 无 cookie 每日盐哈希回退**；UTM + 点击 ID 捕获；触点；5 种归因模型；WooCommerce 三挂载（经典结账 / Store API Blocks / payment_complete，HPOS 通用写法）+ CF7 / Fluent Forms / WPForms 桥接；转化幂等绑定；**A/B 测试**（一致性哈希分流 + URL 参数/短码 + 双比例 Z 检验） |
| 4 出网与开箱（全部 opt-in） | `Http_Client`（超时+熔断+退避）；**Meta CAPI + GA4 MP**（event_id 双端去重、仅回传通过流量质量过滤的事件）；**DB-IP Lite 国家库预置**（CC BY 4.0 归属披露，显式"立即更新"按钮，绝无静默外呼） |
| 合规 | 隐私双轨（ADR-0007）：安全轨合法利益 / 营销轨同意门控；WP 隐私 API；readme 全披露 |

**v1.0 管理页（14 页）**：Dashboard、Traffic & Security（3 标签）、Access Rules、Login Protection、Bot & Device Signals（爬虫验证标签）、Campaigns（4 标签）、URL Builder、Funnels & Goals（A/B 标签先行）、Settings（独立页）、Analytics & CAPI、IP Intelligence（GeoIP 部分）、Audit Log、Data Retention、Status & Diagnostics。

**v1.0 不做**：漏斗定义与流失图（v1.1）、RFM/联系人界面（v1.1）、弃购挽回（v1.1）、行为洞察模块（v1.1）、通用 Webhook（v1.1）、Turnstile（v1.2）。

## v1.1 —— 运营版（行为 + CRM + 漏斗 + 挽回）

| 交付 | 内容 |
| :--- | :--- |
| 行为洞察 | gr-probe.js 行为模块（停留/滚动/怒点/死点），同意门控，落 `gr_events` |
| CRM | 联系人列表/档案/标签、线索评分（**消费 v1.0 安全结论通道**：疑似机器人→评分清零 + `sys:suspected_bot` 标签；原始信号永不入画像）、RFM 分层；**表单线索留存**——语义提取的 email/name/phone 在同意门控下落 `gr_contacts`（ISS-09 C5 裁定，`15` §4；"表单提交=默示同意"已驳回，ADR-0005） |
| 漏斗 | 漏斗定义（表单式，非画布）+ 步骤流失 + **纯 CSS/SVG 阶梯流失图** + 转化目标页 |
| 弃购挽回 | 失焦邮箱捕获（默认关 + 同意勾选）+ 15 分钟调度（自适应队列）+ 令牌核销 + 防重复发信互斥 |
| 归因冲销 | 退款/取消冲销（Woo `refunded`/`cancelled` 监听；`gr_conversions` UNIQUE source 键下须 UPDATE/软标而非第二行；日聚合 7 天重算窗与跨窗退款口径待定——三项开放问题的 ADR 启动时立，ISS-09 C4 后半 / `15` §4） |
| 出网 | 通用 Webhook（HMAC-SHA256 签名 + 验签文档） |

## v1.2 —— 生态版（开放互通）

| 交付 | 内容 |
| :--- | :--- |
| 适配器开放 | `gr_registered_adapters` filter + 适配器公开 API + 文档（第三方/站长自扩展） |
| Webhook 模板 | 飞书 / 企业微信 / 钉钉 / Slack 消息格式器（纯本地格式化） |
| 动态事件嗅探 | 站长后台零代码监听任意 WP 钩子（仅后台工具页按需启用，禁前台常驻） |
| 验证增强 | Cloudflare Turnstile 渐进验证（登录/注册/表单连续失败 ≥2 次触发）、hCaptcha、Matomo 回传、AbuseIPDB（opt-in） |

## v1.3 —— 打磨版

- Bot & Device Signals 页"设备信号"标签（opt-in，有限信号检测，独立隐私评审后交付）。
- TikTok Events API（有真实用户需求再提前）。
- 多触点归因模型对比界面（5 模型计算 v1.0 已就绪）。
- 性能打磨；生态适配器社区征集；仍只宣称实测数量。

## 明确不在路线图内

Pro 版、License、云端、跨站情报、会话回放、Google Ads 离线转化 API、**指纹用于营销画像**、"156 插件兼容"式宣称。

## 排期风险登记

| 风险 | 缓解 |
| :--- | :--- |
| v1.0 范围显著扩大（ADR-0007 决策） | `docs/13` 四支柱任务分级，底座（Phase 1）先行；每支柱独立可验收 |
| 探针默认开的合规表述 | `08` 清单专项自查；设置页一键关；readme/隐私政策模板措辞评审 |
| WooCommerce 双结账 × HPOS 组合 + 常用插件共存矩阵 | 适配器 `Throwable` 隔离 + `11` §5 矩阵；E9 插件补装 |
| 表单插件 Hook 漂移 | 主 Hook + 回退 Hook；静默失败改为状态页告警（通用化原则，ADR-0007） |
| Meta/GA4 API 版本漂移 | 版本号常量化 + 设置页连通性自检 |
| 共享主机性能 | `09` 分层预算为 CI 门禁，不达标不发版 |
| DB-IP Lite 数据时效 | 数据文件版本随 release 更新 + 站长显式更新按钮；归属与数据日期在 readme 声明 |
