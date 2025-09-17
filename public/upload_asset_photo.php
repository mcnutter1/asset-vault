<?php
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Util.php';
require_once __DIR__ . '/auth.php';

// JSON response helper
function up_send($code, $data){
  http_response_code($code);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}

// Require auth + role
ensure_role(['vault','admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  up_send(405, ['ok'=>false,'error'=>'Method not allowed']);
}

// CSRF
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$token = $_POST['csrf'] ?? '';
if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
  up_send(400, ['ok'=>false,'error'=>'Invalid CSRF token']);
}

$assetId = isset($_POST['asset_id']) ? (int)$_POST['asset_id'] : 0;
if ($assetId <= 0) {
  up_send(400, ['ok'=>false,'error'=>'Missing asset_id']);
}

$pdo = Database::get();
$chk = $pdo->prepare('SELECT id FROM assets WHERE id=? AND is_deleted=0');
$chk->execute([$assetId]);
if (!$chk->fetchColumn()) {
  up_send(404, ['ok'=>false,'error'=>'Asset not found']);
}

// Normalization helper (duplicated for endpoint isolation)
if (!function_exists('av_normalize_upload_image')) {
  function av_normalize_upload_image(string $tmp, string $origName, string $mime): array {
    if (function_exists('finfo_open')) {
      $fi = finfo_open(FILEINFO_MIME_TYPE);
      if ($fi) { $det = @finfo_file($fi, $tmp); if ($det) $mime = $det; @finfo_close($fi); }
    }
    $outMime = $mime; $outName = $origName; $content = @file_get_contents($tmp);
    if ($content === false) { return [$content, $outMime, $outName]; }
    if (preg_match('~^image/(heic|heif)$~i', (string)$mime)) {
      if (extension_loaded('imagick')) {
        try { $img = new Imagick($tmp); if (method_exists($img,'autoOrient')) { @$img->autoOrient(); }
          $img->setImageFormat('jpeg'); $img->setImageCompressionQuality(85);
          $content = (string)$img->getImageBlob(); $outMime = 'image/jpeg';
          $outName = preg_replace('/\.(heic|heif)$/i', '.jpg', $origName);
        } catch (Throwable $e) { /* ignore */ }
      }
      return [$content, $outMime, $outName];
    }
    $maxDim = 2400; $maxProcessSize = 8*1024*1024;
    if (strlen($content) <= $maxProcessSize && preg_match('~^image/(jpeg|png|webp)$~i', (string)$mime)) {
      $info = @getimagesize($tmp);
      if ($info && isset($info[0],$info[1])) {
        $w=(int)$info[0]; $h=(int)$info[1]; $scale = max($w,$h) > $maxDim ? ($maxDim / max($w,$h)) : 1.0; $needsScale = $scale < 0.999;
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION)); $img=null;
        if (stripos($mime,'jpeg')!==false || $ext==='jpg' || $ext==='jpeg') { $img=@imagecreatefromjpeg($tmp);
          if (function_exists('exif_read_data')) { $exif=@exif_read_data($tmp); if (!empty($exif['Orientation'])) {
            switch ((int)$exif['Orientation']) { case 3:$img=@imagerotate($img,180,0); break; case 6:$img=@imagerotate($img,-90,0); break; case 8:$img=@imagerotate($img,90,0); break; }
          } }
        } elseif (stripos($mime,'png')!==false || $ext==='png') { $img=@imagecreatefrompng($tmp); }
        elseif (stripos($mime,'webp')!==false || $ext==='webp') { if (function_exists('imagecreatefromwebp')) { $img=@imagecreatefromwebp($tmp); } }
        if ($img) {
          if ($needsScale) { $newW=(int)round($w*$scale); $newH=(int)round($h*$scale); $dst=imagecreatetruecolor($newW,$newH); imagecopyresampled($dst,$img,0,0,0,0,$newW,$newH,$w,$h); imagedestroy($img); $img=$dst; }
          ob_start(); @imagejpeg($img, null, 85); $content2 = ob_get_clean(); if ($content2) { $content=$content2; $outMime='image/jpeg'; if (!preg_match('/\.(jpe?g)$/i',$outName)) { $outName = preg_replace('/\.[^.]*$/','.jpg',$outName); } }
          imagedestroy($img);
        }
      }
    }
    return [$content, $outMime, $outName];
  }
}

// Accept single 'photo' or multiple 'photos[]'
$files = [];
if (!empty($_FILES['photo'])) { $files[] = $_FILES['photo']; }
if (!empty($_FILES['photos'])) {
  // Normalize multiple to list of single-like arrays
  $names = $_FILES['photos']['name'];
  $types = $_FILES['photos']['type'];
  $tmpns = $_FILES['photos']['tmp_name'];
  $errs  = $_FILES['photos']['error'];
  $sizes = $_FILES['photos']['size'];
  for ($i=0; $i<count($names); $i++) {
    $files[] = ['name'=>$names[$i],'type'=>$types[$i],'tmp_name'=>$tmpns[$i],'error'=>$errs[$i],'size'=>$sizes[$i]];
  }
}

if (!$files) {
  // When post_max_size is exceeded, PHP may deliver an empty $_FILES
  up_send(400, ['ok'=>false,'error'=>'No files received. If you tried a very large photo, it may exceed server limits.']);
}

$inserted = [];
foreach ($files as $f) {
  $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($err !== UPLOAD_ERR_OK) {
    $msg = ($f['name'] ?? 'photo') . ': ';
    switch ($err) {
      case UPLOAD_ERR_INI_SIZE:
      case UPLOAD_ERR_FORM_SIZE: $msg .= 'File too large (server limit).'; break;
      case UPLOAD_ERR_PARTIAL: $msg .= 'Upload interrupted (partial).'; break;
      case UPLOAD_ERR_NO_FILE: $msg .= 'No file selected.'; break;
      case UPLOAD_ERR_NO_TMP_DIR: $msg .= 'Server missing temp folder.'; break;
      case UPLOAD_ERR_CANT_WRITE: $msg .= 'Failed to write file to disk.'; break;
      case UPLOAD_ERR_EXTENSION: $msg .= 'Upload blocked by a PHP extension.'; break;
      default: $msg .= 'Error code '.$err; break;
    }
    up_send(400, ['ok'=>false,'error'=>$msg]);
  }
  $tmp = $f['tmp_name']; $orig = $f['name']; $mime = $f['type'] ?: 'application/octet-stream';
  [$content, $outMime, $outName] = av_normalize_upload_image($tmp, $orig, $mime);
  if ($content === false || $content === null) {
    up_send(400, ['ok'=>false,'error'=>($orig?:'photo').': could not be read.']);
  }
  $size = strlen($content);
  $stmt = $pdo->prepare("INSERT INTO files(entity_type, entity_id, filename, mime_type, size, content) VALUES ('asset', ?, ?, ?, ?, ?)");
  $stmt->bindValue(1, $assetId, PDO::PARAM_INT);
  $stmt->bindValue(2, $outName, PDO::PARAM_STR);
  $stmt->bindValue(3, $outMime, PDO::PARAM_STR);
  $stmt->bindValue(4, $size, PDO::PARAM_INT);
  $stmt->bindParam(5, $content, PDO::PARAM_LOB);
  $stmt->execute();
  $fid = (int)$pdo->lastInsertId();
  $inserted[] = [
    'id' => $fid,
    'filename' => $outName,
    'mime' => $outMime,
    'size' => $size,
    'url' => Util::baseUrl('file.php?id='.$fid)
  ];
}

up_send(200, ['ok'=>true, 'files'=>$inserted]);

