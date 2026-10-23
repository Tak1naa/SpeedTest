# Contributing

欢迎用中文或英文报告问题、补充测试和提交小范围改进。Keep changes narrow, reproducible and honest about what was tested.

## Development setup

- Linux is required for the supplied host-network Compose configuration. Prefer a dedicated disposable VM, never a hypervisor host.
- Node.js 24+, Python 3, Redis server/CLI and PHP 8.3.36+ in the 8.3 branch cover local checks. Docker Engine, Compose and OpenSSL cover container tests.
- `npm ci --ignore-scripts` installs the pinned browser development dependency. The website itself does not require Node, npm or a frontend bundler.
- This is a PHP/JavaScript project, not a Meson project. There is no Meson build or placeholder Meson test suite.

```bash
npm ci --ignore-scripts
npm test
npm run check
```

The offline suite verifies the upstream hashes, publication boundaries, JavaScript contracts, real Lua admission races on a private Unix-socket Redis, and PHP syntax/IP handling when PHP is installed. Missing local tools are reported as SKIP. CI additionally requires ShellCheck/actionlint and runs PHP lint in the pinned official runtime; an offline pass is not end-to-end acceptance.

## Build and run

Follow [README](README.en.md#local-evaluation) to create a private `.env` and disposable TLS pair, then:

```bash
docker compose config -q
docker compose build --pull
docker compose up -d --wait
curl --cacert tls/server.crt https://127.0.0.1:8443/health.php
docker compose down
```

Keep the loopback defaults. Do not put test containers on a shared/production host or automatically change routes, firewall rules, certificate trust or system services. Record base digest and output image IDs; APK updates mean a rebuild need not be byte-identical.

## Browser and integration checks

Browser tests create real sessions and transfer data. Use only a fresh disposable stack. Never clear production Redis or rotate source addresses to get more quota.

```bash
npx playwright install chromium --only-shell
SPEEDTEST_URL=https://127.0.0.1:8443 TEST_PRIVATE_CA=1 \
  TEST_CANCEL=1 TEST_CANCEL_PHASE=download TEST_QUOTAS=1 ISOLATED_THROTTLE_KIB=512 \
  node tests/browser-smoke.cjs --allow-quota-consumption
```

`TEST_PRIVATE_CA=1` is an explicit test-only browser exception for the disposable certificate. Use strict CA validation for command-line HTTP checks and real deployments. Throttled loopback results are not bandwidth claims.

`TEST_CANCEL_PHASE=download` waits for an accepted download stream before cancelling; it is restricted to loopback without a relay. The default admission-only cancellation must not be reported as active-download coverage. Container ownership checks require Python assertions; optimized Python is refused before access.

The Actions workflow uses project name `speedtest-ci` and a documented dummy salt for `tests/integration-http.py --allow-disposable-compose`. That test briefly pauses **only its verified disposable Redis**, checks 503/Ping behavior, and unpauses it in `finally`. Do not run it against your installation. The workflow is the canonical sequence; each job gets a fresh runner.

The hardening candidate also checks PROXY/TLS/original IPv6, source and connection/request limits, payload lease cleanup and wrong-owner finish on that owned fixture. It uses at most 17 local connections and 650 small sequential Ping requests, not a production pressure test. `admission-error-ui.cjs --allow-disposable-ui` mocks backend errors at desktop/compact widths without consuming real quota. See [HARDENING.md](HARDENING.md).

CI now includes `scripts/scan-images.py --allow-local-image-scan` for its three fixed local build images. A missing/expired DB, incomplete inventory or blocking vulnerability fails rather than being silently skipped. An optional existing `--cache-directory` still undergoes freshness/identity validation. `.security/` is generated evidence; never upload raw image configuration or credentials.

For read-only screenshots, run `npm run screenshots` against the loopback preview. It blocks all backend/foreign-origin requests, refuses existing output files and saves the public placeholder, not a payment code. See [screenshot notes](docs/screenshots/README.md).

## Changes and reports

1. Open an issue describing the problem and expected behavior. Use the compatibility form for platform results; distinguish real devices from emulation.
2. Fork/create a branch. Keep unrelated formatting and generated files out of the diff.
3. Add a regression test and run the applicable checks. Report skipped/blocked tests, not just passes.
4. Open a PR with scope, test commands, results and remaining risks. Link issues with `Refs #…` unless every acceptance criterion is actually complete.

Preserve `upstream/speedtest.js` and `upstream/speedtest_worker.js`, their fixed baseline, license and SHA256 checks. Algorithm changes need explicit discussion; do not refresh hashes merely to silence failures. Retained upstream examples are not the supported deployment entry point.

Do not commit `.env`, private keys/certificates, raw HAR/logs, session cookies, personal QR codes, private topology or production image archives. The repository guard detects selected patterns; it is not an exhaustive secret scanner. Review screenshots visually before publishing.

Use [SECURITY.md](SECURITY.md) for vulnerabilities. Do not publish exploit details, credentials or unsanitized captures in a public issue. Contributions are distributed under the existing LGPL-3.0 license; preserve authorship notices.
