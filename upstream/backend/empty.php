<?php

require_once __DIR__.'/rate_limit.php';
st_check_request(array('GET', 'POST'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length < 0 || $length > 33554432) st_error(413, '上传请求过大');
    // Upstream sends an empty POST probe before uploading data.
    $token = st_require_payload('upload', max(1, $length));
    $input = fopen('php://input', 'rb');
    $received = 0;
    while (!feof($input)) {
        if (!st_stream_active($token, 'upload')) st_error(429, '上传阶段已结束');
        $chunk = fread($input, 65536);
        if ($chunk === false) st_error(400, '上传读取失败');
        if (!st_stream_active($token, 'upload')) st_error(429, '上传阶段已结束');
        $received += strlen($chunk);
        if ($received > 33554432 || $received > $length) st_error(413, '上传请求过大');
    }
    fclose($input);
}

header('HTTP/1.1 200 OK');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Connection: keep-alive');
