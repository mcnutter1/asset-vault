<?php
require_once __DIR__ . '/../lib/Util.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Plugins.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
    exit;
}

// Ensure session for CSRF
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
// Basic CSRF check for form/xhr
$token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid CSRF token']);
    exit;
}

$pluginId = $_POST['plugin'] ?? '';
$action = $_POST['action_key'] ?? '';
$assetId = isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0;
$phase = $_POST['phase'] ?? 'run'; // describe | run

// Collect any inputs (flat JSON or regular form fields prefixed with input_*)
$inputs = [];
if (isset($_POST['inputs']) && is_string($_POST['inputs'])) {
    $tmp = json_decode($_POST['inputs'], true);
    if (is_array($tmp)) $inputs = $tmp;
}
foreach ($_POST as $k => $v) {
    if (strpos($k, 'input_') === 0) {
        $name = substr($k, 6);
        $inputs[$name] = $v;
    }
}

if (!$pluginId || !$action) {
    echo json_encode(['ok'=>false,'error'=>'Missing plugin or action']);
    exit;
}

$res = PluginManager::runAction($pluginId, $action, [
    'asset_id' => $assetId,
    'phase' => $phase,
    'inputs' => $inputs,
]);
echo json_encode($res);
exit;
