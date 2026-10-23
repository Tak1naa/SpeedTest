"""Only called AFTER disposable container identity/ownership checks.

No I/O on import. At most 17 idle loopback sockets and 650 sequential lightweight
requests; no external clients, production Redis or unbounded stress testing.
"""
import http.client
import json
import socket
import ssl
import time


def run_fault_checks(cafile, control, redis, https_port=8443):
    """The caller must verify the disposable Redis identity before control()."""
    context = ssl.create_default_context(cafile=str(cafile))

    def request(path, expected, method='GET', headers=None, body=None):
        client = http.client.HTTPSConnection('127.0.0.1', https_port, context=context, timeout=5)
        try:
            client.request(method, path, headers=headers or {}, body=body)
            response = client.getresponse(); response.read()
            assert response.status == expected, (path.split('?')[0], response.status, expected)
        finally:
            client.close()

    quota_keys = ['speedtest:' + name + ':127.0.0.1/32' for name in ('recent', 'rolling12h', 'prefix5m', 'prefix1h')]
    quota_keys.append('speedtest:starts5m')
    assert redis('EXISTS', 'speedtest:global') == '0'
    before = [redis('ZCARD', key) for key in quota_keys]
    control('pause')
    try:
        request('/health.php', 503)
        request('/backend/empty.php', 200)
        request('/backend/getIP.php', 503, headers={'X-Speedtest-Action': 'start'})
        synthetic = {'Cookie': 'ST_SESSION=' + 'A' * 43}
        request('/backend/garbage.php?ckSize=1', 503, headers=synthetic)
        request('/backend/empty.php', 503, method='POST', headers=synthetic, body=b'fixture')
    finally:
        control('unpause')
    request('/health.php', 200)
    assert redis('EXISTS', 'speedtest:global') == '0', 'Late queued admission created a ghost lock'
    assert [redis('ZCARD', key) for key in quota_keys] == before, 'Failed admission charged quota after recovery'
    return {'redisFaultFailClosed': 'passed', 'pingDuringRedisFault': 'passed', 'redisRecovery': 'passed', 'noLateAdmissionOrQuotaCharge': 'passed'}


def run_checks(cafile, redis, https_port=8443, http_port=8080, backend_port=18080):
    context = ssl.create_default_context(cafile=str(cafile))

    def request(path, expected, host='::1', method='GET', headers=None, body=None):
        client = http.client.HTTPSConnection(host, https_port, context=context, timeout=5)
        try:
            client.request(method, path, body=body, headers=headers or {})
            response = client.getresponse()
            data = response.read()
            assert response.status == expected, (path.split('?')[0], response.status, expected)
            return response, data
        finally:
            client.close()

    # No TLS/authentication bypass: IPv6 original source survives PROXY v2,
    # whereas supplied forwarding/protocol headers cannot change it.
    request('/backend/getIP.php', 403)
    assert redis('EXISTS', 'speedtest:global') == '0'
    response, body = request('/backend/getIP.php', 200, headers={
        'X-Speedtest-Action': 'start',
        'X-Forwarded-For': '198.51.100.9', 'X-Real-IP': '198.51.100.9',
        'Forwarded': 'for=198.51.100.9;proto=http', 'X-Forwarded-Proto': 'http',
    })
    assert json.loads(body)['processedString'].startswith('::1'), 'Original IPv6 source lost'
    cookie = response.getheader('Set-Cookie')
    assert all(flag in cookie.lower() for flag in ('secure', 'httponly', 'samesite=lax'))
    cookie = cookie.split(';', 1)[0]
    token = cookie.split('=', 1)[1]
    assert redis('ZCARD', 'speedtest:inflight') == '0'
    seeded = ['fixture-held-' + str(index) for index in range(12)]
    try:
        for identifier in seeded:
            redis('ZADD', 'speedtest:inflight', time.time() + 25, identifier)
        request('/backend/garbage.php?ckSize=1', 429, headers={'Cookie': cookie})
        assert redis('EXISTS', 'speedtest:bytes:' + token + ':download') == '0'
    finally:
        for identifier in seeded:
            redis('ZREM', 'speedtest:inflight', identifier)
    _, payload = request('/backend/garbage.php?ckSize=1', 200, headers={'Cookie': cookie})
    assert len(payload) == 1048576
    for _ in range(50):
        if redis('ZCARD', 'speedtest:inflight') == '0':
            break
        time.sleep(.05)
    assert redis('ZCARD', 'speedtest:inflight') == '0', 'Shutdown must release request lease'
    request('/backend/getIP.php', 403, host='127.0.0.1', method='POST', headers={
        'Cookie': cookie, 'X-Speedtest-Action': 'finish',
    }, body=b'')
    request('/backend/getIP.php', 204, method='POST', headers={
        'Cookie': cookie, 'X-Speedtest-Action': 'finish',
    }, body=b'')
    assert redis('EXISTS', 'speedtest:global') == '0'

    cleartext = http.client.HTTPConnection('127.0.0.1', http_port, timeout=5)
    try:
        cleartext.request('GET', '/?fixture=1')
        response = cleartext.getresponse(); response.read()
        assert response.status == 307
        assert response.getheader('Location') == f'https://127.0.0.1:{https_port}/?fixture=1'
    finally:
        cleartext.close()

    with socket.create_connection(('127.0.0.1', backend_port), timeout=3) as direct:
        direct.sendall(b'GET /health.php HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n')
        assert b'200 OK' not in direct.recv(1024), 'Backend must require PROXY, not forwarded headers'

    sockets = []
    try:
        time.sleep(.1)
        for _ in range(16):
            # A completed handshake proves HAProxy has accounted for each
            # connection; raw connect() alone races the accept queue order.
            raw = socket.create_connection(('::1', https_port), timeout=2)
            sockets.append(context.wrap_socket(raw, server_hostname='localhost'))
        with socket.create_connection(('::1', https_port), timeout=2) as excess:
            excess.settimeout(3)
            try:
                assert excess.recv(1) == b'', '17th per-source connection must be rejected before TLS'
            except ConnectionResetError:
                pass  # A deliberate pre-TLS reject may be FIN or RST.
    finally:
        for connection in sockets:
            connection.close()
    time.sleep(.1)  # Allow the local event loop to retire closed connection counters.

    # Reuse one connection; this verifies request limits, not connection-rate limits.
    client = http.client.HTTPSConnection('::1', https_port, context=context, timeout=5)
    throttled = False
    try:
        for count in range(650):
            client.request('GET', '/backend/empty.php')
            response = client.getresponse(); response.read()
            if response.status == 429:
                assert response.getheader('Retry-After') == '10'
                throttled = True
                break
            assert response.status == 200
    finally:
        client.close()
    assert throttled, 'Keep-alive requests must not bypass edge rate control'
    return {'originalIPv6AndForwardingHeaders': 'passed', 'secureCookie': 'passed',
            'payloadSlotRejectionAndShutdownRelease': 'passed', 'wrongOwnerFinish': 'passed',
            'HTTPRedirectAndBackendProxyBoundary': 'passed', 'connectionLimit': 'passed',
            'keepAliveRateLimit': 'passed', 'isPerformanceEvidence': False}


if __name__ == '__main__':
    raise SystemExit('Use the ownership-verified integration-http.py --allow-disposable-compose entrypoint')
