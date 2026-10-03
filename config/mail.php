<?php

// Email is sent only when SMTP_ADDR (host:port) is set; otherwise emailing a
// document answers 501 (spec/api.md §5.11). Port 465 uses implicit TLS, any
// other port STARTTLS when the server offers it.
$addr = env('SMTP_ADDR', '');
[$host, $port] = array_pad(explode(':', $addr, 2), 2, '587');

return [
    'default' => 'smtp',
    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => (int) $port === 465 ? 'smtps' : 'smtp',
            'host' => $host,
            'port' => (int) $port,
            'username' => env('SMTP_USER'),
            'password' => env('SMTP_PASS'),
        ],
    ],
    'from' => ['address' => env('MAIL_FROM'), 'name' => null],
    'enabled' => $addr !== '',
];
