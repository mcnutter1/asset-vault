<?php
// Copy this file to config.php and adjust values.

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
        'base_url' => '/',        // If served from subdir, set e.g. '/asset-vault/'
        'uploads_dir' => __DIR__ . '/public/uploads',
        'uploads_url' => '/uploads',
        'debug' => true,
    ],
];
