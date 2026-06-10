<?php
declare(strict_types=1);

return [
    'username' => 'osman@dogubatiproje.com',
    'password' => 'Osmn_1344.',
    'password_env' => 'DOGUBATI_SMTP_PASSWORD',
    'from_email' => 'osman@dogubatiproje.com',
    'from_name' => 'Dogu Bati Proje',
    'to_email' => 'osman@dogubatiproje.com',
    'to_name' => 'Osman',
    'timeout' => 20,
    'verify_peer' => false,
    'verify_peer_name' => false,
    'allow_self_signed' => true,
    'servers' => [
        [
            'host' => 'shanks.trdns.com',
            'port' => 465,
            'encryption' => 'ssl',
        ],
        [
            'host' => 'shanks.trdns.com',
            'port' => 587,
            'encryption' => 'tls',
        ],
    ],
];
