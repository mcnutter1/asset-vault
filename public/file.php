<?php
require_once __DIR__ . '/../lib/Database.php';

$pdo = Database::get();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { http_response_code(404); exit; }

$stmt = $pdo->prepare('SELECT filename, mime_type, size, content FROM files WHERE id=?');
$stmt->execute([$id]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$file) { http_response_code(404); exit; }

$mime = $file['mime_type'] ?: 'application/octet-stream';
$name = $file['filename'] ?: ('file_'.$id);
$download = isset($_GET['download']) && $_GET['download'] == '1';

header('Content-Type: '.$mime);
header('Content-Length: '.(int)$file['size']);
header('Cache-Control: private, max-age=31536000');
header('Content-Disposition: '.($download ? 'attachment' : 'inline').'; filename="'.addslashes($name).'"');
echo $file['content'];
exit;

