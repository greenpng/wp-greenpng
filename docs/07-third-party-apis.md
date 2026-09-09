# 07. 第三方 API 集成规范 (Third-Party APIs)

> **总原则**：greenpng 自身零外呼；第三方 API 全部**站长自填凭据 + 默认关闭（opt-in）**，且每个都必须在 `readme.txt` 的 `== External services ==` 段披露（服务名、发送什么数据、何时发送、服务条款与隐私政策链接）。
> **来源**：对 wp-plug 档案全部 API 计划的逐项审计结论（细节见 `01` §6）。

---

## 1. 收录决策总表

| 服务 | 决策 | 默认状态 | 说明 |
| :--- | :--- | :--- | :--- |
| Cloudflare Turnstile | ✅ v1 | 关 | 登录/注册/表单人机验证；真免费；最隐私友好；密钥配置后才加载 widget |
| GA4 Measurement Protocol | ✅ v1 | 关 + 同意门控 | 服务端事件回传；提供 `/debug/mp/collect` 连通性自检 |
| Meta Conversions API | ✅ v1 | 关 + 同意门控 | Graph 版本号为**可过滤常量** `GR_META_API_VERSION`（档案硬编码的 v19.0 已停支持，教训） |
| 通用 Webhook（HMAC-SHA256） | ✅ v1 | 关 | 签名串 `timestamp.body`；附带 Slack/飞书/企微/钉钉消息格式器（纯本地格式化，无凭据） |
| FCrDNS 爬虫验证 | ✅ v1 | **开，但仅记录模式** | 系统 DNS，无凭据；IPv6 正查修复；失败一律放行（见 `10` §4） |
| 搜索引擎蜘蛛 IP 段订阅 | ⚠️ v1 | 关 | 手动刷新按钮 + 可选周更（开启才注册 cron）；目标：Google/Bing/Apple 官方 JSON |
| AbuseIPDB | ⚠️ v1 | 关 | 免费 1,000 次/日；24h 缓存 + 熔断器；本地允许列表优先短路 |
| MaxMind GeoLite2 | ⚠️ v1 | 关 | **站长自填 License Key 自行下载，插件绝不捆绑 mmdb**；下载校验 SHA-256；UI 展示 MaxMind EULA 链接与同意勾选；失败降级"geo 不可用"而非 fatal |
| hCaptcha | ⚠️ v1.1 | 关 | 第二人机验证提供方（替代 reCAPTCHA） |
| Matomo（自建实例） | ⚠️ v1.1 | 关 | 站长自有服务器，GPL 兼容 |
| TikTok Events API | ⚠️ v1.2 | 关 | 需 TikTok 企业账号；版本常量化 |
| Google Ads 离线转化 | ❌ 放弃 | — | 需 Google 审批的开发者令牌 + OAuth2 应用审核；档案锁定的 v16 已 sunset。这是硬限制，不是排期问题。如未来重新评估需 ADR |
| Google reCAPTCHA | ❌ 放弃 | — | 已并入 Google Cloud 计费与密钥体系；欧盟认定加载即传输个人数据需事先同意；Turnstile + hCaptcha 覆盖需求 |
| IPQualityScore | ❌ 放弃 | — | API Key 置于 URL 路径会泄漏进日志/诊断导出 |
| proxycheck.io | ❌ 放弃 | — | 免费额度过小，与 AbuseIPDB 职责重复 |
| 会话回放（rrweb 类） | ❌ 永不 | — | 最高隐私风险类别 |
| 一次性邮箱在线 API | ❌ | — | 用本地域名清单即可 |
| 一切自建云端（antigravity.ai 等） | ❌ 删除 | — | 免费版定位（ADR-0002） |

## 2. 统一出网通道：`GreenPNG\Core\Http_Client`

**所有** `wp_safe_remote_*` 调用必须经此类，禁止业务代码直接出网：

```php
GreenPNG\Core\Http_Client::post('meta_capi', $endpoint, $payload);
// 每个服务有独立配置：timeout≤5s、熔断（连续 3 次失败断开 300s）、
// 429 读 Retry-After、失败退避重试（30s/2m/15m，3 次后放弃并记审计）
```

- 未配置凭据 → 立即返回 `WP_Error('gr_not_configured')`。**禁止 mock 默认值假成功**（原型缺陷 S4）。
- 熔断状态存 transient（非 autoload option）。
- 出网一律异步：`wp_schedule_single_event()`，禁止前台请求内同步调用（铁律 3）。

