<?php

$trustedProxyIps = array_map(
    'trim',
    explode(',', (string) env('TRUSTED_PROXY_IPS', ''))
);

return [
    'proxies' => array_values(array_filter(
        $trustedProxyIps,
        static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false
    )),
];
