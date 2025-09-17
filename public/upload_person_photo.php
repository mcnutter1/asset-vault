<?php
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Util.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

function _out($code, $data){ http_response_code($code); echo json_encode($data); exit; }

$config = require __DIR__.'/config.php';
$cookie = $_COOKIE[$config['cookie_name']] ?? null;
if (!$cookie) _out(401, ['ok'=>false,'error'=>'Not authenticated']);
$data = json_decode($cookie, true);
if (!($data && isset($data['session_token']))) _out(401, ['ok'=>false,'error'=>'Invalid session']);
if (!revalidate($data['session_token'])) _out(401, ['ok'=>false,'error'=>'Session expired']);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = $_POST['csrf'] ?? '';
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) _out(400, ['ok'=>false,'error'=>'Invalid CSRF']);

$personId = isset($_POST['person_id']) ? (int)$_POST['person_id'] : 0;
if ($personId <= 0) _out(400, ['ok'=>false,'error'=>'Missing person_id']);

$pdo = Database::get();
$chk = $pdo->prepare('SELECT id FROM people WHERE id=?');
$chk->execute([$personId]);
if (!$chk->fetchColumn()) _out(404, ['ok'=>false,'error'=>'Person not found']);

// Collect files
$files = [];
if (!empty($_FILES['photo'])) $files[] = $_FILES['photo'];
if (!empty($_FILES['photos'])){
  $n=$_FILES['photos']['name']; $t=$_FILES['photos']['type']; $tmp=$_FILES['photos']['tmp_name']; $e=$_FILES['photos']['error']; $s=$_FILES['photos']['size'];
  for($i=0;$i<count($n);$i++) $files[]=['name'=>$n[$i],'type'=>$t[$i],'tmp_name'=>$tmp[$i],'error'=>$e[$i],'size'=>$s[$i]];
}
if (!$files) _out(400, ['ok'=>false,'error'=>'No files received']);

$inserted = [];
foreach ($files as $f){
  if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK){ _out(400,['ok'=>false,'error'=>'Upload error']); }
  $tmp=$f['tmp_name']; $orig=$f['name']; $mime=$f['type'] ?: 'application/octet-stream';
  $content=@file_get_contents($tmp); if ($content===false) _out(400,['ok'=>false,'error'=>'Could not read file']);
  $size=strlen($content);
  $stmt=$pdo->prepare("INSERT INTO files(entity_type, entity_id, filename, mime_type, size, content) VALUES ('person', ?, ?, ?, ?, ?)");
  $stmt->bindValue(1,$personId, PDO::PARAM_INT);
  $stmt->bindValue(2,$orig);
  $stmt->bindValue(3,$mime);
  $stmt->bindValue(4,$size, PDO::PARAM_INT);
  $stmt->bindParam(5,$content, PDO::PARAM_LOB);
  $stmt->execute();
  $fid=(int)$pdo->lastInsertId();
  $inserted[]=['id'=>$fid,'filename'=>$orig,'mime'=>$mime,'size'=>$size,'url'=>Util::baseUrl('file.php?id='.$fid)];
}

_out(200,['ok'=>true,'files'=>$inserted]);
