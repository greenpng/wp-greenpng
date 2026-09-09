# ISS-01: 产品核心定位偏离与关键能力过度阉割

| 严重级别 | 缺陷分类 | 涉及文档 | 状态 |
| :--- : | :---: | :--- | :---: |
| **P0 致命** | 产品定位 / 目标达成度 | `docs/00-project-charter.md` §4<br>`docs/01-wp-plug-analysis-and-assessment.md` §2.3<br>`docs/12-roadmap-free-v1.md`<br>`docs/adr/0005-consent-gated-tracking.md` | **已完成深度对标与纠偏** |

---

## 一、 缺陷背景与用户原始目标对照

用户对本插件的核心战略定位与交付诉求极其明确：
> **用户核心目标**：
> “如果本插件定准是**访客分析与监控管理，用户质量分析与找出机人 rpa 用户，和营销推广分析，落地页 ab 测试，用户在站点和插件内行为统计**。定准是来**解决无效访客，拦截恶意蜘蛛和访客，找出自动程序用户和水军用户，和对广告营销和高质量用户标记，落地页测试与分析**等等。是一个集成**「访客、用户、流量、营销推广」一体**的插件……还要与第三方 API 打通，与 WP 站点内安装用户插件打通，方便 WP 站长运营站点和插件，做好用户管理和推广营销。”

而在同事撰写的 `greenpng` 系列文档中，为了追求极致的“合规无依赖”与“反臃肿”，采取了激进的一刀切阉割策略，导致**用户的核心业务诉求在规划层几乎被瓦解**。

---

## 二、 核心问题深度解剖与同类插件开源实现对标

### 1. 致命痛点：一刀切废弃设备硬件环境探针，导致根本无法识别现代 RPA 机器人与水军

#### 现状分析：
- 同事在 `docs/00-project-charter.md` §4 中将设备指纹列为“❌ 非目标（明确不做）”：“*设备指纹追踪（Canvas/WebGL/Audio 指纹用于营销画像）—— GDPR/ePrivacy 风险与免费插件责任不匹配*”。
- 在 `docs/12-roadmap-free-v1.md` 中，将任何设备信号检测推迟到 v1.3，且只作为可选项（opt-in）。
- 在 v1.0 与 v1.1 中，仅保留了极其简陋的 UA 正则匹配（`gr_is_scanner_ua`）与 IP 黑名单匹配（`gr_match_cidr`）。

#### 为何这是致命错误？
1. **现代 RPA、无头浏览器与水军的运作机制**：
   - 今天的自动化爬虫（如 Puppeteer、Playwright、Selenium）、群控 RPA 刷量脚本与水军，**绝对不会在 User-Agent 里主动写自己是 `sqlmap` 或 `bot`**！它们全部伪装成最新版 Chrome 或 Safari 的合法 UA。
   - 水军工作室与 RPA 批量注册程序全部挂载**动态住宅代理 IP（Residential Proxies）**，IP 轮换频繁且无污染历史，静态的 IP 黑名单或 CIDR 封禁完全无效。
2. **同类顶流开源插件是如何成功实现的？**
   - **`JayBizzle/CrawlerDetect`**（源码路径：`/Users/macos/wp-plug/01-anti-bot-rpa-crawler-detection/JayBizzle-Crawler-Detect/src/CrawlerDetect.php`）：
     - 维护了 1,000+ 爬虫正则表达式，通过 `src/Fixtures/Exclusions.php` 白名单快速短路，纯 PHP 毫秒级识别主流通用抓取工具。
   - **`ClientJS` / `FingerprintJS`**（源码路径：`/Users/macos/wp-plug/02-device-fingerprint-emulator-client/clientjs/src/client.js` 与 `.../fingerprintjs/`）：
     - 运行于客户端，提取 `navigator.webdriver` 注入标志、WebGL 软渲染器特征（`SwiftShader` / `Mesa`）、Canvas 2D 文本微像素渲染差、AudioContext 频响浮点计算延迟。
     - **关键合规界限**：这些特征在 GDPR 前言第 49 条（Recital 49）下，**作为网络安全与防欺诈属于合法的正当利益（Legitimate Interest）**！只要不将探针数据用于跨站第三方广告追踪，纯粹用于计算当前访客的 `bot_score`，完全合法合规，不需要弹出侵入式的 Cookie 弹窗！
3. **严重后果**：
   - 砍掉了客户端环境探针后，本插件面对现代自动化脚本与水军刷量时，**如同不戴眼镜的盲人**。根本不可能实现“找出自动程序用户和水军用户、解决无效访客”的核心目标！

---

### 2. 逻辑死结：强行切断安全风控与 CRM 用户评分，导致机器人被评为优质客户

#### 现状分析：
- 同事在 `docs/adr/0005-consent-gated-tracking.md` §3 与 `docs/07-third-party-apis.md` §4 中立下硬性规则：
  “*目的分离：安全信号不得写入联系人画像。禁止把安全指纹写入联系人画像（原型把同一 fingerprint_hash 同时用于风控与 CRM，违反目的限定原则）。*”

