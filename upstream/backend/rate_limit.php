<?php

/**
 * Minimal single-node guard for the existing LibreSpeed endpoints.
 * The measurement algorithm and public endpoint paths remain upstream-compatible.
 */

const ST_SESSION_TTL = 60;
const ST_RECENT_WINDOW = 300;
const ST_RECENT_LIMIT = 2;
const ST_ROLLING_LIMIT = 5;
const ST_ROLLING_WINDOW = 43200;
// Includes reserved bytes in six parallel upstream download requests.
const ST_DIRECTION_LIMIT = 2500000000;
const ST_REQUEST_LEASE = 25; // Must remain > FPM request_terminate_timeout (20s).
const ST_REQUESTS_GLOBAL = 12; // Leave four of sixteen FPM workers for controls.
const ST_REQUESTS_DOWNLOAD = 8;
const ST_REQUESTS_UPLOAD = 4;
const ST_PREFIX_RECENT_LIMIT = 20;
const ST_PREFIX_HOUR_LIMIT = 120;
const ST_GLOBAL_RECENT_LIMIT = 30;
const ST_REDIS_DISPATCH_WINDOW = 0.4; // Less than the 0.5s Redis read timeout.

date_default_timezone_set('Asia/Shanghai');

/** Browser CSRF defence, not authentication: raw HTTP clients can forge metadata. */
function st_check_request($methods)
{
    header('Cache-Control: no-store');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, $methods, true)) {
        header('Allow: '.implode(', ', $methods));
        st_error(405, '不支持的请求方法');
    }
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if (!in_array($site, array('', 'same-origin', 'none'), true)) {
        st_error(403, '不允许跨站测速请求');
    }
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        $origin = parse_url($_SERVER['HTTP_ORIGIN']);
        $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $local = parse_url($scheme.'://'.($_SERVER['HTTP_HOST'] ?? ''));
        if (!is_array($origin) || !is_array($local) ||
            ($origin['scheme'] ?? '') !== $scheme ||
            strtolower($origin['host'] ?? '') !== strtolower($local['host'] ?? '') ||
            ($origin['port'] ?? ($scheme === 'https' ? 443 : 80)) !== ($local['port'] ?? ($scheme === 'https' ? 443 : 80)) ||
            isset($origin['user']) || isset($origin['pass']) || isset($origin['path']) || isset($origin['query']) || isset($origin['fragment'])) {
            st_error(403, '不允许跨站测速请求');
        }
    }
}

function st_client_ip()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ip = substr($ip, 7);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function st_ip_bucket($ip)
{
    $packed = @inet_pton($ip);
    if ($packed !== false && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return inet_ntop($packed).'/128';
    }
    return $ip.'/32';
}

function st_prefix_bucket($ip)
{
    $packed = @inet_pton($ip);
    if ($packed !== false && strlen($packed) === 16) {
        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }
    return st_ip_bucket($ip);
}

function st_log($event, $extra = array())
{
    // Rejected traffic must not amplify into one journal write per request.
    // Edge counters retain aggregate limits; sampled records contain no tokens.
    if (in_array($event, array('request_rejected', 'rate_limiter_unavailable'), true) && random_int(0, 63) !== 0) return;
    $payload = array_merge(array(
        'event' => $event,
        'ip_key' => substr(hash('sha256', getenv('ST_LOG_SALT').st_ip_bucket(st_client_ip())), 0, 16),
        'time' => gmdate('c'),
    ), $extra);
    error_log(json_encode($payload, JSON_UNESCAPED_SLASHES));
}

function st_redis()
{
    static $redis = null;
    if ($redis instanceof Redis) {
        return $redis;
    }

    try {
        $redis = new Redis();
        $host = getenv('REDIS_HOST') ?: 'redis';
        $port = (int)(getenv('REDIS_PORT') ?: 6379);
        if (!$redis->connect($host, $port, 0.5)) {
            throw new RuntimeException('Redis connection failed');
        }
        $password = getenv('REDIS_PASSWORD');
        $redis->setOption(Redis::OPT_READ_TIMEOUT, 0.5);
        if ($password !== false && $password !== '') {
            $redis->auth($password);
        }
        return $redis;
    } catch (Throwable $exception) {
        st_log('rate_limiter_unavailable');
        http_response_code(503);
        header('Retry-After: 15');
        header('Content-Type: text/plain; charset=utf-8');
        exit('测速保护服务暂时不可用，请稍后重试');
    }
}

function st_error($status, $message, $retryAfter = null)
{
    http_response_code($status);
    if ($retryAfter !== null) {
        header('Retry-After: '.(int)$retryAfter);
    }
    header('Content-Type: text/plain; charset=utf-8');
    st_log('request_rejected', array('status' => $status, 'reason' => $message));
    exit($message);
}

