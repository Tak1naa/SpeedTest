<?php
require __DIR__.'/../upstream/backend/rate_limit.php';
foreach (array(
    '192.0.2.1' => '192.0.2.1/32',
    '2001:db8:abcd:1234::1' => '2001:db8:abcd:1234::1/128',
    '2001:db8:abcd:1234:ffff::2' => '2001:db8:abcd:1234:ffff::2/128',
    '::ffff:192.0.2.2' => '192.0.2.2/32',
) as $address => $expected) {
    $_SERVER['REMOTE_ADDR'] = $address;
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    if (st_ip_bucket(st_client_ip()) !== $expected) throw new RuntimeException('IP bucket mismatch');
}
echo "PASS: IPv4 /32, IPv6 /128, mapped IPv4, forwarded header ignored\n";
foreach (array(
    '192.0.2.1' => '192.0.2.1/32',
    '2001:db8:abcd:1234::1' => '2001:db8:abcd:1234::/64',
    '2001:db8:abcd:1234:ffff::2' => '2001:db8:abcd:1234::/64',
    '2001:db8:abcd:1235::1' => '2001:db8:abcd:1235::/64',
) as $address => $expected) {
    if (st_prefix_bucket($address) !== $expected) throw new RuntimeException('Prefix bucket mismatch');
}
echo "PASS: exact-address binding separated from /64 coarse prefix quota\n";