#### 为何这是业务逻辑灾难？
1. **用户的核心诉求是用户质量标记**：
   - 用户明确要求：“*用户质量分析与找出机人 rpa 用户和水军用户，对广告营销和高质量用户标记*”。
   - 这意味着：如果一个用户是机器人或高风险刷量者，他的**质量评分（Quality Score）必须被扣成零分或打上 `risk:bot`、`spam` 标签**；反之，真实消费的活跃访客才被打上 `lead:high_value`。
2. **同类开源插件的优秀做法**：
   - **`Antispam Bee`**（源码路径：`/Users/macos/wp-plug/01-anti-bot-rpa-crawler-detection/antispam-bee/antispam_bee.php`）：
     - 在表单提交钩子（`precheck_incoming_request`）中，一旦检测到陷阱字段（`ab_spam__hidden_field`）被填写或提交时间小于 2 秒，直接标记请求为垃圾请求；
   - **`FluentCRM` / `AutomatorWP`**（源码路径：`/Users/macos/wp-plug/09-user-scoring-tagging-rfm-crm/fluent-crm/`）：
     - 在创建联系人时，优先校验风控上下文。一旦标记为垃圾或机器人，直接阻止写入活跃 Leads 列表，避免销售团队跟进假线索。
3. **割裂后的荒谬后果**：
   - 按照同事的设计：一个恶意脚本通过自动化程序疯狂提交表单，安全子系统虽然在后台记录了一条安全日志；但因为“安全信号不得传递给 CRM”，CRM 线索评分系统（`gr_lead_score`）依然忠实地记录：“新联系人生成！表单提交成功！触发高分事件！”，并慷慨地为其增加 30 分，列为“优质潜在客户”！
   - 这种为了所谓“目的限定原则”将安全与用户画像彻底绝缘的做法，彻底摧毁了用户质量分析的严肃性。

---

### 3. 目标缩水：落地页 A/B 测试与漏斗流失分析被大幅削减推迟

#### 现状分析：
- 同事在 `docs/01` §4.4 中决议：“*废弃 18 漏斗画布（原型中画布不存在，只有两个 alert 按钮）……废弃 22 A/B 测试页（并入转化目标）*”。
- 在 `docs/12-roadmap-free-v1.md` 中，将 A/B 测试推迟到 v1.2；v1.0 中完全没有漏斗分析界面，仅保留了底层的几个函数。

#### 为何不合理？同类插件如何以极简代码实现：
- **`CartFlows` / `FunnelKit`**（源码路径：`/Users/macos/wp-plug/06-conversion-funnel-dropoff-analytics/cartflows/`）：
  - 漏斗流失分析根本不需要复杂的 React 画布拖拽，纯 PHP 即可根据步骤会话计算转化率：
    `$dropoff_rate = ($step_views - $next_step_views) / max(1, $step_views) * 100`；
  - 配合原生 CSS 阶梯条即可实现极度清晰的可视化效果！
- **落地页 A/B 测试双比例 Z 检验**（详见 `PEER-02`）：
  - 纯 PHP 实现标准正态分布 Z 检验仅需 20 行代码，毫秒级得出 `p-value` 与显著性判定（`confidence >= 95%`），完全可以在 v1.1 顺畅交付！

---

## 三、 纠偏与落地整改方案（结合可用函数映射）

| 序号 | 纠偏项 | 架构与合规落地方案 | 对应可用函数与类 |
| :---: | :--- | :--- | :--- |
| **1** | **重构客户端安全沙箱探针** | 在合法利益（GDPR Recital 49 网络安全免责）前提下，引入**无侵入的客户端自动化环境探针**（`gr-probe.js`）：仅采集 `navigator.webdriver`、WebGL 渲染器标识、Canvas 自动化伪影等安全信号，用于判定 `is_bot`，精准捕获现代 RPA 与水军。 | `gr_verify_crawler()`<br>`gr_inspect_request_payload()`<br>`assets/js/gr-probe.js` |
| **2** | **打通风控风险与 CRM 用户质量分** | 允许安全子系统的风险判定结果以只读上下文传递给用户质量评分：若访客判定为机器人或命中恶意规则，其线索评分强制清零，并自动打上 `sys:suspected_bot` 标签，阻止垃圾数据污染 CRM。 | `gr_user_quality_score()`<br>`gr_lead_score()`<br>`gr_tag_attach()` |
| **3** | **在 v1.1 提前交付落地页 A/B 测试** | 不搞复杂的拖拽画布，而是采用 WordPress 原生风格的**轻量级 A/B 测试卡片**：支持 URL 参数切流（`?gr_variant=b`）与短码分流，自动计算 Z 检验显著性，让站长在首发后即可测试广告落地页。 | `gr_ab_assign_variant()`<br>`gr_ab_record()`<br>`gr_ab_significance()` |
| **4** | **零验证码隐形蜜罐全量表单覆盖** | 借鉴 `Antispam Bee` 机制，在登录、注册、CF7、Fluent Forms 表单中自动注入动态时间戳与混淆陷阱字段，兼顾无障碍（Accessibility），彻底拦截垃圾水军提交。 | `gr_render_honeypot()`<br>`gr_check_honeypot()` |