function st_start_session()
{
    // Same-origin fetch sets a non-simple header. Navigation/image requests
    // without Fetch Metadata cannot silently consume quota or take the lock.
    // This is a browser CSRF guard, not authentication for raw HTTP clients.
    if (($_SERVER['HTTP_X_SPEEDTEST_ACTION'] ?? '') !== 'start') {
        st_error(403, '请从本站页面开始测速');
    }
    $bucket = st_ip_bucket(st_client_ip());
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $redis = st_redis();
    $keys = array(
        'speedtest:active:'.$bucket,
        'speedtest:recent:'.$bucket,
        'speedtest:rolling12h:'.$bucket,
        'speedtest:session:'.$token,
    );
    $prefix = st_prefix_bucket(st_client_ip());
    $result = st_eval(file_get_contents(__DIR__.'/session-start.lua'), array_merge($keys, array(
        'speedtest:global', 'speedtest:prefix5m:'.$prefix,
        'speedtest:prefix1h:'.$prefix, 'speedtest:starts5m',
    )), array($token, ST_RECENT_LIMIT, ST_ROLLING_LIMIT, ST_SESSION_TTL,
        ST_RECENT_WINDOW, $bucket, microtime(true), ST_ROLLING_WINDOW,
        ST_PREFIX_RECENT_LIMIT, ST_PREFIX_HOUR_LIMIT, ST_GLOBAL_RECENT_LIMIT,
        microtime(true) + ST_REDIS_DISPATCH_WINDOW));
    if (!is_array($result) || (int)$result[0] !== 1) {
        $reason = is_array($result) && isset($result[1]) ? $result[1] : 'limit';
        if ($reason === 'unavailable') st_error(503, '测速保护服务暂时不可用', 15);
        $retry = $reason === 'active' ? ST_SESSION_TTL : ST_RECENT_WINDOW;
        if (is_array($result) && isset($result[2])) $retry = max(1, (int)ceil($result[2]));
        $message = $reason === 'rolling' ? '此来源滚动12小时内最多测速5次，请稍后重试' : '已有测速会话或5分钟启动次数已达上限，请稍后重试';
        if ($reason === 'prefix' || $reason === 'global') $message = '当前网络或本站短时启动较频繁，请稍后重试';
        st_error(429, $message, $retry);
    }

    setcookie('ST_SESSION', $token, array(
        'expires' => time() + ST_SESSION_TTL,
        'path' => '/',
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ));
    return $token;
}

function st_require_payload($direction, $bytes)
{
    $token = isset($_COOKIE['ST_SESSION']) ? $_COOKIE['ST_SESSION'] : '';
    // PHP parses bracketed Cookie names as arrays; reject before string functions.
    if (!is_string($token) || $token === '' || strlen($token) > 128 || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
        st_error(403, '测速会话无效，请重新开始测速');
    }

    $bucket = st_ip_bucket(st_client_ip());
    $redis = st_redis();
    try {
        $sessionBucket = $redis->get('speedtest:session:'.$token);
        $activeToken = $redis->get('speedtest:active:'.$bucket);
    } catch (Throwable $error) {
        st_error(503, '测速保护服务暂时不可用', 15);
    }
    if ($sessionBucket !== $bucket || $activeToken !== $token) {
        st_error(403, '测速会话已过期，请重新开始测速');
    }

    $bytes = (int)$bytes;
    if ($bytes <= 0) {
        st_error(411, '测速请求缺少内容长度');
    }

    $key = 'speedtest:bytes:'.$token.':'.$direction;
    $request = bin2hex(random_bytes(16));
    $slots = array('speedtest:inflight', 'speedtest:inflight:'.$token.':'.$direction);
    $allowed = st_eval(file_get_contents(__DIR__.'/payload.lua'), array_merge(array(
        'speedtest:session:'.$token, 'speedtest:global', $key,
        'speedtest:phase:'.$token.':'.$direction,
    ), $slots), array($token, $bucket, $bytes, ST_DIRECTION_LIMIT,
        ST_SESSION_TTL, microtime(true), $direction === 'download' ? 12 : 14,
        $request, ST_REQUEST_LEASE, ST_REQUESTS_GLOBAL,
        $direction === 'download' ? ST_REQUESTS_DOWNLOAD : ST_REQUESTS_UPLOAD,
        microtime(true) + ST_REDIS_DISPATCH_WINDOW));
    if ((int)$allowed === -2) st_error(503, '测速保护服务暂时不可用', 15);
    if ((int)$allowed === -1) st_error(429, '测速连接较多，请稍后重试', 1);
    if ((int)$allowed !== 1) {
        st_error(429, '本次测速已达到单方向流量上限，请稍后重试', ST_SESSION_TTL);
    }
    register_shutdown_function(function () use ($slots, $request) {
        try {
            st_redis()->eval(file_get_contents(__DIR__.'/request-finish.lua'), array_merge($slots, array($request)), 2);
        } catch (Throwable $error) {
            // Fail closed: lease remains until expiry; never delete other slots.
        }
    });
    return $token;
}

function st_eval($script, $keys, $args)
{
    try {
        $result = st_redis()->eval($script, array_merge($keys, $args), count($keys));
        if ($result === false) throw new RuntimeException('Limiter command failed');
        return $result;
    } catch (Throwable $error) {
        st_error(503, '测速保护服务暂时不可用', 15);
    }
}

function st_stream_active($token, $direction)
{
    try {
        $r = st_redis();
        $deadline = $r->get('speedtest:phase:'.$token.':'.$direction);
        return $r->get('speedtest:global') === $token && $deadline !== false && microtime(true) < (float)$deadline;
    } catch (Throwable $error) {
        return false;
    }
}

function st_finish_session()
{
    if (($_SERVER['HTTP_X_SPEEDTEST_ACTION'] ?? '') !== 'finish') st_error(403, '无效的会话操作');
    $token = $_COOKIE['ST_SESSION'] ?? '';
    if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) st_error(403, '无效的会话');
    $released = st_eval(file_get_contents(__DIR__.'/session-finish.lua'), array('speedtest:global', 'speedtest:active:'.st_ip_bucket(st_client_ip()), 'speedtest:session:'.$token), array($token, st_ip_bucket(st_client_ip())));
    if ((int)$released === -1) {
        st_error(403, '会话来源不匹配，无法结束其他来源的测速');
    }
    setcookie('ST_SESSION', '', array('expires' => 1, 'path' => '/', 'httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax'));
    http_response_code(204);
    exit;
}
