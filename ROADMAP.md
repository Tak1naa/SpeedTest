# Public maintenance roadmap / 公开维护路线图

The issue tracker is the source of truth. These are open acceptance gates, not promises that v0.2.0 already passes every production condition. Contributions and sanitized compatibility reports are welcome in Chinese or English; no completion dates or service SLA are promised.

Current hardening milestone: [v0.2.0 — public deployment hardening](https://github.com/Tak1naa/SpeedTest/milestone/1). Outstanding real-environment work remains open after the source release.

[v0.2.0 baseline](HARDENING.md) addresses parts of #1/#6/#7 with public CI and native fixture evidence. Keep issues open when broader deployment, fairness or real-client criteria remain; implementation is not completion of every criterion.

## Security and correctness first

- [IPv6 prefix budgets and request-level abuse limits — #7](https://github.com/Tak1naa/SpeedTest/issues/7): address rotation, shared-prefix false positives, request/connection work and bounded Redis growth.
- [Installation, TLS and management isolation — #2](https://github.com/Tak1naa/SpeedTest/issues/2): dedicated VM/host-network review, certificate lifecycle, safe configuration and egress boundaries.
- [Fresh image scans, SBOM and rebuild verification — #1](https://github.com/Tak1naa/SpeedTest/issues/1): exact image identity, scanner freshness and manually compiled component coverage.
- [Malformed-input/cancellation/error matrix — #6](https://github.com/Tak1naa/SpeedTest/issues/6): deliberate status codes, fail-closed behavior and reliable owner cleanup.

## Compatibility and evidence

- [Browser/platform compatibility matrix — #3](https://github.com/Tak1naa/SpeedTest/issues/3): real devices vs emulation, Safari/mobile behavior, amd64/arm64 and accessibility.
- [Representative gigabit/recovery validation — #4](https://github.com/Tak1naa/SpeedTest/issues/4): wired clients, shared-network impact, restart and upstream-authentication recovery where applicable.

CI covers a Linux/Chromium loopback baseline, not every device, external network, load condition or failure. Do not close an issue because a health page or narrow mock passed. Link the evidence, commands, limits and outstanding checks in the issue.

## Kept deliberately small

Continue reusing LibreSpeed/bg6cq measurement code and existing endpoints. A frontend rewrite, new measurement backend, multi-node control plane, telemetry database, unattended network installer and default public listener are not part of this milestone.

Vulnerabilities belong in [private security reports](SECURITY.md), not public roadmap reproductions.
