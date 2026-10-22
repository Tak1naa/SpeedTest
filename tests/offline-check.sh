#!/usr/bin/env bash
# No browser, container, SSH, live requests or real session quota.
set -euo pipefail
cd "$(dirname "$0")/.."
unset PYTHONOPTIMIZE
export PYTHONDONTWRITEBYTECODE=1
node tests/browser-admission-contract.cjs
node tests/browser-cleanup-contract.cjs
node tests/browser-stop-contract.cjs
node tests/browser-run-guard-contract.cjs
node tests/gauge-size-contract.cjs
sha256sum -c deploy/UPSTREAM_CORE.sha256
python3 tests/repository-contract.py
if command -v redis-server >/dev/null && command -v redis-cli >/dev/null; then
  python3 tests/redis-contract.py
else
  echo 'SKIP: real Redis Lua contracts (Redis not installed)'
fi
if command -v php >/dev/null; then
  while IFS= read -r -d '' file; do php -l "$file" >/dev/null; done < <(find upstream tests -name '*.php' -print0)
  php tests/ip-contract.php
else
  echo 'SKIP: PHP syntax/IP contracts (PHP not installed)'
fi
echo 'PASS: available offline checks; not an end-to-end or load acceptance'