## 3. 许可证与数据合规

| 事项 | 决议 |
| :--- | :--- |
| 插件许可 | GPLv2+（零运行时第三方 PHP 依赖） |
| 若未来打包 LGPL-3/Apache-2.0 库（DeviceDetector、maxmind-db/reader、Action Scheduler） | 整个插件须转 GPLv3 并三处同步声明（文件头、LICENSE、readme.txt）——届时走 ADR |
| GeoLite2 数据文件 | **禁止捆绑**（EULA 禁止再分发 + 60MB+ 二进制）。站长自填 Key 下载，或改用 DB-IP Lite（CC BY 4.0）/ IP2Location LITE 等可再分发数据集（同样按需下载） |
| BotD / FingerprintJS | **禁止复制源码**（BSL 1.1）。任何 bot 检测逻辑独立实现 |
| CrawlerDetect 正则 | 若采用，保留 MIT 版权头与 NOTICE |
| 前端第三方库 | 仅 MIT/BSD；本地打包 + 附未压缩源码 |

## 4. 隐私与同意门控（强制）

1. **门控点**：所有营销类追踪（UTM cookie、点击 ID、行为探针、CAPI/GA4 回传）必须先过 `GreenPNG\Privacy\Consent::allows('marketing')`（内部对接 WP Consent API，无 CMP 时回落到插件自带开关）。
2. **分离安全与营销身份**：安全日志的 IP/UA 依据合法利益处理；**禁止**把安全指纹写入联系人画像（原型把同一 fingerprint_hash 同时用于风控与 CRM，违反目的限定原则）。
3. **WP 核心隐私 API**：注册 `wp_privacy_personal_data_exporters` / `..._erasers`（覆盖 contacts、touchpoints、sessions、cart_abandonments），实现 `wp_add_privacy_policy_content()`。
4. **IP 匿名化**：`gr_anonymize_ip` 默认开（IPv4 /24、IPv6 /48 截断）；安全日志可选择关闭（用于取证），UI 明示后果。
5. **尊重 DNT 与 Sec-GPC** 作为退出信号。
6. **弃购邮件**：默认关闭；开启强制要求结账页同意勾选；否则涉及向未提交地址发营销邮件的 GDPR/CAN-SPAM 风险。

## 5. 各服务实现要点

### 5.1 Meta CAPI
- 版本号：`GR_META_API_VERSION` 常量 + `gr_meta_api_version` filter。
- PII 处理：`gr_hash_pii()`（小写+trim+SHA-256）后出网；浏览器 Pixel 与服务端 CAPI 以 `event_id` 48h 去重（文档化机制，不承诺具体 EMQ 分数）。
- 仅回传**通过流量质量过滤的**事件（这是产品差异化：不把机器人喂给广告算法）。

### 5.2 GA4 Measurement Protocol
- 无 `_ga` client_id 时**不回传**（用自造 ID 会产生垃圾会话数据，档案方案的错误）。
- 提供 debug 端点连通性测试按钮（继承档案中最好的 UX 设计）。

### 5.3 Turnstile / hCaptcha
- widget 脚本只在配置了密钥的页面、仅当触发渐进式验证（连续失败 ≥2 次）时加载；绝不全站加载。
- 服务端 `siteverify` 走 `Http_Client`。

### 5.4 蜘蛛 IP 段订阅
- 默认关闭；开启后注册周更 cron；关闭时取消调度。
- 手动"立即刷新"按钮 + nonce。
- **允许列表决不仅凭 UA 字符串信任任何 ASN 段**（档案把 17.0.0.0/8 信任给 Applebot UA 是严重错误）。

### 5.5 AbuseIPDB
- 查询前置：本地允许列表短路 → transient 缓存（24h）→ 熔断器状态检查 → 才出网。
- 免费额度 1,000 次/日；达限后当日自动停止查询并记录（档案中为该服务设计的熔断模式推广到所有服务）。

### 5.6 出网 Webhook
- 载荷：`{event, timestamp, site, data}`；头部 `X-GR-Event`、`X-GR-Timestamp`、`X-GR-Signature: sha256=<hmac(timestamp.body, secret)>`。
- 验签文档（给接收方）：`hash_equals` 比较，时间戳容差 ±300s 防重放。
