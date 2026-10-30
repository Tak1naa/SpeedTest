# v0.2.0 — bounded requests and subpath support

2026-10-30 · Source release / 源码正式版

继续复用 `bg6cq/speedtest@57e2c9af911d8c392b16b6167249444b7b1107ed`；不重写测速引擎或 Worker，不加入结果数据库、全站日额度或个人收款码。

## Changes

- HAProxy connection/request limits and trusted PROXY v2 to a loopback Apache/FPM backend.
- Atomic payload slots with owner-only release and expiry; a 12-request payload ceiling reserves four of the 16 PHP workers for control work.
- Existing per-address 2/5min and 5/12h quotas retained. Wider IPv6-prefix/global short windows reduce address-rotation abuse without applying the five-test quota to an entire /64.
- Redis TIME/deadline checks reject stale queued Lua operations before writes. This fixes the reproduced delayed-admission/ghost-quota failure, not arbitrary exactly-once delivery.
- Optional `/speedtest/` routes and path-scoped session cookies, without changing upstream relative URLs.
- Fixed image/extension inputs, fresh exact-image scan and inventory checks, CI browser/failure tests, and opt-in checksummed database export for offline deployment audits.

## Upgrade notes

1. Review and rebuild all three images. Do not overlay the public loopback Compose directly on a private production deployment.
2. Update the page, PHP, Lua scripts and Redis ACL together. Custom callers must send `X-Speedtest-Action: start`; the unchanged upstream Worker is still driven by the page.
3. Redis restart clears quotas/sessions because persistence remains disabled. Schedule a maintenance window and preserve a rollback checkpoint.
4. Recheck actual client addresses, TLS identity/renewal, old routes and any co-hosted application. Never expose the plaintext PROXY backend or trust arbitrary forwarding headers.

## Evidence and limits

See [the security audit](docs/SECURITY_AUDIT_2026-10-30.md) for scope, commands, passed checks and residual risk. CI includes native Docker and a real Chromium Worker; loopback throttling is functional evidence only.

Still unverified: representative wired-gigabit saturation and shared-network impact, broad real-device compatibility, production-scale shared-prefix fairness, and exact hard interruption at 12/14 seconds under blocked I/O. FPM timeout/lease bounds are not a substitute for those claims. A valid single test can still saturate its link. No claim of “zero vulnerabilities,” DDoS resistance or production-security certification is made.

Public source and release assets contain a nonpayable donation placeholder, not the maintainer's payment image, private CA, keys, cookies, campus authentication or private deployment configuration.
