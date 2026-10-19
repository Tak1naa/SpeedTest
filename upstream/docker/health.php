<?php
require_once __DIR__.'/backend/rate_limit.php';
st_check_request(array('GET'));
if (st_redis()->ping() === false) st_error(503, '服务暂时不可用');
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('status' => 'ok'));
