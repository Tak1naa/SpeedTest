#!/usr/bin/env bash
# No browser, container, SSH, live requests or real session quota.
set -euo pipefail
cd "$(dirname "$0")/.."
node tests/browser-admission-contract.cjs
node tests/browser-cleanup-contract.cjs
node tests/browser-stop-contract.cjs
node tests/browser-run-guard-contract.cjs
node tests/gauge-size-contract.cjs
sha256sum -c deploy/UPSTREAM_CORE.sha256
if command -v php >/dev/null; then
  while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find upstream tests -name '*.php' -print0)
  php tests/ip-contract.php
else
  echo 'SKIP: PHP syntax/IP contracts (PHP not installed)'
fi
echo 'PASS: available offline checks; not an end-to-end or load acceptance'
