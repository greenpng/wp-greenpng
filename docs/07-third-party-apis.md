# 07. 第三方 API 集成规范 (Third-Party APIs)

> **总原则**：greenpng 自身零外呼；第三方 API 全部**站长自填凭据 + 默认关闭（opt-in）**，且每个都必须在 `readme.txt` 的 `== External services ==` 段披露（服务名、发送什么数据、何时发送、服务条款与隐私政策链接）。
> **来源**：对 wp-plug 档案全部 API 计划的逐项审计结论（细节见 `01` §6）。
> **修订记录**：2026-09-09 依据 ADR-0007——Meta CAPI / GA4 MP / DB-IP GeoIP 预置进入 v1.0（四支柱首发）；版本列按新路线图（`12`）重排；隐私同意门控改双轨表述。

---

## 1. 收录决策总表

| 服务 | 决策 | 版本 | 默认状态 | 说明 |
| :--- | :--- | :--- | :--- | :--- |
| GA4 Measurement Protocol | ✅ | **v1.0** | 关 + 同意门控 | 服务端事件回传；提供 `/debug/mp/collect` 连通性自检 |
| Meta Conversions API | ✅ | **v1.0** | 关 + 同意门控 | Graph 版本号为**可过滤常量** `GR_META_API_VERSION`（档案硬编码的 v19.0 已停支持，教训） |
| **DB-IP Lite（国家库）** | ✅ | **v1.0** | **随包预置（开箱即用）** | CC BY 4.0 可再分发；查询本地**零外呼**；readme/NOTICE 归属声明 + 数据日期；更新仅经**站长显式按钮**（点击才出网，opt-in 披露）；~4MB 文本数据 |
| FCrDNS 爬虫验证 | ✅ | v1.0 | **开，但仅记录模式** | 系统 DNS，无凭据；IPv6 正查修复；失败一律放行（见 `10` §4）；验证经队列异步 |
| 通用 Webhook（HMAC-SHA256） | ✅ | v1.1 | 关 | 四头签名（`X-Gr-Signature: sha256=<HMAC(原始 body)>` 等，ADR-0016）；v1.2 附 Slack/飞书/企微/钉钉消息格式器（纯本地格式化，无凭据） |
| Cloudflare Turnstile | ✅ | v1.2 | 关 | 渐进式验证（登录/注册/表单连续失败 ≥2 次触发）；密钥配置后才加载 widget |
| hCaptcha | ✅ | v1.2 | 关 | 第二人机验证提供方（替代 reCAPTCHA） |
| Matomo（自建实例） | ✅ | v1.2 | 关 | 站长自有服务器，GPL 兼容 |
| AbuseIPDB | ⚠️ | v1.2 | 关 | 免费 1,000 次/日；24h 缓存 + 熔断器；本地允许列表优先短路 |
| 搜索引擎蜘蛛 IP 段订阅 | ⚠️ | v1.2 | 关 | 手动刷新按钮 + 可选周更（开启才注册 cron）；目标：Google/Bing/Apple 官方 JSON |
| MaxMind GeoLite2 | ⚠️ | v1.2 | 关 | 站长自填 License Key 自行下载（偏好 MaxMind 精度时的可选路径）；**插件绝不捆绑 mmdb**（EULA 禁止再分发）；默认路径是 DB-IP Lite 预置 |
| TikTok Events API | ⚠️ | v1.3 | 关 | 需 TikTok 企业账号；版本常量化 |
| Google Ads 离线转化 | ❌ 放弃 | — | — | 需 Google 审批的开发者令牌 + OAuth2 应用审核；档案锁定的 v16 已 sunset。这是硬限制，不是排期问题。如未来重新评估需 ADR |
| Google reCAPTCHA | ❌ 放弃 | — | — | 已并入 Google Cloud 计费与密钥体系；欧盟认定加载即传输个人数据需事先同意；Turnstile + hCaptcha 覆盖需求 |
| IPQualityScore | ❌ 放弃 | — | — | API Key 置于 URL 路径会泄漏进日志/诊断导出 |
| proxycheck.io | ❌ 放弃 | — | — | 免费额度过小，与 AbuseIPDB 职责重复 |
| 会话回放（rrweb 类） | ❌ 永不 | — | — | 最高隐私风险类别 |
| 一次性邮箱在线 API | ❌ | — | — | 用本地域名清单即可 |
| 一切自建云端（antigravity.ai 等） | ❌ 删除 | — | — | 免费版定位（ADR-0002） |

