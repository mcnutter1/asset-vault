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

$policyId = isset($_POST['policy_id']) ? (int)$_POST['policy_id'] : 0;
if ($policyId <= 0) out(400, ['ok'=>false,'error'=>'Missing policy_id']);

$pdo = Database::get();
$chk = $pdo->prepare('SELECT id FROM policies WHERE id=?');
$chk->execute([$policyId]);
if (!$chk->fetchColumn()) out(404, ['ok'=>false,'error'=>'Policy not found']);

// Accept single 'file' or multiple 'files[]'
$files=[];
if (!empty($_FILES['file'])) $files[] = $_FILES['file'];
if (!empty($_FILES['files'])){
  $names=$_FILES['files']['name']; $types=$_FILES['files']['type']; $tmps=$_FILES['files']['tmp_name']; $errs=$_FILES['files']['error']; $sizes=$_FILES['files']['size'];
  for ($i=0;$i<count($names);$i++){ $files[]=['name'=>$names[$i],'type'=>$types[$i],'tmp_name'=>$tmps[$i],'error'=>$errs[$i],'size'=>$sizes[$i]]; }
}
if (!$files) out(400, ['ok'=>false,'error'=>'No files received']);

$inserted=[];
foreach ($files as $f){
  $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($err !== UPLOAD_ERR_OK){
    $msg = ($f['name'] ?? 'file') . ': ';
    switch($err){ case UPLOAD_ERR_INI_SIZE: case UPLOAD_ERR_FORM_SIZE: $msg.='File too large'; break; case UPLOAD_ERR_PARTIAL: $msg.='Partial upload'; break; case UPLOAD_ERR_NO_FILE: $msg.='No file'; break; default: $msg.='Error code '.$err; }
    out(400, ['ok'=>false,'error'=>$msg]);
  }
  $tmp=$f['tmp_name']; $name=$f['name']; $mime=$f['type'] ?: 'application/octet-stream'; $content=@file_get_contents($tmp); $size=strlen((string)$content);
  if ($content===false) out(400, ['ok'=>false,'error'=>($name?:'file').': could not be read']);
  $stmt=$pdo->prepare("INSERT INTO files(entity_type, entity_id, filename, mime_type, size, content) VALUES ('policy', ?, ?, ?, ?, ?)");
  $stmt->bindValue(1,$policyId,PDO::PARAM_INT); $stmt->bindValue(2,$name,PDO::PARAM_STR); $stmt->bindValue(3,$mime,PDO::PARAM_STR); $stmt->bindValue(4,$size,PDO::PARAM_INT); $stmt->bindParam(5,$content,PDO::PARAM_LOB);
  $stmt->execute();
  $fid=(int)$pdo->lastInsertId();
  $inserted[]=['id'=>$fid,'filename'=>$name,'mime'=>$mime,'size'=>$size,'url'=>Util::baseUrl('file.php?id='.$fid)];
}
out(200,['ok'=>true,'files'=>$inserted]);

