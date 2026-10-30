<?php

require_once __DIR__.'/rate_limit.php';
st_check_request(array('GET'));

// Disable Compression
@ini_set('zlib.output_compression', 'Off');
@ini_set('output_buffering', 'Off');
@ini_set('output_handler', '');

/**
 * @return int
 */
function getChunkCount()
{
    if (
        !array_key_exists('ckSize', $_GET)
        || !is_string($_GET['ckSize'])
        || !ctype_digit($_GET['ckSize'])
        || (int) $_GET['ckSize'] <= 0
    ) {
        return 4;
    }

    if ((int) $_GET['ckSize'] > 100) {
        return 100;
    }

    return (int) $_GET['ckSize'];
}

/**
 * @return void
 */
function sendHeaders()
{
    header('HTTP/1.1 200 OK');

    // Indicate a file download
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename=random.dat');
    header('Content-Transfer-Encoding: binary');

    // Cache settings: never cache this request
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
}

// Determine how much data we should send
$chunks = getChunkCount();
$token = st_require_payload('download', $chunks * 1048576);

// Generate data
if (function_exists('random_bytes')) {
    $data = random_bytes(1048576);
} else {
    $data = openssl_random_pseudo_bytes(1048576);
}

// Deliver chunks of 1048576 bytes
sendHeaders();
for ($i = 0; $i < $chunks; $i++) {
    if (connection_aborted() || !st_stream_active($token, 'download')) break;
    echo $data;
    flush();
    if (connection_aborted() || !st_stream_active($token, 'download')) break;
}
