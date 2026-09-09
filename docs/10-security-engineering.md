# 10. 安全工程 (Security Engineering)

> **本文档修正参考项目 Agy 原型中已确认的安全缺陷（`01` §3.1），并定义 greenpng 的安全工程基线。**

---

## 1. 客户端 IP 解析（修正缺陷 S1）

**威胁模型**：代理头（`X-Forwarded-For`、`CF-Connecting-IP`、`X-Real-IP`）可被客户端任意伪造。无条件信任它们 = 封禁与限流形同虚设 + 可伪造他人 IP 实施诬陷。

```php
final class Ip_Resolver {
    public static function resolve(): string {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $remote = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';

        $settings = gr()->settings();
        if (empty($settings['trusted_proxy_enabled'])) {
            return $remote;  // 默认：只信 REMOTE_ADDR
        }
        // 仅当 REMOTE_ADDR 属于站长配置的可信代理 CIDR，才允许读代理头
        if (!Ip_Matcher::match($remote, $settings['trusted_proxy_cidrs'])) {
            return $remote;
        }
        // 从右往左扫描 XFF：跳过可信代理，第一个不可信地址即真实客户端
        return self::walk_forwarded_for($remote, $settings['trusted_proxy_cidrs']);
    }
}
```

- 后台设置项：`trusted_proxy_enabled`（默认关）、`trusted_proxy_cidrs`（默认空，预填按钮可一键填入 Cloudflare 官方段）。
- Cloudflare 场景优先引导站长配置可信代理段，而不是盲信 `CF-Connecting-IP`。

## 2. 凭据存储（修正缺陷 S3/S4）

- 第三方 token 经 `Gr_Core_Secrets`（AES-256-GCM，密钥派生自 `wp_salt()`）加密后存 `autoload=no` 的 option。
- **禁止** `mock_*` 默认值；未配置 = 明确返回"未配置"错误并在设置页显示状态。
- 诊断导出、日志、错误消息中一律脱敏（保留前后各 2 字符）。

## 3. 调试与测试端点（修正缺陷 S2）

- **禁止**任何经 `$_GET` 触发的公开执行路径。测试代码不得随发布包分发（`build-zip.sh` 排除 `tests/`）。
- 管理诊断动作：REST 路由 + `manage_options` + nonce。

## 4. 威胁检测与拦截成熟度模型（修正 WAF 误杀）

参考项目对全量 GET/POST 跑 30 条正则并默认 403，会把"搜索 wp-config 教程的访客""提交代码片段的评论者"甚至 WordPress 核心作者归档（`?author=1`）全部封死。greenpng 采用**三档成熟度**，默认值保守：

| 档位 | 行为 | 默认 |
| :--- | :--- | :--- |
| 1 仅记录 | 所有检测命中只写 `gr_security_logs`（浪涌折叠），不拦截 | ✅ 全部规则的默认档 |
| 2 质询 | 对命中者呈现 Turnstile 无感验证（需站长已配置） | 站长逐项开启 |
| 3 拦截 | 返回 403 | 仅高置信规则（扫描器 UA、蜜罐触碰、显式封禁规则）可升到此档 |

规则设计约束：
- 只在**特定高风险参数**上匹配高置信模式（如 `UNION SELECT`、`../..` 路径穿越链）；**禁止**把 `<?php`、`eval(`、`0x[hex]`、`document.cookie`、`wp-config.php` 字样作为独立拦截条件。
- `?author=<int>` 是 WP 核心的作者归档 URL：防枚举的正确做法是**重定向到作者 slug 页或按设置隐藏**，不是 403。
- 登录防爆破：失败计数 + 递增锁定（transient，键含 IP+用户名哈希）；管理员可通过允许列表恢复，提供 WP-CLI 解锁命令，永不出现"站长锁死自己且无救"的状态。
- FCrDNS 爬虫验证：**失败开放**。`gethostbyaddr`/`gethostbyname` + `dns_get_record(DNS_AAAA)`；前台只读缓存结论，未命中异步入队，**绝不在前台同步做 DNS**（原型缺陷 P5）。任何验证失败仅标记，不拦截。
- **安全数据留存（ADR-0007）**：`gr_security_logs` 存**完整 IP**（合法利益，GDPR Recital 49；`05` §3.1）；后台展示默认脱敏（末段遮蔽）；`gr_security_log_anonymize` 开关（默认 0=完整存储）；站长开启匿名化时 UI 明示"封禁将降级为网段级"；readme 隐私披露段说明依据。iss-04 指出"匿名化后无法精准封禁"成立，据此修正。

## 5. 写入面安全

- 公开采集端点 `POST /greenpng/v1/collect`：日盐令牌（`hash_equals` 比对）+ 每 IP 限流 + 8KB body 上限 + 严格 schema 校验。
- 所有写路径幂等：转化绑定靠 UNIQUE 键 + 订单 meta 锁（HPOS 安全写法 `$order->update_meta_data()` + `save()`）。
- 事件类型与字段白名单制：客户端提交的事件名必须命中已注册清单，防日志灌水。

## 6. 输出与 XSS

- PHP：`esc_html/esc_attr/esc_url/wp_kses_post` 按上下文（PHPCS 强制）。
- JS：`textContent` 优先；必须写 HTML 时经 `GreenPNG.escapeHtml()`。
- **重点**：安全日志中展示的 UA、请求路径、payload 样本均为攻击者可控数据——它们进入后台列表与抽屉前必须转义（原型的 `innerHTML` 拼接是后台存储型 XSS）。

## 7. 权限模型

| 能力 | 用途 |
| :--- | :--- |
| `manage_options` | 全部设置写操作、规则管理、诊断导出 |
| `manage_options`（v1 不引入自定义 cap） | 读接口同样要求（流量与 CRM 数据敏感） |

- v2 若需"运营只看报表"角色，再以 ADR 引入 `gr_view_reports` 等自定义能力并注册到角色。

## 8. 依赖与供应链

- 零运行时 Composer 依赖 ⇒ 供应链面最小化。
- 前端库（如图表库）固定版本、附未压缩源码、SRI 不适用（本地文件）但需在 `SECURITY.md` 记录来源与版本。
- `composer audit`（dev 依赖）与 GitHub Dependabot 开启。

## 9. 事件响应

- `SECURITY.md` 提供漏洞报告渠道与响应承诺（72h 确认）。
- 安全修复单独发版，changelog 在修复公开后再披露细节。
