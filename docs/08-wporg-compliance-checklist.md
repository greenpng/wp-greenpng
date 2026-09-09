# 08. WordPress.org 合规清单 (Plugin Directory Compliance Checklist)

> **用途**：每次提交 WordPress.org 审核前逐项自查；CI 能自动化的项标注 🤖。
> **依据**：WordPress.org Plugin Guidelines（提交时以官网最新版为准复核编号）。

---

## 1. 许可证

- [ ] 🤖 插件头声明 `License: GPLv2 or later` + `License URI`
- [ ] 仓库根有完整 `LICENSE`（GPLv2 全文）
- [ ] 所有打包的第三方代码与 GPLv2 兼容（当前决议：**零运行时第三方 PHP 依赖**）
- [ ] 若未来引入 LGPL-3/Apache-2.0 依赖 → 先走 ADR 并整体转 GPLv3
- [ ] 所有自创代码可由项目以 GPL 发布（无从非许可源复制的代码；BotD 等 BSL 源码零复制）

## 2. 代码可读性（Guideline 4 类）

- [ ] 🤖 无混淆代码；无不透明的 minified-only 资产
- [ ] 每个压缩的第三方 JS/CSS 同目录附**同版本未压缩源码**
- [ ] `readme.txt` 有 `== External services ==` 段，逐项披露：服务名、目的、发送的数据、发送时机、ToS/隐私链接

## 3. 零外呼与追踪（Guideline 7 类）——本项目生命线

- [ ] 🤖 静态扫描：代码库中无任何指向本项目自有域名的 URL
- [ ] 无版本检查心跳、无遥测、无统计上报、无 License 校验调用
- [ ] 全部第三方集成**默认关闭**；逐一核对设置默认值
- [ ] 首次后台访问无外呼；启用某集成前不发生任何对应请求
- [ ] 蜘蛛 IP 段订阅默认关闭（参考项目设计为默认每 7 天外呼——本项目修正为 opt-in）
- [ ] 无广告拦截器规避端点；无伪装采集路径
- [ ] 追踪脚本（gr-probe.js）仅在站长启用且访客同意（WP Consent API）后输出

## 4. 数据与隐私

- [ ] 实现 `wp_privacy_personal_data_exporters` + `wp_privacy_personal_data_erasers`
- [ ] `wp_add_privacy_policy_content()` 提供建议隐私政策文本
- [ ] IP 默认匿名化；安全与营销身份分离
- [ ] 无 Canvas/WebGL/Audio 指纹追踪（安全用途的有限信号检测为 opt-in）
- [ ] 弃购邮件默认关闭，开启需同意勾选
- [ ] `uninstall.php` 存在且默认不删用户数据（删除需站长显式开启选项）

## 5. 安全基线

- [ ] 🤖 全部 SQL 走 `$wpdb->prepare()`（静态扫描裸 `->query(`）
- [ ] 🤖 全部输出转义（PHPCS WordPress-Extra）
- [ ] 🤖 全部写操作有 `current_user_can` + nonce
- [ ] 🤖 REST 路由无 `__return_true`（公开 collect 端点除外且有令牌+限流）
- [ ] 无 `?_test_runner` 类调试后门；无 `eval`；无 `create_function`
- [ ] 客户端 IP 默认仅信 `REMOTE_ADDR`
- [ ] API 密钥加密存储（`Gr_Core_Secrets`），诊断导出脱敏
- [ ] 无文件写入插件目录之外；无 `mu-plugins` drop-in；无 `auto_prepend_file`
- [ ] 无直接访问 PHP 文件的采集端点（不用独立 collect.php）

## 6. UI 与内容

- [ ] 🤖 text domain 全部为字面量 `greenpng` 且与 slug 一致
- [ ] 🤖 无硬编码中文/英文文案（全部走 `__()` 系函数）
- [ ] 零 CDN 资源（JS/CSS/字体/图片）；头像用 `get_avatar()`
- [ ] 无竞品名、无装饰性 CVE 编号、无无实测支撑的数字
- [ ] 无 "Pro/Upgrade/License/Cloud" 字样与灰化占位 UI
- [ ] 后台仅注册 1 个顶级菜单 + 子菜单（不污染 admin）；资源仅在本插件页 enqueue

## 7. readme.txt 与元数据

- [ ] 通过官方 readme 校验器（含 `Contributors`、`Tags` ≤5 个、`Requires at least: 6.0`、`Tested up to`、`Requires PHP: 7.4`、`Stable tag`）
- [ ] 名称与 slug 不含他人商标开头（Google/Meta/WooCommerce…）
- [ ] 截图存在且为真实界面
- [ ] `== Frequently Asked Questions ==` 含数据存放位置说明

## 8. 打包与发布

- [ ] ZIP 由 `tools/build-zip.sh` 产出：排除 `vendor/`（dev）、`tests/`、`docs/`、`.git*`
- [ ] 包内无 `.zip`、`node_modules`、测试夹具（除非有意）、构建缓存
- [ ] 版本号三处一致（文件头 / `GR_VERSION` / readme `Stable tag`）
- [ ] SVN 提交信息与 Git tag 对应

## 9. 已知高风险项（参考项目踩过的坑，专项复核）

| 风险 | 自查点 |
| :--- | :--- |
| Tailwind Play CDN 运行时（407KB 浏览器内 JIT） | greenpng 不使用任何 utility 框架，零此类资产 |
| 587 处内联 `onclick` + 87 个内联 `<script>` | greenpng 全部事件委托 + enqueue |
| 伪造交互层（alert 假成功、假 API 面板） | 零容忍，评审即拒 |
| 未鉴权测试端点 | 零容忍，静态扫描 |
| 默认开启的爬虫拦截致 Googlebot 误封 | 安全模块默认**仅记录**，拦截需站长逐项开启 |
