# Tak1na的自建测速 · v0.1

基于 [bg6cq/speedtest](https://github.com/bg6cq/speedtest) 的单节点测速站，保留 LibreSpeed 原有前端引擎、Worker 与测速算法，仅增加中文界面、会话保护和部署约束。当前版本是**校园 IPv4 试运行版**，不是完整公网生产上线声明。

## 功能与边界

- Ping / Jitter、下载及上传；下载/上传各十秒目标，保留上游预热与自动结束逻辑。
- 保留 `/`、`/backend/getIP.php`、`/backend/garbage.php`、`/backend/empty.php`。
- Redis 原子准入：全站同时一个测速会话；每 IP 五分钟最多两次、滚动十二小时最多五次。开始成功即计次，取消或失败不退还次数；没有全站日流量限额。
- IPv4 按 /32，IPv6 按 /128。IPv6 隐私地址轮换、代理及共享 NAT 会影响按 IP 限制的效果，不能将其当实名或防攻击认证。
- 会话最多六十秒，正常结束/取消释放，异常由 TTL 回收。单方向最多 2.5GB，下载单请求最多 100MiB、上传最多 32MiB；阶段和请求均有截止时间。
- 不启用 telemetry、结果数据库、结果分享或 ISP 查询。上传有界读取后丢弃，PHP 临时目录使用 tmpfs；不承诺 PHP 完全不缓冲。
- 按钮下的隐私说明、双语标签、原生弹窗及延迟加载的捐助图片。公开版仅包含**不能付款的占位图**，不包含个人收款码。

Redis 不持久化：重启后次数、会话和锁全部丢失。全站单会话只能避免测速之间争抢，单个会话仍可能占满线路，影响共享出口的其他用户。

## 本机启动（Linux 专用独立 VM）

建议 Debian VM：4 vCPU、4GB RAM、16GB 磁盘。需要 Docker Engine、Compose 插件、OpenSSL、systemd-journald。宿主网络模式用于保留真实客户端来源，**不要直接在 PVE 宿主机或共享主机上照搬运行**。

```bash
cp .env.example .env
# 编辑 .env，将 ST_LOG_SALT 设为 openssl rand -hex 32 的输出；不要提交此文件。
mkdir -p tls
openssl req -x509 -newkey rsa:2048 -nodes -days 7 \
  -subj /CN=localhost -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1' \
  -keyout tls/server.key -out tls/server.crt
chmod 600 tls/server.key
docker compose config -q
docker compose build --pull
docker compose up -d
curl --cacert tls/server.crt https://127.0.0.1:8443/health.php
```

访问 `https://127.0.0.1:8443/`。上述自签证书只用于本机验证，浏览器默认不信任；证书信任与公网发布条件需要另行解决，不要关闭正式客户端的 TLS 校验。

默认只监听回环：HTTP 8080、HTTPS 8443、PHP-FPM 19000、Redis 16379。启动前确认端口未占用。公开副本已去掉私有地址、校园认证脚本和生产运维记录，**不能直接覆盖现有生产部署**。

镜像基础使用可拉取的官方 PHP 8.3 / Redis 7.4 Alpine 标签，Redis 扩展固定 6.1.0。构建会从签名 APK 仓库安装更新；移动标签与 APK 更新意味着构建不保证字节复现。正式部署需记录并固定经检查的基础 digest、产出镜像 ID 与扫描结果，不能把历史镜像的安全结论套用到新构建。

## 开放给其他设备之前

1. 审核网络边界和访问范围，只开放测速入口，不开放 Redis、FPM、SSH 或虚拟化管理端口。
2. 同时调整 `upstream/docker/alpine-candidate-httpd.conf` 的监听/VHost 和 `speedtest-security.conf` 的来源与 Host 白名单；重新构建。不要单独改一个 `Require` 就视为安全上线。
3. 配置匹配入口的证书、必要的 HTTP→HTTPS 跳转及续期；HTTPS 直连才会设置 Secure Cookie。不经过 CDN 缓存或隐藏真实来源的代理；未来引入反代必须重新审查 IP 信任边界，不能直接信任转发头。
4. 按机器用途配置日志。容器使用 journald，应用日志仅含加盐来源摘要；`deploy/journald-speedtest.conf` 提供 64MB / 七天模板，容量满会更早轮转。仅在独立 VM 审核后装入 `/etc/systemd/journald.conf.d/` 并重启 journald，此限制作用于整个 VM。页面中的保留声明必须与实际配置一致。
5. 先独立验证入口、限流、取消释放、重启恢复和真实客户端，再进行经过授权、带停止阈值的性能测试。不要在共享网络直接跑无界压测。

Redis ACL 默认无密码，仅回环、专用实例、有限命令和键空间；仍允许业务必需的 Lua。它不是共享主机上的安全隔离，也不修复 Redis 自身漏洞。Redis 不可用时拒绝高流量准入；Ping 仍轻量可用。

## 验证与现状

```bash
bash tests/offline-check.sh
```

离线检查涵盖浏览器准入/收尾/停止辅助契约、测试 CLI 安全前置、仪表盘尺寸及未改算法的 SHA256。安装 PHP 时也检查 PHP 语法和来源分桶。`tests/browser-smoke.cjs` 是真实 Worker 测试，必须显式传 `--allow-quota-consumption`，并自行安装 Playwright；它会消耗流量和次数，不应默认用于生产监控。

已部署版本有校园 IPv4 真实 Worker、Chrome/Firefox 布局及受控 Ping 并发测试记录。它们不代表这份公开配置已部署或已验证千兆满载。剩余门槛见 [版本说明](CHANGELOG.md)，不要据此宣称任意线路能达到 900–950Mbps、宿舍体验不受影响或全站安全认证通过。

## 源码与许可

上游基线：`bg6cq/speedtest@57e2c9af911d8c392b16b6167249444b7b1107ed`。测速引擎 `speedtest.js` 与 `speedtest_worker.js` 保持原样，摘要见 `deploy/UPSTREAM_CORE.sha256`。`upstream/` 保留上游源码结构、README、许可证及版权信息；本项目的修改也按 LGPL-3.0 发布。修改范围与排除项见 [来源说明](UPSTREAM.md)。