## 2. 统一出网通道：`GreenPNG\Core\Http_Client`

**所有** `wp_safe_remote_*` 调用必须经此类，禁止业务代码直接出网：

```php
GreenPNG\Core\Http_Client::post('meta_capi', $endpoint, $payload);
// 每个服务有独立配置：timeout≤5s（API 类；两个站长显式点击的批量数据下载服务
// ——dbip_update/dch_ranges——为 120s 线预算，官方段表数以 MB 计）、
// 熔断（连续 3 次失败断开 300s）、
// 429 读 Retry-After、失败退避重试（30s/2m/15m，3 次后放弃并记审计）
```

- 未配置凭据 → 立即返回 `WP_Error('gr_not_configured')`。**禁止 mock 默认值假成功**（原型缺陷 S4）。
- 熔断状态存 transient（非 autoload option）。
- 出网一律异步：经 `Gr_Queue::enqueue()`（自适应 AS / WP-Cron，ADR-0007），禁止前台请求内同步调用（铁律 3）。

## 3. 许可证与数据合规

| 事项 | 决议 |
| :--- | :--- |
| 插件许可 | GPLv2+（零运行时第三方 PHP 依赖） |
| 若未来打包 LGPL-3/Apache-2.0 库（DeviceDetector、maxmind-db/reader、Action Scheduler） | 整个插件须转 GPLv3 并三处同步声明——届时走 ADR。**Action Scheduler 仅运行时嗅探使用、不打包**（ADR-0007；其许可证实为 GPLv3，`14` §1 实核） |
| GeoLite2 数据文件 | **禁止捆绑**（EULA 禁止再分发 + 60MB+ 二进制） |
| DB-IP Lite 数据文件 | **允许随包分发**（CC BY 4.0）：readme + 包内 NOTICE 做归属与数据日期声明（ADR-0007） |
| CrawlerDetect 规则数据 | **采纳为数据种子**：提取规则为本地自维护数据文件，保留 MIT 版权头与 NOTICE（`14` §1 实核：MIT、1492 行规则） |
| BotD / FingerprintJS | **禁止复制源码**（BSL 1.1）。探针独立实现，仅借鉴信号思路（iss/PEER-01 评审确认） |
| 前端第三方库 | 仅 MIT/BSD；本地打包 + 附未压缩源码 |

