<?php
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'cloudcomai',
        'user' => 'cloudcomai_user',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_url' => 'https://api.cloudcomai.com',
        'web_url' => 'https://cloudcomai.com',
        'allowed_origins' => [
            'https://cloudcomai.com',
            'https://www.cloudcomai.com',
            'https://app.cloudcomai.com',
        ],
        'token_secret' => 'CHANGE_TO_A_LONG_RANDOM_SECRET',
        'upload_dir' => __DIR__ . '/../storage/uploads',
    ],
];
