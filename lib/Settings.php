<?php
require_once __DIR__ . '/Database.php';

class Settings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key=?');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        if ($v === false) return $default;
        return (string)$v;
    }

    public static function set(string $key, ?string $value): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('INSERT INTO app_settings(setting_key, setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        $stmt->execute([$key, $value]);
    }
}
