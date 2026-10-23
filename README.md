# Tak1na的自建测速 · v0.1.0

[![Source checks](https://github.com/Tak1naa/SpeedTest/actions/workflows/checks.yml/badge.svg)](https://github.com/Tak1naa/SpeedTest/actions/workflows/checks.yml)
[![Release](https://img.shields.io/github/v/release/Tak1naa/SpeedTest)](https://github.com/Tak1naa/SpeedTest/releases)
[![License: LGPL-3.0](https://img.shields.io/badge/license-LGPL--3.0-blue.svg)](LICENSE)

[English](README.en.md) · 简体中文 · [参与贡献](CONTRIBUTING.md) · [安全策略](SECURITY.md) · [公开路线图](ROADMAP.md)

A bilingual, self-hosted network speed test built on LibreSpeed/bg6cq, with Redis session limits, bounded transfers, and loopback-first Docker Compose deployment.

基于 [bg6cq/speedtest](https://github.com/bg6cq/speedtest) 的单节点测速站，保留 LibreSpeed 原有前端引擎、Worker 与测速算法，仅增加中文界面、会话保护和部署约束。v0.1.0 是首个非预发布的**源码正式版**；默认仍是回环评估配置，不代表完整公网生产或安全验收。

当前工作树另含[未发布安全修复候选](HARDENING.md)：HAProxy 薄入口、在途请求租约、宽松前缀短窗保护和镜像扫描门禁。它不等于已发布 v0.1.0 或生产已部署。

![桌面界面，未运行测速](docs/screenshots/desktop.png)

<details>
<summary>移动端与支持弹窗</summary>
<img src="docs/screenshots/mobile.png" width="320" alt="390px 移动端真实界面，未运行测速" />
<img src="docs/screenshots/coffee.png" width="680" alt="内嵌不可支付占位图的支持弹窗" />
</details>

截图来自公开 PHP 页面，不含生产数据、个人收款码或虚构测速值。[生成方式](docs/screenshots/README.md)。

## 功能与边界

- Ping / Jitter、下载及上传；下载/上传各十秒目标，保留上游预热与自动结束逻辑。
- 保留 `/`、`/backend/getIP.php`、`/backend/garbage.php`、`/backend/empty.php`。
- Redis 原子准入：全站同时一个测速会话；每 IP 五分钟最多两次、滚动十二小时最多五次。开始成功即计次，取消或失败不退还次数；没有全站日流量限额。
- IPv4 按 /32，IPv6 按 /128。IPv6 隐私地址轮换、代理及共享 NAT 会影响按 IP 限制的效果，不能将其当实名或防攻击认证。
- 候选额外限制入口连接/请求、在途载荷和前缀短窗启动；每 IP 五次/12h 不变，前缀不共用这个低额度。阈值、真实来源及反代信任边界见 [HARDENING.md](HARDENING.md)。
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
  -subj /CN=localhost -addext 'subjectAltName=DNS:localhost,IP:127.0.0.1,IP:::1' \
  -keyout tls/server.key -out tls/server.crt
chmod 600 tls/server.key
# 仅 rootful Docker：入口容器的 root 没有 DAC_OVERRIDE，私钥也须归 root。
# rootless Docker/Podman 则保留当前用户所有权，不执行此行。
sudo chown 0:0 tls/server.key
docker compose config -q
docker compose build --pull
docker compose up -d
curl --cacert tls/server.crt https://127.0.0.1:8443/health.php
```

访问 `https://127.0.0.1:8443/`。上述自签证书只用于本机验证，浏览器默认不信任；证书信任与公网发布条件需要另行解决，不要关闭正式客户端的 TLS 校验。

默认只监听回环：入口 HTTP 8080、HTTPS 8443、Apache PROXY 后端 18080、PHP-FPM 19000、Redis 16379。启动前确认端口未占用。公开副本已去掉私有地址、校园认证脚本和生产运维记录，**不能直接覆盖现有生产部署**。

PHP、Redis、入口基础均固定 digest；PHP 拒绝旧/未验证分支，扩展 6.1.0 校验固定下载摘要。未使用的 PHP 上传进度已关闭，Redis 健康超时返回 503。构建会从签名 APK 仓库安装更新，因此仍不保证字节复现。正式部署需记录产出镜像 ID 与新鲜扫描结果，不能把历史镜像的安全结论套用到新构建。

## 开放给其他设备之前

1. 审核网络边界和访问范围，只开放测速入口，不开放 Redis、FPM、SSH 或虚拟化管理端口。
2. 审核 `deploy/haproxy.cfg` 的入口监听/Host/跳转与 Apache 来源/Host 白名单；后端 18080 继续仅回环并强制 PROXY，不直接暴露。重新构建并验证原始 IPv4/IPv6 来源，不单独改一个 `Require` 就上线。
3. 配置匹配入口的证书、HTTP→HTTPS 跳转及续期；TLS 在本机受控入口终止，不经过 CDN 缓存或其他代理出口。不要绕过入口暴露明文 Apache；Secure Cookie 依赖这条受控 TLS/PROXY 链。
4. 按机器用途配置日志。容器使用 journald，应用日志仅含加盐来源摘要；`deploy/journald-speedtest.conf` 提供 64MB / 七天模板，容量满会更早轮转。仅在独立 VM 审核后装入 `/etc/systemd/journald.conf.d/` 并重启 journald，此限制作用于整个 VM。页面中的保留声明必须与实际配置一致。
5. 先独立验证入口、限流、取消释放、重启恢复和真实客户端，再进行经过授权、带停止阈值的性能测试。不要在共享网络直接跑无界压测。

Redis ACL 默认无密码，仅回环、专用实例、有限命令和键空间；仍允许业务必需的 Lua。它不是共享主机上的安全隔离，也不修复 Redis 自身漏洞。Redis 不可用时拒绝高流量准入；Ping 仍轻量可用。

## 验证与现状

```bash
bash tests/offline-check.sh
npm run check
```

离线检查涵盖浏览器辅助契约、CLI 安全前置、仪表盘尺寸、上游 SHA256、公开文件边界和独立 Redis Lua 的二十客户端竞争。安装 PHP 时也检查语法和来源分桶。仓库没有 Meson，不引入占位 Meson 测试。

每次 push/PR 自动执行静态检查、ShellCheck、actionlint、必需的 PHP 检查、官方基础镜像构建、Compose 启动、真实 Chromium Worker/取消/限额、Redis 超时错误处理及只读截图。Actions 使用只读仓库权限与固定 Action 提交，不接触生产环境。[贡献指南](CONTRIBUTING.md) 说明完整命令和通过/跳过的区别。

`tests/browser-smoke.cjs` 必须显式传 `--allow-quota-consumption`；它会消耗流量和次数，不应默认用于生产监控。`npm ci` 安装固定的 Playwright 开发依赖；网站运行本身不需要 Node、npm 或前端打包器。

历史校园测试、当前 CI 与这份公开配置的生产验收分开记录。剩余门槛见 [路线图](ROADMAP.md) 与 [安全策略](SECURITY.md)，不要据此宣称任意线路能达到 900–950Mbps、共享网络不受影响或全站无漏洞。旧 v0.1 预发布保留用于溯源，不作为安全部署版本。

## 源码与许可

上游基线：`bg6cq/speedtest@57e2c9af911d8c392b16b6167249444b7b1107ed`。测速引擎 `speedtest.js` 与 `speedtest_worker.js` 保持原样，摘要见 `deploy/UPSTREAM_CORE.sha256`。`upstream/` 保留上游源码结构、README、许可证及版权信息；本项目的修改也按 LGPL-3.0 发布。修改范围与排除项见 [来源说明](UPSTREAM.md)。
