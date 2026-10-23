# Unreleased hardening candidate / 未发布安全修复候选

这些变更位于 v0.1.0 之后的修复候选分支，不是已发布版本，也不表示已替换维护者的生产服务。没有修改 LibreSpeed 测速引擎、Worker 或既有 URL。

## 请求路径与信任边界

客户端 → HAProxy 8080/8443 → Apache 127.0.0.1:18080（PROXY v2）→ FPM 127.0.0.1:19000；Redis 为 127.0.0.1:16379。公开默认所有监听均为回环，适用于专用 Linux VM。

HAProxy 终止 TLS 并限制新连接、请求和连接数；没有给测速内容加带宽限速或 CDN 缓存。Apache 后端必须接收 PROXY 协议，从而保留 IPv4/IPv6 实际连接来源；不信任 X-Forwarded-For、Forwarded 或 X-Real-IP。后端没有直接公网监听，不得单独暴露 18080 或启用公网 accept-proxy。HAProxy 只转发 TLS 请求和本机 GET 健康检查，Apache 设置 HTTPS 环境以保持 Secure Cookie。

host network 仍不是隔离不可信 VM 共用者的边界。同 VM 的受陷进程可能访问回环后端与 Redis，故仍需专用 VM、权限边界及管理网出站隔离。新增入口层也不解决上游线路被大流量打满的问题。

入口只保留SETUID/SETGID，不靠DAC_OVERRIDE读取其他用户的0600文件。rootful Docker的TLS私钥必须为root所有、0600；rootless容器则由映射到容器root的主机用户持有。生成证书时应按运行模式设置所有权，不能通过改成0644或恢复广泛capabilities绕过权限。

## 候选阈值

- 入口：最多 64 个连接/SSL连接、两线程；每地址最多 16 连接、每前缀最多 32 连接。IPv4 前缀为 /32，IPv6 为 /64。
- 新连接：全站每秒接收最多 80；每地址十秒内连接速率超过 80 时提前拒绝，可能表现为连接关闭而非 HTTP 状态码。
- 请求：每地址十秒 600、每前缀十秒 1200、全站十秒 1200，返回 429 和 Retry-After。HAProxy stick-table 为有界、近似短窗速率，不是精确的每用户历史计数。
- 原有次数：每实际 IP 五分钟两次、滚动十二小时五次；一个全站活动会话，不恢复全站日流量额度。
- 更宽松的聚合启动限制：每前缀五分钟 20 次、每小时 120 次；全站五分钟 30 次。只计成功启动，采用原子滑动窗口。前缀与完整地址分别建桶，不能把整个校园 /64 限成五次/12h。
- 在途载荷：全站最多 12、同会话下载最多 8、上传最多 4；保留原版正常六下载/三上传所需空间。PHP shutdown 释放请求自己的槽位，异常由 25 秒租约回收。租约必须大于 FPM 的 20 秒终止时间。
- 会话结束不删除仍可能阻塞的请求槽位，避免取消后新旧会话挤满 FPM；控制请求仍共享后端资源，不承诺攻击下永远可用。

这些是候选保护参数，不是承诺的性能成绩或普适校园配额。生产采用前需评估共享前缀人数与正常峰值；若调整 FPM 超时、线程、请求槽位或入口端口，应一并复查契约和文档。

## 兼容性与剩余风险

页面的 GET 准入增加 `X-Speedtest-Action: start`，POST 结束继续用 `finish`。没有来源元数据的导航不能直接消耗额度；原始 HTTP 客户端仍可伪造请求头，所以这不是身份认证。其他调用者需同步增加准入头，已有 Worker 文件不变。

每方向 2.5GB、单请求 100MiB/32MiB 与阶段期限保留；上传在读取后再次检查阶段。阻塞 I/O 不会因 PHP 轮询立即中断，仍靠前端/FPM 的最终超时限制；不宣称严格在 12/14 秒停止。IP/前缀限制无法彻底解决地址轮换、共享 NAT、公平排队或分布式攻击。

Redis 的客户端读超时不会取消已排队命令。准入及载荷预约新增第12个参数：派发后0.4秒的执行期限，小于0.5秒读超时；Lua在任何写入前以Redis `TIME` 校验，过期返回保护服务不可用，不迟到创建锁、扣次或预约。ACL仅额外允许只读TIME命令，PHP/Lua/ACL必须一起更新。Redis与PHP应在同一VM时钟域。此机制修复已复现的暂停恢复后迟到执行，**不保证任意网络故障下写入与HTTP回复具有恰好一次语义**；执行已提交但回复丢失仍有不确定性，现有会话TTL与次数语义继续保留。参见 [Redis脚本时钟与复制说明](https://redis.io/docs/latest/develop/programmability/eval-intro/)。

## 构建与扫描

PHP、Redis 和 HAProxy 基础固定 digest；PECL 6.1.0 下载包增加固定 SHA256 检查，Redis 更新整个签名 APK 运行包集合，避免遗漏 musl。签名包仓库仍会变化，因此最终输出镜像不保证字节复现。

CI 新增新鲜、精确镜像身份的 Trivy 门禁及 CycloneDX 清单；HIGH/CRITICAL，及已有修复但尚未评级的 UNKNOWN 记录均阻止通过。数据库过期、下载失败、库存缺失或版本身份漂移也失败，不使用 ignore-unfixed。

```bash
python3 scripts/scan-images.py --allow-local-image-scan
```

该命令只读取三个固定公开构建镜像，并无网络启动版本 CLI；不检查生产容器。输出 `.security/` 必须不存在，防止覆盖证据。系统包清单不等于完整手工组件 SBOM；脚本额外检查 PHP/PECL、Redis、HAProxy 的已审查版本下限，未来公告和组件兼容仍需人工复核。

## 验证

```bash
npm test
npm run check
# 仅全新、标记为 speedtest-ci 的独立 Compose 夹具：
python3 tests/integration-http.py --allow-disposable-compose
```

集成检查验证 TLS、实际 IPv6 来源、伪造转发头、Secure Cookie、请求槽位释放与错误所有者、HTTP跳转、后端PROXY边界、连接数及 keep-alive 请求速率。它使用少量局部连接和顺序小请求，不是生产压测或吞吐验收。浏览器测试仍使用未改的 Worker，限速回环的数值不得当作线路成绩。

上线前仍需独立维护授权、真实外部来源及吞吐/故障/共享网络影响验证。不能用公开回环配置直接覆盖现有生产配置，不能因为候选测试通过就关闭全部公开路线图 Issues。
