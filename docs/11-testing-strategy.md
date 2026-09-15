# 11. 测试策略 (Testing Strategy)

> **诚实纪律**：测试结论只在确实跑过且可复现时声称。参考项目"188/188 PASS"式宣称在本项目属违规行为（`AGENTS.md` §3.4）。
> **修订记录**：2026-09-09 依据 ADR-0007——集成矩阵扩展至站长常用插件共存；新增 A/B、探针、队列双后端必测项；SQLite 冒烟任务废止（MySQL-only）。

---

## 1. 测试金字塔

```
        ┌─────────────┐
        │  E2E (少量)  │  WP-CLI 冒烟：激活/停用/卸载/升级幂等 + 常用插件共存
        ├─────────────┤
        │  集成测试    │  PHPUnit + 主验证站（真实 WP + MariaDB）
        │             │  采集端点、归因数学、幂等、瘦身、队列双后端、共存矩阵
        ├─────────────┤
        │  单元测试    │  领域层纯 PHP（仓储用内存 stub）
        │             │  CIDR、归因 5 模型、A/B Z 检验、UA 引擎、diff、提取器
        └─────────────┘
        静态层：PHPStan level 6 + PHPCS（WPCS + PHPCompatibilityWP 7.4）
```

## 2. 工具链

| 工具 | 用途 | 备注 |
| :--- | :--- | :--- |
| PHPUnit 9 | 单元 + 集成 | dev 依赖 |
| 主验证站（MariaDB + FrankenPHP） | 集成与共存测试 | ADR-0006；本地替代 wp-env（CI 仍用 wp-env/Docker 矩阵） |
| PHPStan level 6 | 静态分析 | 基线文件随仓库；**零动态属性赋值**（ADR-0007 向上防御） |
| PHPCS + WPCS + PHPCompatibilityWP | 规范 + PHP 7.4 兼容 | `phpcs.xml.dist`；`str_*` 核心函数经 WP polyfill 白名单不误报（`14` §1，S9 收口） |
| WP-CLI | 生命周期冒烟脚本 | `wp plugin activate/deactivate/uninstall` |

## 3. 必测清单（核心域）

### 3.1 安全
- IP 解析：无代理头 / 伪造 XFF / 可信代理开启后 XFF 右扫 / IPv6。
- CIDR：IPv4 边界（/32、/0、/24 跨界）、IPv6 前缀。
- FCrDNS：PTR 命中 + 正查一致（含 AAAA）、PTR 失败 → "无法验证"且**不拦截**；**验证绝不发生在前台请求路径**（经队列异步）。
- 浪涌折叠：并发 N 次写入 = 1 行且 `hit_count=N`（断言行数与计数；**不宣称百分比压缩率**）；并发压测无 1062 重复键错误。
- UA 引擎：CrawlerDetect 种子语料命中 + Exclusions 白名单不误伤（正常浏览器 UA 样本零误报）。
- 登录锁定：阈值、递增时长、允许列表恢复、CLI 解锁。
- 蜜罐：陷阱字段 + 提交时间差（<2s）夹具；`aria-hidden` 属性断言。
- 采集端点：无令牌/错令牌 401；超频 429；超大 body 413；未知事件名拒绝；**bot_score 越界值拒绝**。
- 安全 IP 双轨：完整/匿名两模式入库断言；展示脱敏输出断言。

### 3.2 归因与 A/B
- 5 模型在已知触点序列上的权重分配（手工算好的期望值）。
- **跨天主链路**：Day1（gclid 落地）→ Day3（fbclid 再入）→ Day5（下单）经 cookie visitor_id 正确关联（ISS-04 场景的正向断言）。
- **无 cookie 回退**：同访客次日哈希变化（断言无跨天关联，与文档口径一致）。
- 重复回调（`woocommerce_payment_complete` 双触发）→ 仅一条 `gr_conversions`。
- HPOS 开启/关闭两种模式的 meta 幂等锁；经典结账与 Blocks Store API 两条路径。
- A/B：一致性哈希分桶稳定性（同 visitor 多请求同桶）；Z 检验显著/不显著/样本不足三态手工期望值。

