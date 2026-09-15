# ADR-0011 · 离线 IP 质量层（G2 裁定：机房段采纳、Tor 出口拒绝）

> **编号**：0011　**日期**：2026-09-13　**状态**：已接受
> **背景材料**：ISS-08 G2、`docs/15-iss09-review-verdicts.md` §4（维持讨论）、`docs/16-iss10-review-verdicts.md` §3/§5（两问：Tor 列表陈旧性与更新语义；机房段库数据源许可与归属——本 ADR 定夺）。
> **触发问题**：v1.0 会话只带国家码；云机房段承载的爬虫/RPA/广告刷量在渠道与设备维度上不可见。G2 悬置两轮评审，v1.1 启动时须裁（`docs/16` §3 既定安排）。

---

## 1. 背景

1. 同类做法核验（wp-plug/03 实读）：peers 的 Tor/机房判定几乎全部依赖 MaxMind GeoIP2 Anonymous-IP 库或 IP2Location BIN——**前者被本项目明令禁捆**（AGENTS.md：许可受限二进制库），后者的 BIN 属私有序言格式且更新走账号 token。
2. 许可事实（2026-09-13 官网 FAQ 实查）：IP2Location 对 LITE 库明文允许「随应用再分发一份副本 + 归属，但须告知用户自行注册以获取更新；第三方数据库仓库式分发不允许」。LITE 整库许可为 CC BY-SA 4.0。
3. 官方云厂商公开发布段表、无需认证直接拉取：AWS `ip-ranges.json`（ip-ranges.amazonaws.com）、Azure ServiceTags JSON、Google `goog.json`（gstatic ipranges）——事实性公开数据，站主显式点击拉取与 DB-IP 更新按钮同类（铁律 1 白名单）。
4. Tor 出口列表**小时级轮换**（docs/16 §3 已记录该顾虑）：静态随包必然小时级陈旧；按钮更新语义面对小时级轮换同属失效类（DB-IP 按钮对月度库成立，对小时库不成立）。逐请求查询类 API 不可行：前台同步外呼被铁律 3 禁止，且会把访客 IP 发给第三方（隐私双轨下不可接受）。

## 2. 决策

### D1 · 机房段离线层：随包 CIDR 文本 + 打包器复用 DB-IP 纪律

- 交付物：`data/` 下一个**纯文本 CIDR 列表**（IPv4+IPv6，每行一条，文件头含来源/数据日期/归属声明），由 IP2Proxy LITE（PX 库）CSV 的 **DCH 行**（机房/托管段，PX 词表：PUB/VPN/TOR/DCH/SES/RES——只取 DCH）过滤生成；打包为 packed PHP 数组（`Gr_Geoip_Packer` 同款管线，请求内静态加载、内存匹配、零 SQL）。
- 发布节奏：数据文件随 release 更新（仓库侧开发期下载生成，非站点运行时拉取）；数据日期在 IP Intelligence 页如实显示。机房段变更缓慢（ASN 级段表，月度上游更新足够），随包节奏的陈旧度诚实标注即可。
- 归属与披露：readme External services/数据归属段登记 IP2Proxy LITE（CC BY-SA 4.0，归属 + 「注册 lite.ip2location.com 获取更新」告知义务）；文件头保留原库归属行。BSL/代码零复制纪律不受影响（纯数据）。

### D2 · 站长显式更新按钮：官方云厂商段表（免注册、免认证）

- IP Intelligence 页新增「机房段更新」按钮：仅点击时拉取 AWS/Azure/Google 官方段表 JSON，合并进 packed 缓存（站点本地，autoload=no option 或文件缓存），显示最近拉取时间。零静默外呼（铁律 1 同 DB-IP 先例）；端点列入 readme 披露。
- 该按钮解决「IP2Proxy LITE 更新需站主自行注册」的落差：不注册也有免认证的官方源可刷（覆盖最大宗云机房流量）；D1 随包底座覆盖长尾托管商。
- **实施批注（2026-09-15，:8091 实弹后）**：①文件缓存落地为 uploads 目录 override 文件（temp+rename 原子发布，失败保旧数据）——优于 option（免 base64 开销、opcache 可编译）；②Azure 无稳定 JSON 直链，只有稳定下载页——刷新从页面 HTML 实时发现当日 ServiceTags JSON URL 后再拉，页与 JSON 两跳都计入「显式点击」授权；③批量数据下载的线预算为 120s（docs/07 §2 已同步：5s 只属 API 类——2.7MB 起的段表在 5s 预算下刷新必败，铁律 1 的「显式点击」授权面不变）；④诚实词表三态：`refreshed`（含逐源名单——部分源失败不沉掉其余源）、`failed/no_source`（零源应答，旧数据保全）、`failed/empty_pack`（有源应答但零段——拒绝晋升空包）。源「应答过」以 fetch 解析成功为准，应答但零段与 fetch 失败在审计上严格区分。

### D3 · Tor 出口列表：**拒绝进 v1.1**（并记录重启条件）

- 理由：小时级轮换使随包与按钮两模型都只能提供小时级陈旧数据，作为「判定证据」价值存疑、作为「展示信号」误导性强；真实 API 路线被铁律 3 与隐私双轨双重禁止。**这是裁定不做，不是再议延期**。
- 重启条件（任一满足才重开讨论）：① 出现许可干净、更新语义匹配按钮模型（亚日级）的可再分发 Tor 段源；② 真实站点数据显示 Tor 出口流量构成值得专门信号的占比。

### D4 · 判定落点：会话行类别词，原始 IP 永不落营销轨

- `gr_sessions` 增列 `ip_quality VARCHAR(16) NOT NULL DEFAULT ''`（DB_VERSION 2，与 ADR-0010 同批），取值 `'hosting'`（命中 D1/D2 段表）或 `''`（未知/住宅）。
- 计算点：归因监听器落地会话的同一 upsert（首触写入、重复 touch 不覆写——与 channel 落地纪律同款），匹配在内存完成（packed 数组 + `Gr_Ip_Matcher` CIDR 原语），**前台 SQL 条数不变**（同一条 upsert 增一列），原始 IP 不落任何营销轨表——`ip_quality` 是类别结论词，非标识符，与 `country_code` 同级。
- **仅展示与报表，绝不自动定罪**：bot 判定权仍在探针/检测结论通道（ADR-0009 D1/D2 语义不变）；机房段 ≠ 机器人（企业 VPN、合规爬虫、代理用户皆在列），自动定罪必伤真人。

### D5 · G8 城市级 GeoIP 维持范围外（v1.1 不做）

- DB-IP City 同为 CC BY 但文件量级更大、报表增益有限；v1.3 打磨版再评（`docs/12` v1.3 行既列）。

## 3. 后果

- 正面：渠道/战役报表首次可见机房流量占比（配合 v1.1 战役无效流量标签页）；站长获免注册的段表更新路径；G2 两轮悬置正式收口。
- 负面/风险：随包列表在两次 release 之间渐陈旧（数据日期如实显示 + D2 按钮缓解）；DCH 数据源覆盖度依赖 IP2Proxy LITE 的收录质量（归属披露 + 长尾由云厂商官方源补充）。
- 合规：零出网默认（D2 仅显式点击）；营销轨不新增任何标识符存储；readme 披露面更新（IP2Proxy 归属 + 三个官方段表端点）。
- 修订关联：`docs/05` §3.2（ip_quality 列）、`docs/12`（IP Intelligence 扩面行）、readme 数据归属段随实现同步。
