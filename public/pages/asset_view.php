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

// Build contents (children) table with values
$allAssets = $pdo->query('SELECT a.id, a.name, a.parent_id, ac.name AS category, l.name AS locname FROM assets a 
  LEFT JOIN asset_categories ac ON ac.id=a.category_id 
  LEFT JOIN locations l ON l.id=a.location_id
  WHERE a.is_deleted=0 ORDER BY a.parent_id, a.name')->fetchAll();
$byParent = [];
foreach ($allAssets as $a) { $byParent[$a['parent_id'] ?? 0][] = $a; }

$vals = $pdo->query("SELECT asset_id, amount, valuation_date FROM asset_values WHERE value_type='current' ORDER BY valuation_date ASC")->fetchAll();
$selfValue = [];
foreach ($vals as $v) { $selfValue[$v['asset_id']] = (float)$v['amount']; }

$memoTotals = [];
function pv_totalsFor($id, $byParent, $selfValue, &$memoTotals){
  if (isset($memoTotals[$id])) return $memoTotals[$id];
  $self = $selfValue[$id] ?? 0.0;
  $children = $byParent[$id] ?? [];
  $contents = 0.0;
  foreach ($children as $c){
    $t = pv_totalsFor($c['id'], $byParent, $selfValue, $memoTotals);
    $contents += $t['total'];
  }
  return $memoTotals[$id] = ['self'=>$self, 'contents'=>$contents, 'total'=>$self+$contents];
}

$flat = [];
function pv_flatten($parentId, $byParent, $depth, &$flat){
  if (empty($byParent[$parentId])) return;
  foreach ($byParent[$parentId] as $a){
    $a['depth'] = $depth;
    $flat[] = $a;
    pv_flatten($a['id'], $byParent, $depth+1, $flat);
  }
}
pv_flatten((int)$asset['id'], $byParent, 0, $flat);

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

<?php if ($flat): ?>
<div class="card" style="margin-top:16px">
  <h2>Contents</h2>
  <table>
    <thead><tr><th>Asset</th><th>Category</th><th>Location</th><th>Value</th><th>Contents</th><th>Total</th></tr></thead>
    <tbody>
      <?php foreach ($flat as $row): $t = pv_totalsFor($row['id'], $byParent, $selfValue, $memoTotals); ?>
        <tr>
          <td><div style="padding-left: <?= (int)$row['depth']*16 ?>px"><?= Util::h($row['name']) ?></div></td>
          <td><?= Util::h($row['category']) ?></td>
          <td><?= $row['locname'] ? Util::h($row['locname']) : '-' ?></td>
          <td>$<?= number_format($t['self'], 2) ?></td>
          <td>$<?= number_format($t['contents'], 2) ?></td>
          <td><strong>$<?= number_format($t['total'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