### 3.3 数据
- `dbDelta` 幂等：连续两次激活不产生变更；从 v1 到 v2 的迁移正确加列。
- `gr_daily_stats` 唯一键：重复聚合执行不翻倍。
- 瘦身：构造超龄数据 → 分批删除到保留线；瘦身后报表读数不变。
- 卸载：默认保留数据；开启删除选项后表与 option 清零。

### 3.4 集成适配器与常用插件共存矩阵（ADR-0007 / OQ-4）
- WooCommerce 不存在时：适配器零加载、零错误。
- **WooCommerce × {HPOS 开 / HPOS 关} × {经典结账 / Blocks Store API}** 四组合转化归因。
- 表单三桥：CF7 / Fluent Forms / WPForms 提交 → 触点/转化正确；主 Hook 失效时回退 Hook + 状态页告警可见。
- Elementor 前台共存：探针正常上报、无 JS 冲突。
- 缓存插件（WP Super Cache 或等价）：页面缓存命中时探针仍上报（脚本随 HTML 缓存输出）；collect 端点 nocache 头有效。
- SEO 插件（Yoast 或等价）：前台开销共存实测入报告。
- 队列双后端：AS 存在（装 WooCommerce）与不存在（纯 WP 站）各实测派发 + 互斥锁防重入。
- 适配器内抛 `\Throwable`：站点其余部分与 greenpng 其他模块不受影响。

## 4. 夹具

- `tests/fixtures/` 收纳参考项目原型的 58KB 假数据记录（UA/headers/payload 样本）作为攻击与解析语料——这是原型假数据唯一合法的归宿。
- CrawlerDetect 种子规则取子集作 UA 语料（命中 + 白名单两个方向）。
- 夹具中的假密钥（`agy_live_*` 类字符串）替换为明显的占位符（`TEST_TOKEN_NOT_A_SECRET`）。

## 5. CI（GitHub Actions，ADR-0008 五工作流）

- **Unit · PHP matrix**：桩态 WP 的领域层测试（PHP 7.4–8.5）。
- **Static · Security**：WPCS + PHP 7.4 兼容 + PHPStan L6 + JS 组件测试 + gitleaks 全史密扫。
- **WP integration**：wp-env 真栈（PHP{8.1,8.3} × WP{6.0,7.1}）——装库幂等、REST 契约、生命周期、卸载双模。
- **WP floor**：PHP 7.4 + MySQL 5.7 地板独立 compose（`tests/integration/run.sh`）。
- **E2E · Playwright**：真浏览器顾客模拟（v1.1 起，`tests/e2e/` 17 个 spec + `ci-seed.php` 数据种子，覆盖采集门控与 15 张后台页）；**CI 是 e2e 唯一实跑权威**——开发机无 Docker 时不可本地复跑，以 CI 轮为准。
- 全部绿灯才可合并；报告中如实写明跳过项。

## 6. 性能回归

- `tests/benchmarks/front-request.php` 在 CI 跑对比基准（安装插件前后 P95 差值），按 `09` §1.1 **三层口径分列**，任一层超预算即失败。
- 数字写进 PR 描述，含环境说明（含队列后端）。

## 7. 不做的

- Playwright 自 v1.1 起仅用于后台页顾客模拟 e2e（ADR-0008 工作流，见 §5），不测 JS 单元行为（组件测试由 `tests/js/` 承担）；不追求行覆盖率数字（核心域 ≥70% 为门槛，不刷无意义断言）。
- 不自建 HTML 测试报告页作为"通过证据"（参考项目的 `?agy_test_runner=1` 正是这种模式，且是安全后门）。
- 不做 SQLite 方言兼容测试（ADR-0007：MySQL-only；`:8090` SQLite 站仅为参考环境）。
