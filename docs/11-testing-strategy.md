# 11. 测试策略 (Testing Strategy)

> **诚实纪律**：测试结论只在确实跑过且可复现时声称。参考项目"188/188 PASS""WordPress 7.1 环境"式宣称在本项目属违规行为（`AGENTS.md` §3.4）。

---

## 1. 测试金字塔

```
        ┌─────────────┐
        │  E2E (少量)  │  WP-CLI 冒烟：激活/停用/卸载/升级幂等
        ├─────────────┤
        │  集成测试    │  PHPUnit + wp-env（真实 WP + MySQL）
        │             │  采集端点、归因数学、幂等、瘦身、cron
        ├─────────────┤
        │  单元测试    │  领域层纯 PHP（仓储用内存 stub）
        │             │  CIDR、归因 5 模型、RFM、Z 检验、diff、提取器
        └─────────────┘
        静态层：PHPStan level 6 + PHPCS（WPCS + PHPCompatibilityWP 7.4）
```

## 2. 工具链

| 工具 | 用途 | 备注 |
| :--- | :--- | :--- |
| PHPUnit 9 | 单元 + 集成 | dev 依赖 |
| `wp-env`（@wordpress/env） | 集成测试容器环境 | Node 24 已具备 |
| PHPStan level 6 | 静态分析 | 基线文件随仓库 |
| PHPCS + WPCS + PHPCompatibilityWP | 规范 + PHP 7.4 兼容 | `phpcs.xml.dist` |
| WP-CLI | 生命周期冒烟脚本 | `wp plugin activate/deactivate/uninstall` |

## 3. 必测清单（核心域）

### 3.1 安全
- IP 解析：无代理头 / 伪造 XFF / 可信代理开启后 XFF 右扫 / IPv6。
- CIDR：IPv4 边界（/32、/0、/24 跨界）、IPv6 前缀。
- FCrDNS：PTR 命中 + 正查一致（含 AAAA）、PTR 失败 → "无法验证"且**不拦截**。
- 浪涌折叠：同折叠键 N 次写入 = 1 行且 `hit_count=N`（断言行数与计数，**不宣称百分比压缩率**）。
- 登录锁定：阈值、递增时长、允许列表恢复、CLI 解锁。
- 采集端点：无令牌/错令牌 401；超频 429；超大 body 413；未知事件名拒绝。

### 3.2 归因
- 5 模型在已知触点序列上的权重分配（手工算好的期望值）。
- 重复回调（`woocommerce_payment_complete` 双触发）→ 仅一条 `gr_conversions`。
- HPOS 开启/经典存储两种模式的 meta 幂等锁。
- cookie 被禁/被清时的降级（末次会话内归因）。

### 3.3 数据
- `dbDelta` 幂等：连续两次激活不产生变更；从 v1 到 v2 的迁移正确加列。
- 瘦身：构造超龄数据 → 分批删除到保留线；汇总表在瘦身后报表不变。
- 卸载：默认保留数据；开启删除选项后表与 option 清零。

### 3.4 集成适配器
- WooCommerce 不存在时：适配器零加载、零错误。
- 适配器内抛 `\Throwable`：站点其余部分与 greenpng 其他模块不受影响。

## 4. 夹具

- `tests/fixtures/` 收纳参考项目原型的 58KB 假数据记录（UA/headers/payload 样本）作为攻击与解析语料——这是原型假数据唯一合法的归宿。
- 夹具中的假密钥（`agy_live_*` 类字符串）替换为明显的占位符（`TEST_TOKEN_NOT_A_SECRET`）。

## 5. CI（GitHub Actions）

- 矩阵：PHP 7.4 / 8.1 / 8.3 × WP 6.0 / latest × MySQL 5.7 / 8.0。
- 阶段：lint（php -l 全量）→ PHPCS + PHPCompatibility → PHPStan → PHPUnit → 生命周期冒烟。
- 全部绿灯才可合并；报告中如实写明跳过项。

## 6. 性能回归

- `tests/benchmarks/front-request.php` 在 CI 跑对比基准（安装插件前后 P95 差值），超预算（`09` §1.1）即失败。
- 数字写进 PR 描述，含环境说明。

## 7. 不做的

- v1 不引入 Playwright（无 SPA）；不追求行覆盖率数字（核心域 ≥70% 为门槛，不刷无意义断言）。
- 不自建 HTML 测试报告页作为"通过证据"（参考项目的 `?agy_test_runner=1` 正是这种模式，且是安全后门）。
