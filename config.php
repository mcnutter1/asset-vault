<?php
// Default config (edit as needed). For safety, credentials are blanks.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'asset_vault',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'base_url' => '/',
        'uploads_dir' => __DIR__ . '/public/uploads',
        'uploads_url' => '/uploads',
        'debug' => true,
    ],
];
