# Security policy

## Reporting

Please use [GitHub private vulnerability reporting](https://github.com/Tak1naa/SpeedTest/security/advisories/new). Reports may be written in Chinese or English. Do not open a public issue with exploit details, credentials, personal payment information, private addresses, raw HARs or session tokens.

Include the affected version/image, configuration prerequisites, expected impact, minimal reproduction in an owned disposable environment, and suggested mitigation if known. Redact secrets. Do not test the maintainer's live service, shared networks or third-party systems without permission. No response-time SLA or bug-bounty program is promised.

## Supported source

Security fixes target the latest source release (currently 0.2.0). Historical v0.1/v0.1.0 tags remain for provenance, not separate maintained security branches. Upgrade rather than deploying an old snapshot.

The default Web build pins PHP 8.3.36 and rejects older/other untested branches. It includes the fixes described in the [PHP 8.3.36 changelog](https://www.php.net/ChangeLog-8.php#8.3.36), disables unused upload-progress tracking, and returns 503 for Redis health timeouts. This is not a claim that every dependency is vulnerability-free or that a previous installation was never compromised.

The v0.2.0 hardening baseline is described in [HARDENING.md](HARDENING.md), with an evidence-bounded [audit](docs/SECURITY_AUDIT_2026-10-30.md). Do not infer that a live installation has these controls from its source version alone.

## Deployment boundary

- Default edge HTTP/HTTPS, Apache PROXY backend, FPM and Redis listeners are loopback-only. Public access requires network/edge Host/TLS and Apache source/Host review. Never expose the plaintext PROXY backend.
- Use a dedicated Linux VM. Host-network containers can reach VM loopback services; Docker is not a strong boundary between this app and everything on that VM.
- Redis is dedicated, nonpersistent, loopback-only, with a limited ACL and no password by default. It is not suitable for untrusted co-tenants or a publicly exposed listener.
- Same-origin metadata and a short-lived HttpOnly cookie are not user authentication. IP quotas are not DDoS protection; IPv6 /128 addresses can rotate, and prefix-level controls can affect shared networks.
- The baseline adds a same-origin start header, wider prefix windows, bounded edge work and payload leases. They reduce known amplification/worker pressure, but do not prevent distributed attacks, eliminate address rotation or establish per-person fairness. A prefix does not share the five-per-12h address quota.
- Cookie Path narrows delivery only. Another compromised same-origin application can still issue same-origin requests. Co-hosting also shares TLS, connection and host-network resource boundaries; a different URL path is not a security sandbox.
- A valid test can fill the link. Request floods and TLS work are not bounded solely by the one-active-test lock.
- Self-signed evaluation certificates are not publicly trusted. Do not train users to ignore arbitrary certificate warnings. Public service requires appropriate server identity verification and renewal.
- Keep telemetry disabled unless you deliberately redesign and document privacy/storage behavior. Limit logs and temporary storage; audit actual retention, not just the sample file.

Track unresolved hardening work in [ROADMAP.md](ROADMAP.md). The candidate image gate checks fresh image identity/inventory and reviewed runtime floors, not every manual component vulnerability. Broader compatibility, prefix fairness and representative load/recovery evidence remain open work. Checks and source releases are not production-security certification.
