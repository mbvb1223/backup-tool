<?php

return [
    'db' => [
        'dir' => __DIR__ . '/backups',
        'keep_days' => 14,

        'mysqldump' => 'mysqldump',
        'mysqldump_options' => [
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--set-gtid-purged=OFF', // MySQL only: remove it for MariaDB
        ],

        'connections' => [
            'main' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'user' => 'backup',
                'password' => 'secret',
                'databases' => [
                    'project_a' => ['exclude_data' => ['sessions', 'cache'], 'exclude' => ['jobs']],
                    'project_b' => ['include' => ['users', 'orders', 'products']],
                    'project_c' => [],
                ],
            ],

            'reporting' => [
                'host' => 'db2.internal',
                'user' => 'backup',
                'password' => 'secret',
                'databases' => ['analytics', 'crm'],
            ],
        ],
    ],

    'files' => [
        'dir' => __DIR__ . '/backups',
        'keep_days' => 7,
        'paths' => [
            'shop_media' => ['path' => '/var/www/shop/public/media', 'exclude' => ['cache', '*.log']],
            'nginx' => '/etc/nginx',
        ],
    ],

    'uploaders' => [
        'r2' => [
            'type' => 's3',
            'endpoint' => 'https://ACCOUNT_ID.r2.cloudflarestorage.com',
            'region' => 'auto',
            'bucket' => 'my-bucket',
            'key' => '',
            'secret' => '',
            'prefix' => 'backup-tool',
            'keep_days' => 30,
        ],
    ],

    'log' => [
        'file' => __DIR__ . '/backup.log',
        'slack_webhook' => '',
    ],
];
