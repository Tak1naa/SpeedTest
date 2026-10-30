<?php
require __DIR__.'/../upstream/backend/rate_limit.php';
foreach (array('/' => '/', '/backend/getIP.php' => '/',
    '/speedtest/backend/getIP.php' => '/speedtest/',
    '/speedtest/backend/getIP.php?x=1' => '/speedtest/',
    '/speedtest-other/' => '/', '/cycle/' => '/') as $uri => $expected) {
    $_SERVER['REQUEST_URI'] = $uri;
    if (st_cookie_path() !== $expected) throw new RuntimeException('Cookie path contract');
}
echo "PASS: subpath/root cookie paths and finish helper; path is not same-origin isolation\n";
