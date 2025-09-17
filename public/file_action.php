<?php
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Util.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

function out($code, $data){ http_response_code($code); echo json_encode($data); exit; }

// Auth (no redirects)
$config = require __DIR__.'/config.php';
$cookie = $_COOKIE[$config['cookie_name']] ?? null;
if (!$cookie) out(401, ['ok'=>false,'error'=>'Not authenticated']);
$data = json_decode($cookie, true);
if (!($data && isset($data['session_token']))) out(401, ['ok'=>false,'error'=>'Invalid session']);
if (!revalidate($data['session_token'])) out(401, ['ok'=>false,'error'=>'Session expired']);
$now = $_COOKIE[$config['cookie_name']] ?? null; $auth = $now ? (json_decode($now, true) ?: $data) : $data;
$roles = $auth['roles'] ?? [];
if (!(in_array('vault',$roles,true) || in_array('admin',$roles,true))) out(403, ['ok'=>false,'error'=>'Not authorized']);

// CSRF
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) out(400, ['ok'=>false,'error'=>'Invalid CSRF']);

$id = (int)($_POST['file_id'] ?? 0);
$action = $_POST['action'] ?? '';
if ($id <= 0 || !in_array($action, ['trash','restore','delete'], true)) out(400, ['ok'=>false,'error'=>'Bad request']);

$pdo = Database::get();
$row = $pdo->prepare('SELECT * FROM files WHERE id=?');
$row->execute([$id]);
$f = $row->fetch();
if (!$f) out(404, ['ok'=>false,'error'=>'File not found']);

if ($action === 'trash') {
  $pdo->prepare('UPDATE files SET is_trashed=1, trashed_at=NOW() WHERE id=?')->execute([$id]);
  out(200, ['ok'=>true]);
} elseif ($action === 'restore') {
  $pdo->prepare('UPDATE files SET is_trashed=0, trashed_at=NULL WHERE id=?')->execute([$id]);
  out(200, ['ok'=>true]);
} else { // delete
  $pdo->prepare('DELETE FROM files WHERE id=?')->execute([$id]);
  out(200, ['ok'=>true]);
}

