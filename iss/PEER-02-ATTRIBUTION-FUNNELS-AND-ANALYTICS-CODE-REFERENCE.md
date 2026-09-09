# PEER-02: 同类营销归因、转化漏斗与网站分析插件源码级对标与免费实现方案

> **文档定位**：针对本项目“**营销推广分析、落地页 A/B 测试、跨天多触点归因、转化漏斗与全表单打通**”的核心诉求，深入解构 `/Users/macos/wp-plug/` 目录下真实同类顶流免费开源插件的落地实现，提供源码路径、核心类与代码片段，并制定本项目在纯免费版下的标准集成方案。

---

## 一、 对标插件资产全景（源码目录与路径）

| 插件名称 | 本地真实源码路径 | 核心能力与架构借鉴点 | 许可证 |
| :--- | :--- | :--- | :--- |
| **HandL UTM Grabber** | `/Users/macos/wp-plug/05-utm-campaign-marketing-attribution/handl-utm-grabber/` | 第一方 Cookie 持久化、WooCommerce（含 HPOS/Store API）与全表单字段捕获 | GPLv2 |
| **PixelYourSite** | `/Users/macos/wp-plug/05-utm-campaign-marketing-attribution/pixelyoursite/` | 转化事件构建、PII 规范化 SHA-256 哈希与双端 event_id 去重 | GPLv2 |
| **Koko Analytics** | `/Users/macos/wp-plug/10-selfhosted-web-analytics-privacy/koko-analytics/` | 零写锁缓冲文件写入与批量聚合（支持共享主机数万 PV 极速入库） | GPLv3 |
| **CartFlows / FunnelKit** | `/Users/macos/wp-plug/06-conversion-funnel-dropoff-analytics/cartflows/`<br>`.../funnel-builder/` | 漏斗步骤状态机、流失率计算模型与结账页失焦弃购捕获 | GPLv2 |
| **Independent Analytics** | `/Users/macos/wp-plug/10-selfhosted-web-analytics-privacy/independent-analytics/` | 纯自托管网站分析、会话维度切分与多维广告战役追踪 | GPLv2 |

---

## 二、 核心插件源码级解构与实现机制

### 1. 营销参数捕获与持久化：`HandL UTM Grabber`

- **核心源码文件**：
  - `/Users/macos/wp-plug/05-utm-campaign-marketing-attribution/handl-utm-grabber/handl-utm-grabber.php`
  - `/Users/macos/wp-plug/05-utm-campaign-marketing-attribution/handl-utm-grabber/lite/woocommerce.php`
  - `/Users/macos/wp-plug/05-utm-campaign-marketing-attribution/handl-utm-grabber/lite/contact-form-7.php`

#### 核心实现机制：
1. **第一方 Cookie 30 天持久化（解决跨天归因痛点）**：
   - 当访客首次携带 UTM 参数（`utm_source`、`utm_campaign` 等）或广告点击 ID（`gclid`、`fbclid`、`ttclid`）进入任何页面；
   - 插件通过服务端（或轻量客户端 JS）将其写入第一方 SameSite=Lax Cookie（默认生命周期 30 天）；
   - **完全不使用破坏跨天追踪的每日动态盐**，确保 7~30 天内只要访客再次进入或下单，即可回溯其最早广告来源。
2. **WooCommerce 双轨订单绑定（HPOS + 古腾堡区块）**：
   - 监听 `woocommerce_checkout_update_order_meta` 与 `woocommerce_store_api_checkout_update_order_meta`；
   - 自动将 Cookie 中的 UTM 数据写入订单 Meta（HPOS 下调用 `$order->update_meta_data()`）。
- **本项目集成与可用函数映射**：
  - 映射为本项目函数：`gr_parse_attribution_params()` 与 `gr_bind_conversion()`；
  - 触点持久化由 `Attribution_Listener` 统一接管，完美解决此前每日盐导致的跨天归因失效！

---

### 2. 电商 CAPI 事件与去重中枢：`PixelYourSite`

- **核心源码文件**：
  - `/Users/macos/wp-plug/05-utm-campaign-marketing-attribution/pixelyoursite/facebook-pixel-master.php`
  - `/Users/macos/wp-plug/05-utm-campaign-marketing-attribution/pixelyoursite/includes/class-events-manager.php`

#### 核心实现机制：
1. **PII 严格规范化与不可逆 SHA-256 哈希**：
   - 邮箱：`hash('sha256', strtolower(trim($email)))`；
   - 手机号：去掉所有符号、前导 0，保留纯数字后哈希；
2. **浏览器与服务端双端去重（Event Deduplication）**：
   - 在前端输出的 Pixel 事件与服务端异步出网的 CAPI 事件中使用**完全相同的 `event_id`**（如 `gr_ord_10024_1710000000`）；
   - 广告平台（Meta / Google / TikTok）在 48 小时窗口内自动去重，既保障 iOS 14.5+ ATT 隐私拦截下的高转化召回率，又杜绝了重复计费。
