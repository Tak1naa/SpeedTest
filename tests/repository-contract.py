"""Public-source integrity and accidental-publication guard; no live requests."""
import hashlib
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
version = (ROOT / 'VERSION').read_text().strip()
assert re.fullmatch(r'\d+\.\d+\.\d+', version)
assert json.loads((ROOT / 'package.json').read_text())['version'] == version
assert (ROOT / 'LICENSE').read_bytes() == (ROOT / 'upstream/LICENSE').read_bytes()
pin = 'php:8.3.36-fpm-alpine@sha256:aaf1ad25fc6484613be770743d1038e98e3e7e07409d78b97410eccdf3628f75'
for name in ('compose.yaml', '.env.example', 'upstream/Dockerfile.alpine-candidate'):
    assert pin in (ROOT / name).read_text(), 'PHP base differs across build entry points'
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
docs = ['README.md', 'README.en.md', 'CONTRIBUTING.md', 'SECURITY.md', 'ROADMAP.md', 'CHANGELOG.md', 'docs/screenshots/README.md']
for name in docs:
    text = (ROOT / name).read_text()
    for link in re.findall(r'!?\[[^\]]*\]\(([^)]+)\)', text):
        if '://' in link or link.startswith(('#', 'mailto:')): continue
        target = link.split('#', 1)[0]
        assert not target or (ROOT / name).parent.joinpath(target).is_file(), f'Broken local documentation link in {name}'
print('PASS: version/license/base pins, unchanged engine, public placeholder, publication patterns and documentation links; not a complete secret/security audit')
