<?php

class Util
{
    public static function config(): array
    {
        return require __DIR__ . '/../config.php';
    }

    public static function baseUrl(string $path = ''): string
    {
        $cfg = self::config();
        $base = rtrim($cfg['app']['base_url'], '/');
        return $base . '/' . ltrim($path, '/');
    }

    public static function h(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function redirect(string $path): void
    {
        header('Location: ' . self::baseUrl($path));
        exit;
    }

    public static function ensureUploadsDir(): void
    {
        $cfg = self::config();
        if (!is_dir($cfg['app']['uploads_dir'])) {
            @mkdir($cfg['app']['uploads_dir'], 0775, true);
        }
    }

    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function checkCsrf(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $token = $_POST['csrf'] ?? '';
        if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(400);
            echo 'Invalid CSRF token';
            exit;
        }
    }
}

