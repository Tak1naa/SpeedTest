# Security audit — 2026-10-30

Scope: SpeedTest application source, pinned container/runtime inputs, admission and payload accounting, the HTTP/TLS/proxy trust boundary, publication hygiene, and bounded disposable tests. This report is not an audit of every hypervisor/router package, other applications, historical intrusion or the entire campus network.

## Verified source controls

- Unchanged LibreSpeed engine and Worker SHA256; no telemetry, results database, ISP lookup, persistent uploads or personal donation asset in the public tree.
- Explicit same-origin start/finish handling, random short-lived Secure/HttpOnly/SameSite cookies, exact source binding, owner-only release and fail-closed Redis errors.
- Per-address rolling quotas, independent coarse prefix/global start windows, atomic in-flight request leases and a reserved PHP-worker margin.
- Stale Redis scripts check server TIME before writes. Actual pause/recovery tests verify that failed admission does not later create a lock or charge quota.
- The edge removes caller forwarding headers; only a loopback backend accepts PROXY v2. Native tests check original IPv6, forged headers, direct-backend rejection, TLS, connection caps, keep-alive request throttling and slot release.
- Read-only container roots, finite memory/PID limits, minimal edge capabilities, bounded PHP body parsing and tmpfs temporary storage. Rootful TLS key ownership is enforced without making keys world-readable.
- `/speedtest/` preserves relative endpoint URLs, protects internal files and narrows Cookie Path. Root endpoints remain compatible.

## Executed evidence

- `npm test` and `npm run check` pass on the available local tools; local PHP, ShellCheck and actionlint absence is explicit. CI requires their relevant counterparts rather than treating local skips as passes.
- GitHub Actions performs native Docker builds, exact-image Trivy/freshness/inventory checks, a real Chromium Worker, cancellation/quotas, fault and error contracts, and sanitized screenshots. Evidence is tied to each run/commit; an older green run is not acceptance of a newer commit.
- Independent bounded native Docker and shared-domain adapter fixtures passed without changing the running services. Fixtures use independent RAM Redis; co-hosted upstream behavior is tested with an inert local stub, not by sending failure/load traffic to another application.
- npm's official registry audit reported zero known development-dependency advisories; Trivy source-secret scanning reported zero matches. Neither proves that all secrets or vulnerabilities are absent.
- A current CI scan inventoried Web/Redis/edge OS packages and reviewed PHP/PECL/Redis/HAProxy version floors without blocking findings. This does not cover every manually compiled component or an arbitrary rebuilt/deployed image. Exact production image scans and deployment acceptance are tracked separately in private operations evidence.

## Residual risks / unverified conditions

- Redis's upstream [RESTORE advisory](https://github.com/redis/redis/security/advisories/GHSA-c8h9-259x-jff4) lists all versions affected and no fixed version at this audit. The dedicated ACL denies RESTORE/RESTORE-ASKING; actual ACL DRYRUN checks verify denial without executing payloads. This is a mitigation, not a binary patch or zero-CVE claim. The [replica Lua advisory](https://github.com/redis/redis/security/advisories/GHSA-8ghh-qpmp-7826) requires a writable replica; the checked deployment is master with replica-read-only enabled and cannot change topology via the business ACL. EVAL remains necessary for this application. These prerequisites must be rechecked when deployments change.
- A legitimate test may saturate the uplink. Gigabit wired saturation and simultaneous household-user impact remain unverified for lack of a representative client; Wi-Fi or loopback results cannot close this gate.
- Prefix windows are abuse controls, not per-person fairness. Shared NAT and large shared IPv6 prefixes can create false positives, and distributed clients can evade address-based attribution. Real population tuning remains open.
- PHP polling does not instantly cancel a blocked read/write. Cooperative phase deadlines remain 12/14 seconds; FPM has a 20-second request timeout and leases expire later. Strict 12/14-second hard interruption is not certified.
- Redis dispatch deadlines do not solve an already-committed write whose HTTP reply is lost. Quotas are volatile across Redis restarts.
- host-network containers and loopback services share the VM trust domain. A compromised co-tenant or same-origin application is not isolated by a different URL or Cookie Path. Prefer dedicated origins/VMs for untrusted workloads; do not treat this release as a host-isolation redesign.
- Link-level volumetric floods, all router/hypervisor CVEs, Safari/arm64 coverage, real certificate renewal at a future expiry, broad restart/failure conditions and historical compromise are outside this acceptance.

## Reproduction

```sh
npm test
npm run check
# Explicit disposable CI Compose stack only, never the public live service:
python3 tests/integration-http.py --allow-disposable-compose
python3 scripts/scan-images.py --allow-local-image-scan
```

Scanning requires a current official database, verified scanner, exact image IDs and nonempty package inventories. Database download failure/expiry is unknown, not zero findings. The manual workflow can export the public database plus SHA256 for an offline audit; verify provenance, checksums and NextUpdate before use. Ordinary pushes do not export this large artifact. Raw logs, credentials and private infrastructure details must not be published.

References: [PHP security fixes](https://www.php.net/ChangeLog-8.php#8.3.36), [Apache security advisories](https://httpd.apache.org/security/vulnerabilities_24.html), [trusted PROXY handling](https://httpd.apache.org/docs/2.4/mod/mod_remoteip.html), [Redis advisories](https://github.com/redis/redis/security/advisories), [HAProxy management](https://www.haproxy.org/download/3.2/doc/management.txt).
