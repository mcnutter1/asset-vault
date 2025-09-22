<?php
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/Database.php';

class PluginManager
{
    public static function pluginsDir(): string
    {
        return dirname(__DIR__) . '/plugins';
    }

    public static function list(): array
    {
        $dir = self::pluginsDir();
        if (!is_dir($dir)) return [];
        $out = [];
        foreach (scandir($dir) as $name) {
            if ($name === '.' || $name === '..') continue;
            $pdir = $dir . '/' . $name;
            if (!is_dir($pdir)) continue;
            $jsonPath = $pdir . '/plugin.json';
            if (!is_file($jsonPath)) continue;
            $meta = json_decode(@file_get_contents($jsonPath), true);
            if (!is_array($meta)) continue;
            $meta['id'] = $meta['id'] ?? $name;
            $meta['dir'] = $pdir;
            $meta['enabled'] = self::getConfig($meta['id'])['enabled'] ?? ($meta['default_enabled'] ?? true);
            $out[$meta['id']] = $meta;
        }
        ksort($out);
        return $out;
    }

    public static function get(string $id): ?array
    {
        $list = self::list();
        return $list[$id] ?? null;
    }

    public static function getConfig(string $id): array
    {
        $raw = Settings::get('plugin:' . $id);
        $cfg = $raw ? json_decode($raw, true) : [];
        if (!is_array($cfg)) $cfg = [];
        return $cfg;
    }

    public static function saveConfig(string $id, array $cfg): void
    {
        Settings::set('plugin:' . $id, json_encode($cfg));
    }

    public static function isEnabled(string $id): bool
    {
        $meta = self::get($id);
        if (!$meta) return false;
        $cfg = self::getConfig($id);
        return (bool)($cfg['enabled'] ?? ($meta['default_enabled'] ?? false));
    }

    public static function includeMain(array $plugin): bool
    {
        // Auto-load shared bootstrap first, if present
        $sharedBoot = self::pluginsDir() . '/_bootstrap.php';
        if (is_file($sharedBoot)) { require_once $sharedBoot; }
        // Allow per-plugin bootstrap override (optional)
        $perBoot = rtrim($plugin['dir'] ?? '', '/') . '/_bootstrap.php';
        if (is_file($perBoot)) { require_once $perBoot; }
        // Load plugin main file
        $main = $plugin['main'] ?? 'plugin.php';
        $path = rtrim($plugin['dir'] ?? '', '/') . '/' . $main;
        if (is_file($path)) { require_once $path; return true; }
        return false;
    }

    public static function runAction(string $pluginId, string $action, array $context = []): array
    {
        $plugin = self::get($pluginId);
        if (!$plugin) return ['ok'=>false,'error'=>'Plugin not found'];
        if (!self::isEnabled($pluginId)) return ['ok'=>false,'error'=>'Plugin disabled'];
        if (!self::includeMain($plugin)) return ['ok'=>false,'error'=>'Plugin entry file missing'];
        $class = $plugin['class'] ?? null;
        if (!$class || !class_exists($class)) return ['ok'=>false,'error'=>'Plugin class not found'];
        $obj = new $class($plugin, self::getConfig($pluginId));
        if (!method_exists($obj, 'runAction')) return ['ok'=>false,'error'=>'Plugin does not implement runAction'];
        try {
            return (array)$obj->runAction($action, $context);
        } catch (Throwable $e) {
            return ['ok'=>false,'error'=>'Plugin error: '.$e->getMessage()];
        }
    }

    // Utility: resolve category IDs by case-insensitive name
    public static function categoryIdByName(string $name): ?int
    {
        $pdo = Database::get();
        $st = $pdo->prepare('SELECT id FROM asset_categories WHERE LOWER(name)=LOWER(?)');
        $st->execute([$name]);
        $id = $st->fetchColumn();
        return $id!==false ? (int)$id : null;
    }

    // Utility: property defs for category ids
    public static function propertyDefsForCategories(array $categoryIds): array
    {
        $categoryIds = array_values(array_unique(array_map('intval', array_filter($categoryIds))));
        if (!$categoryIds) return [];
        $pdo = Database::get();
        $in = implode(',', $categoryIds);
        $rows = $pdo->query('SELECT id, category_id, name_key, display_name, input_type FROM asset_property_defs WHERE is_active=1 AND category_id IN ('.$in.') ORDER BY sort_order, display_name')->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}

// Optional base class providing shared utilities (debug logging, helpers) for plugins
abstract class BasePlugin
{
    protected array $meta;
    protected array $config;
    protected bool $debug = false;
    protected array $debugLog = [];

    public function __construct(array $meta, array $config)
    {
        $this->meta = $meta;
        $this->config = $config;
        $this->debug = !empty($config['debug']);
    }

    protected function dbg(string $line): void
    {
        if (!$this->debug) return;
        $this->debugLog[] = '['.date('H:i:s').'] '.$line;
        $id = $this->meta['id'] ?? 'plugin';
        @error_log('Plugin['.$id.']: '.$line);
    }

    protected function renderDebug(): string
    {
        if (!$this->debug || !$this->debugLog) return '';
        $out = '<div class="debug" style="margin-top:10px"><h3 style="margin:0 0 6px">Debug Log</h3><pre style="white-space:pre-wrap; font-size:11px; line-height:1.4; padding:8px; background:#f8fafc; border:1px solid var(--border); border-radius:6px;">';
        $out .= htmlspecialchars(implode("\n", $this->debugLog), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $out .= '</pre></div>';
        return $out;
    }

    protected function fail(string $msg): array
    {
        $resp = ['ok'=>false,'error'=>$msg];
        if ($this->debug) { $resp['debug_html'] = $this->renderDebug(); }
        return $resp;
    }
}
