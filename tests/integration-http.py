"""Low-traffic HTTP/error checks on an explicitly disposable CI Compose stack."""
import argparse
import http.client
import json
from pathlib import Path
import ssl
import subprocess
from hardening_checks import run_checks, run_fault_checks


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--allow-disposable-compose', action='store_true')
    args = p.parse_args()
    if not args.allow_disposable_compose: p.error('Explicit --allow-disposable-compose required')
    if not __debug__: p.error('Assertions required; optimized Python refused before container access')
    root = Path(__file__).resolve().parents[1]
    compose = ['docker', 'compose', '-p', 'speedtest-ci']
    def command(*parts):
        proc = subprocess.run(parts, cwd=root, capture_output=True, text=True, timeout=15)
        assert proc.returncode == 0, 'Disposable stack command failed; raw output withheld'
        return proc.stdout.strip()
    ids = {}
    for role in ('speedtest', 'redis', 'edge'):
        identifier = command(*compose, 'ps', '-q', role)
        info = json.loads(command('docker', 'inspect', identifier))[0]
        assert info['Config']['Labels'].get('com.docker.compose.project') == 'speedtest-ci'
        assert info['Config']['Labels'].get('com.docker.compose.service') == role
        assert info['HostConfig']['ReadonlyRootfs'] and not info['HostConfig']['Privileged']
        ids[role] = identifier
        if role == 'speedtest':
            # Public dummy marker prevents accidentally treating a real stack as a fixture.
            assert 'ST_LOG_SALT=ci-only-not-a-production-secret' in info['Config']['Env']
    assert command(*compose, 'exec', '-T', 'redis', 'redis-cli', '-p', '16379', 'EXISTS', 'speedtest:global') == '0', 'A test is active'
    context = ssl.create_default_context(cafile=str(root / 'tls/server.crt'))
    def request(path, expected, method='GET', headers=None, body=None):
        c = http.client.HTTPSConnection('127.0.0.1', 8443, context=context, timeout=5)
        try:
            c.request(method, path, body=body, headers=headers or {})
            r = c.getresponse(); r.read()
            assert r.status == expected, (path.split('?')[0], r.status, expected)
        finally: c.close()
    request('/health.php', 200)
    request('/speedtest/health.php', 200)
    request('/speedtest', 308)
    request('/speedtest/speedtest.js', 200)
    request('/speedtest/speedtest_worker.js', 200)
    request('/speedtest/coffee-qr.svg', 200)
    request('/speedtest/backend/rate_limit.php', 403)
    request('/speedtest/backend/payload.lua', 403)
    request('/backend/empty.php', 200)
    request('/backend/garbage.php?ckSize=1', 403)
    request('/backend/empty.php', 403, 'POST', body=b'fixture')
    request('/backend/getIP.php', 403, headers={'Sec-Fetch-Site': 'cross-site'})
    request('/backend/rate_limit.php', 403)
    request('/backend/session-start.lua', 403)
    request('/', 403, headers={'Host': 'rejected.example'})
    request('/health.php', 405, 'POST', body=b'')
    def redis(*parts):
        return command(*compose, 'exec', '-T', 'redis', 'redis-cli', '-p', '16379', '--raw', *map(str, parts))
    assert all(value == 'passed' for value in run_fault_checks(root / 'tls/server.crt',
               lambda action: command('docker', action, ids['redis']), redis).values())
    assert command(*compose, 'ps', '-q', 'redis') == ids['redis']
    assert all(value == 'passed' or key == 'isPerformanceEvidence'
               for key, value in run_checks(root / 'tls/server.crt', redis).items())
    print('PASS: strict local TLS, no-token/internal/Host/origin/method rejection, Redis timeout503 and lightweight Ping; disposable CI stack only, no quota reset')


if __name__ == '__main__': main()
