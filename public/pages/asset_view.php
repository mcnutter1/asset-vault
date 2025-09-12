<?php
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/Util.php';
$pdo = Database::get();
$code = $_GET['code'] ?? '';
if (!$code) { http_response_code(404); echo '<div class="card"><h1>Not Found</h1></div>'; return; }

$stmt = $pdo->prepare('SELECT a.*, ac.name AS category_name, l.name AS location_name FROM assets a 
  LEFT JOIN asset_categories ac ON ac.id=a.category_id 
  LEFT JOIN locations l ON l.id=a.location_id
  WHERE a.public_token=? AND a.is_deleted=0');
$stmt->execute([$code]);
$asset = $stmt->fetch();
if (!$asset) { http_response_code(404); echo '<div class="card"><h1>Not Found</h1></div>'; return; }

$photos = [];
$stmt = $pdo->prepare("SELECT id, filename FROM files WHERE entity_type='asset' AND entity_id=? AND mime_type LIKE 'image/%' ORDER BY uploaded_at DESC");
$stmt->execute([(int)$asset['id']]);
$photos = $stmt->fetchAll();

?>
<div class="card">
  <h1><?= Util::h($asset['name']) ?></h1>
  <div class="small muted">Category: <?= Util::h($asset['category_name']) ?></div>
  <?php if ($asset['location_name']): ?><div class="small muted">Location: <?= Util::h($asset['location_name']) ?></div><?php endif; ?>
  <?php if ($asset['description']): ?><p><?= nl2br(Util::h($asset['description'])) ?></p><?php endif; ?>

  <div class="row">
    <div class="col-4"><label>Make</label><div><?= Util::h($asset['make']) ?></div></div>
    <div class="col-4"><label>Model</label><div><?= Util::h($asset['model']) ?></div></div>
    <div class="col-4"><label>Year</label><div><?= Util::h($asset['year']) ?></div></div>
  </div>

  <?php if ($photos): ?>
    <h2>Photos</h2>
    <div class="gallery" style="margin-top:8px">
      <?php foreach ($photos as $ph): ?>
        <img src="<?= Util::baseUrl('file.php?id='.(int)$ph['id']) ?>" alt="<?= Util::h($ph['filename']) ?>">
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

