<?php
$pdo = Database::get();

// Handle delete
if (($_POST['action'] ?? '') === 'delete') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $stmt = $pdo->prepare('UPDATE assets SET is_deleted=1 WHERE id=?');
  $stmt->execute([$id]);
  $pdo->prepare('INSERT INTO audit_log(entity_type, entity_id, action, details) VALUES ("asset", ?, "delete", NULL)')->execute([$id]);
  Util::redirect('index.php?page=assets');
}

// Generate public link token
if (($_POST['action'] ?? '') === 'gen_token') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $token = bin2hex(random_bytes(12));
  $stmt = $pdo->prepare('UPDATE assets SET public_token=? WHERE id=?');
  $stmt->execute([$token, $id]);
  Util::redirect('index.php?page=assets');
}

// Fetch assets tree
// Detect optional asset_locations table and choose the safest query
$hasAssetLocations = false;
try {
  $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'asset_locations'");
  $stmt->execute();
  $hasAssetLocations = (bool)$stmt->fetchColumn();
} catch (Throwable $e) { /* ignore and assume false */ }

if ($hasAssetLocations) {
  $sql = 'SELECT a.id, a.name, a.parent_id, a.public_token, a.category_id, a.make, a.model, a.year, ac.name AS category, al.name AS locname
          FROM assets a
          LEFT JOIN asset_categories ac ON ac.id=a.category_id
          LEFT JOIN asset_locations al ON al.id=a.asset_location_id
          WHERE a.is_deleted=0
          ORDER BY a.parent_id IS NOT NULL, a.parent_id, a.name';
} else {
  // Fallback to free-text location column on assets if locations table not available
  $sql = 'SELECT a.id, a.name, a.parent_id, a.public_token, a.category_id, a.make, a.model, a.year, ac.name AS category, a.location AS locname
          FROM assets a
          LEFT JOIN asset_categories ac ON ac.id=a.category_id
          WHERE a.is_deleted=0
          ORDER BY a.parent_id IS NOT NULL, a.parent_id, a.name';
}
$assets = $pdo->query($sql)->fetchAll();
$byParent = [];
foreach ($assets as $a) { $byParent[$a['parent_id'] ?? 0][] = $a; }

// Latest current values per asset
$vals = $pdo->query("SELECT asset_id, amount, valuation_date FROM asset_values WHERE value_type='current' ORDER BY valuation_date ASC")->fetchAll();
$selfValue = [];
foreach ($vals as $v) { $selfValue[$v['asset_id']] = (float)$v['amount']; }

// Photo counts per asset (non-trashed)
$photoRows = $pdo->query("SELECT entity_id asset_id, COUNT(*) c FROM files WHERE entity_type='asset' AND is_trashed=0 AND mime_type LIKE 'image/%' GROUP BY entity_id")->fetchAll();
$photoCount = [];
foreach ($photoRows as $r) { $photoCount[(int)$r['asset_id']] = (int)$r['c']; }

// Categories list for filter UI
$cats = $pdo->query('SELECT id, name FROM asset_categories ORDER BY name')->fetchAll();

// Filters
$q = trim($_GET['q'] ?? '');
$catFilter = isset($_GET['cat']) && $_GET['cat']!=='' ? (int)$_GET['cat'] : null;
$photosFilter = $_GET['photos'] ?? 'any'; // any | yes | no
$incompleteOnly = isset($_GET['incomplete']) && $_GET['incomplete']=='1';

// Determine incomplete criteria for a row
function av_is_incomplete_row(array $row, array $photoCount, array $selfValue): bool {
  $id = (int)$row['id'];
  $catName = strtolower($row['category'] ?? '');
  $hasPhotos = ($photoCount[$id] ?? 0) > 0;
  $hasValue = isset($selfValue[$id]);
  $needsMakeModel = !in_array($catName, ['home','house','property','residence']);
  $missingMakeModel = $needsMakeModel && (trim((string)($row['make'] ?? ''))==='' && trim((string)($row['model'] ?? ''))==='');
  $needsYear = in_array($catName, ['vehicle','car','truck','auto','boat']);
  $missingYear = $needsYear && empty($row['year']);
  return (!$hasPhotos) || (!$hasValue) || $missingMakeModel || $missingYear;
}

