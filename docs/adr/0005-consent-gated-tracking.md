# ADR-0005: 追踪须同意门控，默认匿名化

- 状态：已接受
- 日期：2026-09-09

## 背景

参考项目的设计把以下行为设为默认开启且无同意门控：服务端种 30 天营销 cookie（`template_redirect` 即写入，早于任何同意 UI）、Canvas/WebGL/Audio 指纹、同一 `fingerprint_hash` 同时用于风控与 CRM 画像、失焦捕获邮箱后自动发挽回邮件。这些在 GDPR/ePrivacy/CCPA 下不成立，也违反 WP.org 对追踪的要求。档案 2,487 行的安全规范中 WP 隐私 API（exporters/erasers）出现 0 次。

## 决策

1. **同意门控**：营销类追踪（UTM cookie、点击 ID、行为探针、CAPI/GA4 回传）必须先过 `GreenPNG\Privacy\Consent::allows('marketing')`，内部对接 WP Consent API；站点无 CMP 时回落为插件自带开关（默认关）。安全日志依据合法利益单独处理。
2. **默认匿名化**：IP 默认截断（IPv4 /24、IPv6 /48）后存储；访客标识用每日旋转盐哈希（无跨天追踪）。
3. **目的分离**：安全信号不得写入联系人画像。
4. **隐私 API**：注册 exporters/erasers（contacts、touchpoints、sessions、cart_abandonments），提供隐私政策建议文本。
5. **尊重 DNT / Sec-GPC**。
6. **弃购邮件**默认关闭，开启强制要求结账页同意勾选。

## 后果

- 正面：GDPR/ePrivacy/CCPA 与 WP.org 审核均站得住；"隐私友好的本地分析"成为正当卖点。
- 代价：未同意访客不产生长期归因（只有会话内归因）——这是合规成本，接受。
- 明确放弃：任何"绕过 ITP/广告拦截器"的卖点话术与技术（服务端 cookie 穿透等），不实现、不宣传。