> 落地形态（2026-09-10，W2 实装）：CrawlerDetect 种子提取 = `plugin/assets/data/gr-ua-crawlers.txt`（1468 条）+ `gr-ua-exclusions.txt`（52 条），各带 5 行 MIT 版权头（来源/许可/数据日期 2026-09-10）；包根 `plugin/NOTICE` 含完整 MIT 许可文本与提取说明，readme `== Attribution ==` 双处归属。提取脚本做 PHP 单引号反转义（`\\`→`\`，修正 `Zend\Http\Client` 一行的字节语义）并与参考库做行为对齐校验：18/18 样本判定全同（12 爬虫 + 6 真浏览器）。后续维护 = 直接编辑数据文件（greenpng 自维护，升级种子须重跑对齐校验）。

## 4. 隐私与同意门控（双轨，ADR-0007）

1. **营销轨门控**：所有营销类追踪（UTM cookie、点击 ID、行为探针模块、CAPI/GA4 回传）必须先过 `GreenPNG\Privacy\Consent::allows('marketing')`（内部对接 WP Consent API，无 CMP 时回落到插件自带开关）。
2. **安全轨（合法利益）**：安全日志完整 IP、探针安全模块、封禁依据合法利益处理（Recital 49），不要求营销同意；设置页可整体关闭探针。
3. **目的桥接（受限）**：安全**结论**（`suspected_bot` 布尔 + 分数档位）可经 `gr_event` 进入 CRM 打标（`sys:suspected_bot`）；**原始安全信号/指纹明细永不写入联系人画像**（修正原型同一 fingerprint_hash 双用的缺陷，同时修复 iss-01 指出的"机器人被评优质客户"逻辑漏洞）。
4. **WP 核心隐私 API**：注册 `wp_privacy_personal_data_exporters` / `..._erasers`（覆盖 contacts、touchpoints、sessions、cart_abandonments），实现 `wp_add_privacy_policy_content()`。
5. **IP 双轨**：营销数据默认匿名化（IPv4 /24、IPv6 /48）；安全日志完整 IP + 展示脱敏 + 匿名化开关（`05` §3.1）。
6. **尊重 DNT 与 Sec-GPC** 作为退出信号。
7. **弃购邮件**：默认关闭；开启强制要求结账页同意勾选；否则涉及向未提交地址发营销邮件的 GDPR/CAN-SPAM 风险。

## 5. 各服务实现要点

### 5.1 Meta CAPI（v1.0）
- 版本号：`GR_META_API_VERSION` 常量 + `gr_meta_api_version` filter。
- PII 处理：出网摘要 `Gr_Secrets::hash_pii_sha256()`（小写+trim+SHA-256，**无本地前缀**——Meta 必须能从浏览器侧复算同一摘要；带 `类型|` 前缀的 `gr_hash_pii()` 只用于插件内部 join，两语义分立）；浏览器 Pixel 与服务端 CAPI 以 `event_id` 48h 去重（文档化机制，不承诺具体 EMQ 分数）。**双端同 ID 的派生机制**：服务端 `Gr_Meta_Capi::order_event_id(订单号)`（`gr_generate_event_id('capi','order|'.id)` 确定性派生，重放收敛）是唯一派生源；感谢页经 `woocommerce_thankyou` 打印 `window.GreenPNGPurchaseEventId` 全局——站长已有 Pixel 片段以 `fbq('track','Purchase',{eventID: window.GreenPNGPurchaseEventId})` 携带同一 ID，Meta 在其 48h 窗口内把两端折叠为一次转化。插件不承诺也不计算 Event Match Quality 分数。
- 仅回传**通过流量质量过滤的**事件（这是产品差异化：不把机器人喂给广告算法）。
- 派发经 `Gr_Queue`（宿主有 AS 时用其重试/并发锁）。

### 5.2 GA4 Measurement Protocol（v1.0）
- 无 `_ga` client_id 时**不回传**（用自造 ID 会产生垃圾会话数据，档案方案的错误）。
- 提供 debug 端点连通性测试按钮（显式点击触发，继承档案中最好的 UX 设计）。

### 5.3 Turnstile / hCaptcha（v1.2）
- widget 脚本只在配置了密钥的页面、仅当触发渐进式验证（连续失败 ≥2 次）时加载；绝不全站加载。
- 服务端 `siteverify` 走 `Http_Client`。

### 5.4 蜘蛛 IP 段订阅（v1.2）
- 默认关闭；开启后注册周更 cron；关闭时取消调度。
- 手动"立即刷新"按钮 + nonce。
- **允许列表决不仅凭 UA 字符串信任任何 ASN 段**（档案把 17.0.0.0/8 信任给 Applebot UA 是严重错误）。

### 5.5 AbuseIPDB（v1.2）
- 查询前置：本地允许列表短路 → transient 缓存（24h）→ 熔断器状态检查 → 才出网。
- 免费额度 1,000 次/日；达限后当日自动停止查询并记录。

### 5.6 出网 Webhook（v1.1，ADR-0016）
- 目标是站长自配端点（仅 https、≤10 个、默认全关；无任何 greenpng 自有端点）；派发走 `Gr_Queue`（铁律 3），投递经 `Gr_Http_Client`（5s 超时 + per-endpoint 熔断）。
- 载荷 = 事件 DTO 的 JSON（事件名/组/访客与会话的哈希形态/事件参数；**PII 零出网**——邮箱只出 hash）。头部四个：`X-Gr-Signature: sha256=<HMAC-SHA256(端点密钥, 原始 body)>`、`X-Gr-Event`、`X-Gr-Delivery`（32hex 去重 id）、`X-Gr-Timestamp`（接收方 ±300s 防重放）。
- 签名体即发送体（`post_raw` 预编码直发、永不重编）；验签契约文档随页给出（`hash_equals` 等价一行代码）；端点密钥 ≥16 字符、信封存储、展示仅掩码；失败 60s/300s 两梯级重试，5 连败熔断至站主复位。

### 5.7 GeoIP（DB-IP Lite，v1.0，ADR-0007）
- 数据文件随包分发（CC BY 4.0），readme + NOTICE 声明归属与数据日期；更新节奏随 release。
- `gr_geoip_country()` 查询**纯本地**，每请求 L1 静态缓存，零外呼。
- "立即更新"按钮：`manage_options` + nonce + **站长显式点击**才向 db-ip.com 发起下载；无任何静默/自动/cron 更新（铁律 1）。
- 更新失败降级为"沿用旧库 + 状态页提示"，绝不 fatal。
