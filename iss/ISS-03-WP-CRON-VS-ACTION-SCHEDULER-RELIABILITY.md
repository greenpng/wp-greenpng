# ISS-03: 排斥 Action Scheduler 导致后台作业极度不可靠

| 严重级别 | 缺陷分类 | 涉及文档 | 状态 |
| :--- : | :---: | :--- | :---: |
| **P1 严重** | 异步架构 / 系统可靠性 | `docs/02-architecture-blueprint.md` §2.4<br>`docs/adr/0003-php74-no-composer-runtime.md`<br>`docs/07-third-party-apis.md` §2 | **已完成深度对标与纠偏** |

---

## 一、 缺陷背景与决策记录对照

同事在 `docs/02-architecture-blueprint.md` §2.4 与 ADR-0003 中做出一项重大决策：
> “**异步只用 WP-Cron + 单次事件，不打包 Action Scheduler（v1）**。
> 出网 CAPI、邮件挽回等延迟任务：`wp_schedule_single_event()`。
> 理由：Action Scheduler 带来 4 张表、GPLv3 传染、全站版本协商问题，换 v1 的 3 个后台任务不值得。”

这一决策表面上看似降低了系统复杂度，但忽视了 WordPress 原生伪定时（WP-Cron）在生产环境中的**极端脆弱性**，对弃购挽回、广告平台 CAPI 数据同步、每日报表聚合等核心运营能力构成了严重威胁。

---

## 二、 核心问题深度解剖与同类插件开源实现对标

### 1. 致命缺陷：WP-Cron 的“伪定时”特性导致关键业务严重延宕与漏发

#### 为什么独立站不能纯依赖 WP-Cron？
1. **依赖访客 HTTP 触发的死穴**：
   - WordPress 内置的 WP-Cron 不是真正的操作系统级常驻进程（Daemon），它**完全依赖访客在前台打开页面时触发 `wp-cron.php`**。
   - 在低流量独立站（如客单价较高的 B2B、小众独立电商，每天仅几十个访客）：
     - 假设访客在晚间 23:00 弃购离开，设定 15 分钟后触发挽回（`wp_schedule_single_event(time() + 900, 'gr_cart_recover')`）；
     - 若直到第二天上午 09:00 才有下一个访客访问站点，该弃购任务**将被延后整整 10 个小时执行**！
     - 营销统计表明：弃购挽回邮件在 15~60 分钟内打开率最高，超过 6 小时后转化率暴跌 85%。
2. **CAPI 广告算法的实时性崩盘**：
   - Meta Conversions API 与 Google Ads 算法依赖实时转化信号来优化当天的广告出价和人群定向；
   - 依赖 WP-Cron 延迟几个小时甚至半天才上报转化，广告系统的实时投流算法完全失去参考依据。

---

### 2. 灾难隐患：高并发下的“并发重复执行”与无并发锁保护

在流量突发或站长开展促销活动时，WP-Cron 的并发重入漏洞尤为致命：
1. **并发重入导致重复扣费与重复邮件**：
   - 多个访客几乎同时请求页面，多个 PHP 线程可能同时触发 `spawn_cron()`；
   - 在没有任何数据库行锁或分布式锁保护的情况下，同一条弃购任务或 CAPI 上报任务会被执行两次：
     - 向同一个潜在客户连发两封一模一样的弃购挽回邮件（极差的品牌形象，易被判定为垃圾邮件发件人）；
     - 向 Meta CAPI 连续发送重复的 Purchase 转化事件（可能导致广告平台误判投产比或在去重失败时重复统计）。

---

### 3. 同类开源插件是如何实现双赢的？（不用捆绑也能享受 Action Scheduler）

翻阅 `/Users/macos/wp-plug/` 下优秀的同类免费插件，它们的架构设计极具启发性：
- **`AutomatorWP`**（源码路径：`/Users/macos/wp-plug/09-user-scoring-tagging-rfm-crm/automatorwp/includes/action-scheduler.php`）
- **`FluentCRM`**（源码路径：`/Users/macos/wp-plug/09-user-scoring-tagging-rfm-crm/fluent-crm/`）
- **`CartFlows`**（源码路径：`/Users/macos/wp-plug/06-conversion-funnel-dropoff-analytics/cartflows/`）

#### 它们的标准范式：渐进式运行时嗅探（Adaptive Runtime Sniffing）
这些顶级插件**在免费版中根本不需要打包 Action Scheduler 代码**，也没有产生任何许可证冲突，它们仅仅在运行时做一层防御性检测：
```php
if (function_exists('as_enqueue_async_action')) {
    // 宿主有 Action Scheduler（如安装了 WooCommerce），优先走可靠队列！
    as_enqueue_async_action('gr_capi_dispatch', array($channel, $event, $data), 'greenpng');
} else {
    // 普通环境平滑回落至 WP-Cron
    wp_schedule_single_event(time(), 'gr_capi_dispatch', array($channel, $event, $data));
}
```
- **事实依据**：用户的核心目标平台是 WooCommerce 独立电商站，而**每一个 WooCommerce 站点都已经天然激活了 Action Scheduler**！
- 故意排斥已有的企业级可靠队列，属于教条式的自我设限。

---

## 三、 纠偏与落地整改方案（结合可用函数映射）

| 序号 | 改进项 | 生产级优化方案 | 对应可用函数与类 |
| :--- : | :--- | :--- | :--- |
| **1** | **渐进式队列适配器** | 封装统一队列门面 `Gr_Queue::enqueue()`：运行时嗅探宿主环境。若存在 Action Scheduler 则享受自动重试与并发锁；未安装时平滑降级至 WP-Cron。 | `Gr_Queue`<br>`gr_capi_dispatch()` |
| **2** | **防重入互斥并发锁** | 在 WP-Cron 降级链路中，加入轻量级 transient 互斥锁（TTL 300s）：任务开始时加锁，执行完毕核销，彻底消除并发重复发信问题。 | `gr_cart_mark_abandoned()`<br>`Gr_Cart_Recovery_Service` |
| **3** | **失败任务持久化与重试** | 借鉴 Action Scheduler 的死信理念，网络出网失败的任务写入数据库状态标记，并在每日维护任务中重试，绝不发生数据永久静默丢失。 | `gr_schedule_maintenance()`<br>`Gr_Http_Client` |
