"""Public-source integrity and accidental-publication guard; no live requests."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
version = (ROOT / 'VERSION').read_text().strip()
assert re.fullmatch(r'\d+\.\d+\.\d+', version)
assert json.loads((ROOT / 'package.json').read_text())['version'] == version
assert (ROOT / 'LICENSE').read_bytes() == (ROOT / 'upstream/LICENSE').read_bytes()
pin = 'php:8.3.36-fpm-alpine@sha256:aaf1ad25fc6484613be770743d1038e98e3e7e07409d78b97410eccdf3628f75'
for name in ('compose.yaml', '.env.example', 'upstream/Dockerfile.alpine-candidate'):
    assert pin in (ROOT / name).read_text(), 'PHP base differs across build entry points'
redis_pin = 'redis:7.4-alpine@sha256:57e4255cc971b1c8d6f8a6ca283ac01bc3ecdd914273ef6bfaede87aaecd6541'
for name in ('compose.yaml', '.env.example', 'deploy/Dockerfile.redis'):
    assert redis_pin in (ROOT / name).read_text(), 'Redis base differs across build entry points'
build = (ROOT / 'upstream/Dockerfile.alpine-candidate').read_text()
assert 'f10405f639fe415e9ed4ec99538e72c90694d8dbd62868edcfcd6a453466b48c' in build
assert 'sha256sum -c -' in build
guard = (ROOT / 'upstream/backend/rate_limit.php').read_text()
fpm = (ROOT / 'upstream/docker/alpine-candidate-fpm.conf').read_text()
lease = int(re.search(r'const ST_REQUEST_LEASE = (\d+)', guard)[1])
hard_timeout = int(re.search(r'request_terminate_timeout = (\d+)s', fpm)[1])
slots = int(re.search(r'const ST_REQUESTS_GLOBAL = (\d+)', guard)[1])
pool = int(re.search(r'pm.max_children = (\d+)', fpm)[1])
assert lease > hard_timeout and slots <= pool - 4, 'Lease/worker safety margin broken'
assert 'const ST_REDIS_DISPATCH_WINDOW = 0.4;' in guard
assert '+time' in (ROOT / 'deploy/redis-security-candidate.acl').read_text()
for name in ('session-start.lua', 'payload.lua'):
    script = (ROOT / 'upstream/backend' / name).read_text()
    assert "redis.call('TIME')" in script and 'tonumber(ARGV[12])' in script
apache = (ROOT / 'upstream/docker/alpine-candidate-httpd.conf').read_text()
assert 'Listen 127.0.0.1:18080' in apache and 'RemoteIPProxyProtocol On' in apache
assert 'RemoteIPHeader' not in apache and 'RemoteIPProxyProtocolExceptions' not in apache
edge = (ROOT / 'deploy/haproxy.cfg').read_text()
assert not re.search(r'^\s*bind\s+.*\baccept-proxy\b', edge, re.M)
assert 'send-proxy-v2' in edge and 'http-reuse never' in edge
assert "headers:{'X-Speedtest-Action':'start'}" in (ROOT / 'upstream/docker/standalone.php').read_text()
for line in (ROOT / 'deploy/UPSTREAM_CORE.sha256').read_text().splitlines():
    digest, name = line.split('  ')
    assert hashlib.sha256((ROOT / name).read_bytes()).hexdigest() == digest, 'Upstream engine changed'
assert 'session.upload_progress.enabled=Off' in (ROOT / 'upstream/docker/speedtest.ini').read_text()
assert 'This release requires patched PHP 8.3.36+' in (ROOT / 'upstream/Dockerfile.alpine-candidate').read_text()
assert '无支付功能' in (ROOT / 'upstream/assets/coffee-qr.svg').read_text()
files = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT).decode().split('\0')
for name in filter(None, files):
    path = ROOT / name
    assert not path.is_symlink(), 'Symlinked public source refused'
    assert not any(p in ('tls', '.private-ca', '.private-leaf', 'node_modules') for p in path.relative_to(ROOT).parts), 'Private/runtime file selected'
    assert path.name != '.env' and path.suffix.lower() not in ('.key', '.pem', '.har', '.p12'), 'Secret/runtime file selected'
    assert name not in ('upstream/assets/coffee-qr.jpg', 'upstream/assets/coffee-qr.png'), 'Personal payment asset selected'
    data = path.read_bytes()
    assert not re.search(rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----', data), 'Private key material detected'
    assert not re.search(rb'(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{30,})', data), 'GitHub credential pattern detected'
    assert not re.search(rb'(?:192\.168\.2\.\d+|172\.29\.\d+\.\d+|10\.70\.102\.\d+|2001:da8:205:40[b]1:)', data), 'Private deployment address detected'
docs = ['README.md', 'README.en.md', 'CONTRIBUTING.md', 'SECURITY.md', 'ROADMAP.md', 'CHANGELOG.md', 'HARDENING.md', 'docs/screenshots/README.md']
for name in docs:
    text = (ROOT / name).read_text()
    for link in re.findall(r'!?\[[^\]]*\]\(([^)]+)\)', text):
        if '://' in link or link.startswith(('#', 'mailto:')): continue
        target = link.split('#', 1)[0]
        assert not target or (ROOT / name).parent.joinpath(target).is_file(), f'Broken local documentation link in {name}'
print('PASS: version/license/base pins, unchanged engine, public placeholder, publication patterns and documentation links; not a complete secret/security audit')
for script, flag in (('tests/integration-http.py', '--allow-disposable-compose'),
                     ('scripts/scan-images.py', '--allow-local-image-scan')):
    # An empty PATH prevents even a regressed guard from contacting Docker.
    result = subprocess.run([sys.executable, '-B', '-E', '-O', str(ROOT / script), flag],
                            env={**os.environ, 'PATH': ''}, capture_output=True, text=True, timeout=10)
    assert result.returncode != 0 and 'Assertions required' in result.stderr
print('PASS: optimized Python is refused before container/scan access; subprocess PATH contains no container CLI')