// Compute totals recursively with memoization
$memoTotals = [];
function totalsFor($id, $byParent, $selfValue, &$memoTotals){
  if (isset($memoTotals[$id])) return $memoTotals[$id];
  $self = $selfValue[$id] ?? 0.0;
  $children = $byParent[$id] ?? [];
  $contents = 0.0;
  foreach ($children as $c){
    $t = totalsFor($c['id'], $byParent, $selfValue, $memoTotals);
    $contents += $t['total'];
  }
  $memoTotals[$id] = ['self'=>$self, 'contents'=>$contents, 'total'=>$self+$contents];
  return $memoTotals[$id];
}

// Flatten tree to rows with depth for indentation
$flat = [];
function flattenRows($parentId, $byParent, $depth, &$flat){
  if (empty($byParent[$parentId])) return;
  foreach ($byParent[$parentId] as $a){
    $a['depth'] = $depth;
    $flat[] = $a;
    flattenRows($a['id'], $byParent, $depth+1, $flat);
  }
}
flattenRows(0, $byParent, 0, $flat);
?>

<div class="card">
  <div class="section-head" style="margin-bottom:8px">
    <h1>Assets</h1>
    <a class="btn sm" href="<?= Util::baseUrl('index.php?page=asset_edit') ?>">Add Asset</a>
  </div>
  <form method="get" id="assetsFilter" class="row" style="margin-bottom:8px">
    <input type="hidden" name="page" value="assets">
    <div class="col-4">
      <label>Search</label>
      <input id="assets_q" name="q" value="<?= Util::h($q) ?>" placeholder="Name contains…">
    </div>
    <div class="col-3">
      <label>Category</label>
      <select name="cat">
        <option value="">All</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= ($catFilter===$c['id'])?'selected':'' ?>><?= Util::h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-3">
      <label>Photos</label>
      <?php $opts = ['any'=>'Any','yes'=>'Has Photos','no'=>'No Photos']; ?>
      <select name="photos">
        <?php foreach ($opts as $k=>$v): ?>
          <option value="<?= $k ?>" <?= ($photosFilter===$k)?'selected':'' ?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-2">
      <label>&nbsp;</label>
      <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="incomplete" value="1" <?= $incompleteOnly?'checked':'' ?>> Incomplete only</label>
    </div>
    <div class="col-12 actions"><a class="btn sm ghost" href="<?= Util::baseUrl('index.php?page=assets') ?>">Reset</a></div>
  </form>
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Asset</th>
        <th>Category</th>
        <th>Location</th>
        <th>Files</th>
        <th>Value</th>
        <th>Contents</th>
        <th>Total</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($flat as $row): $t = totalsFor($row['id'], $byParent, $selfValue, $memoTotals);
        // Apply filters
        if ($q !== '' && stripos($row['name'], $q) === false) continue;
        if ($catFilter !== null && (int)($row['category_id'] ?? 0) !== $catFilter) continue;
        $pc = $photoCount[(int)$row['id']] ?? 0;
        if ($photosFilter === 'yes' && $pc <= 0) continue;
        if ($photosFilter === 'no' && $pc > 0) continue;
        if ($incompleteOnly && !av_is_incomplete_row($row, $photoCount, $selfValue)) continue;
      ?>
        <tr>
          <td>
            <div style="padding-left: <?= (int)$row['depth']*16 ?>px">
              <?php if (!empty($row['public_token'])): $viewUrl = Util::baseUrl('index.php?page=asset_view&code='.$row['public_token']); ?>
                <a href="<?= $viewUrl ?>" target="_blank"><?= Util::h($row['name']) ?></a>
              <?php else: ?>
                <?= Util::h($row['name']) ?>
              <?php endif; ?>
            </div>
          </td>
          <td><?= Util::h($row['category']) ?></td>
          <td><?= $row['locname'] ? Util::h($row['locname']) : '-' ?></td>
          <td>
            <?php if (($photoCount[(int)$row['id']] ?? 0) > 0): ?>
              <span class="pill primary"><?= (int)$photoCount[(int)$row['id']] ?></span>
            <?php else: ?>
              <span class="pill danger">0</span>
            <?php endif; ?>
          </td>
          <td>$<?= number_format($t['self'], 2) ?></td>
          <td>$<?= number_format($t['contents'], 2) ?></td>
          <td><strong>$<?= number_format($t['total'], 2) ?></strong></td>
          <td class="actions">
            <a class="btn sm ghost" title="Edit" href="<?= Util::baseUrl('index.php?page=asset_edit&id='.(int)$row['id']) ?>">✏️</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