- **本项目集成与可用函数映射**：
  - 映射为本项目函数：`gr_capi_build_payload()` 与 `gr_generate_event_id('gr_ev')`；
  - 结合 `Gr_Queue` 异步队列可靠派发，纯免费版同样享受专业级 CAPI 回传。

---

### 3. 零写锁高并发数据吞吐：`Koko Analytics`

- **核心源码文件**：
  - `/Users/macos/wp-plug/10-selfhosted-web-analytics-privacy/koko-analytics/src/Resources/functions/collect.php`
  - `/Users/macos/wp-plug/10-selfhosted-web-analytics-privacy/koko-analytics/src/class-aggregator.php`

#### 核心实现机制：
- **痛点解决**：在没有 Redis 的廉价共享主机上，如果每来一个访客就直接在 MySQL 执行 `INSERT/UPDATE`，高并发下会导致数据库严重行锁竞争；
- **Koko 的破局之道**：
  1. **采集轻量化**：前台 `navigator.sendBeacon` 将极简参数发往采集端；
  2. **缓冲管道（Buffer）**：请求被迅速以纯文本行（TSV / JSON）追加写入临时缓冲文件（`uploads/cache/koko-analytics/buffer.php`）或对象缓存，耗时 < 1ms，**前台零数据库查询**！
  3. **定时批量聚合（Aggregator）**：后台每小时或每日通过 Cron 执行一次批量聚合（`INSERT INTO gr_daily_stats ... ON DUPLICATE KEY UPDATE`），将上万条浏览合并为极简的日汇总数据。
- **本项目集成方案**：
  - 会话与大盘统计引入轻量分批缓冲机制，从根本上兑现 `docs/09` 中的“前台执行 ≤5ms、≤2 条 SQL”指标。

---

### 4. 漏斗状态机与原生流失柱状图：`CartFlows / FunnelKit`

- **核心源码文件**：
  - `/Users/macos/wp-plug/06-conversion-funnel-dropoff-analytics/cartflows/modules/tracking/`
  - `/Users/macos/wp-plug/06-conversion-funnel-dropoff-analytics/funnel-builder/modules/analytics/`

#### 核心实现机制：
1. **步骤状态机流转**：
   - 步骤 1：落地页 / 产品页（Step 1: Landing/View）；
   - 步骤 2：加入购物车（Step 2: Add-to-Cart）；
   - 步骤 3：发起结账（Step 3: Initiate-Checkout）；
   - 步骤 4：支付完成（Step 4: Purchase）。
2. **轻量流失率计算**：
   ```php
   // 流失率计算公式
   $dropoff_rate = ($step_views - $next_step_views) / max(1, $step_views) * 100;
   ```
3. **原生 WP 阶梯流失图呈现（无需任何重量级前端框架）**：
   利用纯 WordPress 原生样式 + 极简 CSS Flex 即可渲染出媲美 SaaS 的流失图：
   ```html
   <div class="gr-funnel-step">
       <div class="gr-funnel-step__header">
           <strong>步骤 1: 落地页访问</strong>
           <span>10,000 访客 (100%)</span>
       </div>
       <div class="gr-funnel-step__bar" style="width: 100%; background: #2271b1;"></div>
       <div class="gr-funnel-step__dropoff">🔻 流失 68% (6,800 人离开)</div>
   </div>
   ```
- **本项目集成与可用函数映射**：
  - 映射为本项目函数：`gr_funnel_track_step()` 与 `gr_funnel_get_stats()`；
  - 恢复被同事误废弃的流失图展示，让独立站长清晰掌握每一个营销步骤的瓶颈。

---

### 5. 落地页 A/B 测试：双比例 Z 检验算法（纯 PHP 本地运算）

- **算法落地实现**：
  ```php
  // includes/funnel/class-gr-ab-engine.php
  public static function calculate_z_score(int $conversions_a, int $visitors_a, int $conversions_b, int $visitors_b): array {
      if ($visitors_a < 30 || $visitors_b < 30) {
          return ['significant' => false, 'message' => __('样本量不足 30，继续测试中...', 'greenpng')];
      }

      $p1 = $conversions_a / $visitors_a;
      $p2 = $conversions_b / $visitors_b;
      $p_pool = ($conversions_a + $conversions_b) / ($visitors_a + $visitors_b);
      $se = sqrt($p_pool * (1 - $p_pool) * (1 / $visitors_a + 1 / $visitors_b));

      if ($se == 0) return ['significant' => false, 'p_value' => 1.0];

      $z = ($p2 - $p1) / $se;
      $significant = abs($z) >= 1.96; // 95% 置信度标准正态分布分位数

      return [
          'z_score'     => round($z, 4),
          'significant' => $significant,
          'winner'      => $significant ? ($z > 0 ? 'B' : 'A') : 'none',
          'confidence'  => $significant ? '≥ 95%' : '< 95%'
      ];
  }
  ```
- **落地表现**：
  - 纯 PHP 数学运算，耗时 < 0.1ms，零外部 Python 或云端服务；
  - 支持站长在 v1.1 即可通过简单短码或 URL 参数测试不同的广告落地页，达成核心营销指标！
