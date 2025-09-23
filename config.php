<?php
// Default config (edit as needed). For safety, credentials are blanks.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'asset_vault',
        'user' => 'asset_vault_app',
        'pass' => 'MvX2qC9tJ7rN4bP1yL6gF3wZ8kH5sD0',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        // Absolute base URL for building full links (used by QR codes, emails, etc.)
        'base_url' => 'https://vault.mcnutt.cloud/',
        'uploads_dir' => __DIR__ . '/public/uploads',
        'uploads_url' => '/uploads',
        'debug' => true,
    ],
    'openai' => [
        'api_key' => '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',
    ],
];
