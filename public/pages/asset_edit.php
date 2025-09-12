<?php
$pdo = Database::get();
$cfg = Util::config();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

// Fetch categories and parents
$cats = $pdo->query('SELECT id, name FROM asset_categories ORDER BY name')->fetchAll();
$parents = $pdo->query('SELECT id, name FROM assets WHERE is_deleted=0 ORDER BY name')->fetchAll();

// Save asset
if (($_POST['action'] ?? '') === 'save') {
  Util::checkCsrf();
  $name = trim($_POST['name'] ?? '');
  $category_id = $_POST['category_id'] ? (int)$_POST['category_id'] : null;
  $parent_id = $_POST['parent_id'] ? (int)$_POST['parent_id'] : null;
  $description = trim($_POST['description'] ?? '');
  $location = trim($_POST['location'] ?? '');
  $make = trim($_POST['make'] ?? '');
  $model = trim($_POST['model'] ?? '');
  $serial_number = trim($_POST['serial_number'] ?? '');
  $year = $_POST['year'] !== '' ? (int)$_POST['year'] : null;
  $purchase_date = $_POST['purchase_date'] ?: null;
  $notes = trim($_POST['notes'] ?? '');

  if ($isEdit) {
    $stmt = $pdo->prepare('UPDATE assets SET name=?, category_id=?, parent_id=?, description=?, location=?, make=?, model=?, serial_number=?, year=?, purchase_date=?, notes=? WHERE id=?');
    $stmt->execute([$name, $category_id, $parent_id, $description, $location, $make, $model, $serial_number, $year, $purchase_date, $notes, $id]);
    $pdo->prepare('INSERT INTO audit_log(entity_type, entity_id, action, details) VALUES ("asset", ?, "update", NULL)')->execute([$id]);
  } else {
    $stmt = $pdo->prepare('INSERT INTO assets(name, category_id, parent_id, description, location, make, model, serial_number, year, purchase_date, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$name, $category_id, $parent_id, $description, $location, $make, $model, $serial_number, $year, $purchase_date, $notes]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO audit_log(entity_type, entity_id, action, details) VALUES ("asset", ?, "create", NULL)')->execute([$id]);
    $isEdit = true;
  }

  // Handle valuation entries
  $valTypes = ['purchase','current','replace'];
  foreach ($valTypes as $vt) {
    $amount = $_POST[$vt.'_amount'] ?? '';
    $dateField = ($vt === 'purchase') ? 'purchase_val_date' : ($vt.'_date');
    $date = $_POST[$dateField] ?? '';
    if ($amount !== '' && $date !== '') {
      $stmt = $pdo->prepare('INSERT INTO asset_values(asset_id, value_type, amount, valuation_date, source) VALUES (?,?,?,?,?)');
      $stmt->execute([$id, $vt, (float)$amount, $date, 'manual']);
    }
  }

  // Handle photo uploads
  if (!empty($_FILES['photos']['name'][0])) {
    Util::ensureUploadsDir();
    $dir = rtrim($cfg['app']['uploads_dir'], '/');
    for ($i=0; $i<count($_FILES['photos']['name']); $i++) {
      if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['photos']['tmp_name'][$i];
        $nameSafe = preg_replace('/[^a-zA-Z0-9._-]/','_', $_FILES['photos']['name'][$i]);
        $destRel = date('Ymd_His').'_'.bin2hex(random_bytes(4)).'_'.$nameSafe;
        $dest = $dir.'/'.$destRel;
        if (@move_uploaded_file($tmp, $dest)) {
          $stmt = $pdo->prepare('INSERT INTO asset_photos(asset_id, filepath) VALUES (?, ?)');
          $stmt->execute([$id, $destRel]);
        }
      }
    }
  }

  Util::redirect('index.php?page=asset_edit&id='.$id);
}

// Fetch existing asset
$asset = [
  'name'=>'','category_id'=>'','parent_id'=>'','description'=>'','location'=>'','make'=>'','model'=>'','serial_number'=>'','year'=>'','purchase_date'=>'','notes'=>''
];
if ($isEdit) {
  $stmt = $pdo->prepare('SELECT * FROM assets WHERE id=?');
  $stmt->execute([$id]);
  $asset = $stmt->fetch() ?: $asset;
}
// Values history
$values = [];
if ($isEdit) {
  $stmt = $pdo->prepare('SELECT value_type, amount, valuation_date FROM asset_values WHERE asset_id=? ORDER BY valuation_date ASC');
  $stmt->execute([$id]);
  $values = $stmt->fetchAll();
}
// Prepare current value series
$seriesCurrent = [];
if ($values) {
  foreach ($values as $v) {
    if ($v['value_type'] === 'current') {
      $seriesCurrent[] = ['x' => $v['valuation_date'], 'y' => (float)$v['amount']];
    }
  }
}
// Photos
$photos = [];
if ($isEdit) {
  $stmt = $pdo->prepare('SELECT * FROM asset_photos WHERE asset_id=? ORDER BY uploaded_at DESC');
  $stmt->execute([$id]);
  $photos = $stmt->fetchAll();
}
// Linked policies (direct)
$policies = [];
if ($isEdit) {
  $stmt = $pdo->prepare('SELECT p.* FROM policy_assets pa JOIN policies p ON p.id=pa.policy_id WHERE pa.asset_id=? ORDER BY p.end_date DESC');
  $stmt->execute([$id]);
  $policies = $stmt->fetchAll();
}

// Inherited policies from ancestors (applies_to_children=1)
$inheritedPolicies = [];
if ($isEdit) {
  $ancestors = [];
  $cur = $asset['parent_id'] ?? null;
  while ($cur) {
    $stmt = $pdo->prepare('SELECT id, parent_id FROM assets WHERE id=?');
    $stmt->execute([$cur]);
    $row = $stmt->fetch();
    if (!$row) break;
    $ancestors[] = (int)$row['id'];
    $cur = $row['parent_id'];
  }
  if ($ancestors) {
    $inClause = implode(',', array_map('intval', $ancestors));
    $inheritedPolicies = $pdo->query('SELECT DISTINCT p.* FROM policy_assets pa JOIN policies p ON p.id=pa.policy_id WHERE pa.asset_id IN ('.$inClause.') AND pa.applies_to_children=1 ORDER BY p.end_date DESC')->fetchAll();
  }
}

?>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
    <h1><?= $isEdit ? 'Edit Asset' : 'Add Asset' ?></h1>
    <a class="btn ghost" href="<?= Util::baseUrl('index.php?page=assets') ?>">Back</a>
  </div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
    <input type="hidden" name="action" value="save">
    <div class="row">
      <div class="col-6">
        <label>Name</label>
        <input name="name" required value="<?= Util::h($asset['name']) ?>">
      </div>
      <div class="col-3">
        <label>Category</label>
        <select name="category_id">
          <option value="">--</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $asset['category_id']==$c['id']?'selected':'' ?>><?= Util::h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-3">
        <label>Parent Asset</label>
        <select name="parent_id">
          <option value="">--</option>
          <?php foreach ($parents as $p): if ($isEdit && $p['id']==$id) continue; ?>
            <option value="<?= (int)$p['id'] ?>" <?= $asset['parent_id']==$p['id']?'selected':'' ?>><?= Util::h($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label>Description</label>
        <textarea name="description" rows="3"><?= Util::h($asset['description']) ?></textarea>
      </div>
      <div class="col-4"><label>Location</label><input name="location" value="<?= Util::h($asset['location']) ?>"></div>
      <div class="col-4"><label>Make</label><input name="make" value="<?= Util::h($asset['make']) ?>"></div>
      <div class="col-4"><label>Model</label><input name="model" value="<?= Util::h($asset['model']) ?>"></div>
      <div class="col-4"><label>Serial #</label><input name="serial_number" value="<?= Util::h($asset['serial_number']) ?>"></div>
      <div class="col-4"><label>Year</label><input type="number" min="1900" max="2100" name="year" value="<?= Util::h($asset['year']) ?>"></div>
      <div class="col-4"><label>Purchase Date</label><input type="date" name="purchase_date" value="<?= Util::h($asset['purchase_date']) ?>"></div>
      <div class="col-12"><label>Notes</label><textarea name="notes" rows="2"><?= Util::h($asset['notes']) ?></textarea></div>

      <div class="col-12">
        <h2>Valuations</h2>
        <div class="input-row">
          <div>
            <label>Purchase Amount</label>
            <input type="number" step="0.01" name="purchase_amount" placeholder="e.g. 1500.00">
            <label class="small">Date</label>
            <input type="date" name="purchase_val_date">
          </div>
          <div>
            <label>Current Value</label>
            <input type="number" step="0.01" name="current_amount">
            <label class="small">As Of</label>
            <input type="date" name="current_date">
          </div>
          <div>
            <label>Replacement Cost</label>
            <input type="number" step="0.01" name="replace_amount">
            <label class="small">Quoted Date</label>
            <input type="date" name="replace_date">
          </div>
        </div>
        <?php if ($values): ?>
          <div class="small" style="margin-top:8px">History (most recent last):</div>
          <table>
            <thead><tr><th>Type</th><th>Amount</th><th>Date</th></tr></thead>
            <tbody>
              <?php foreach ($values as $v): ?>
                <tr><td><?= Util::h($v['value_type']) ?></td><td>$<?= number_format($v['amount'],2) ?></td><td><?= Util::h($v['valuation_date']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if (!empty($seriesCurrent)): ?>
            <div class="small" style="margin-top:8px">Current Value Trend</div>
            <div style="width:100%;height:160px">
              <canvas data-autodraw data-series='<?= Util::h(json_encode($seriesCurrent)) ?>' style="width:100%;height:160px"></canvas>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div class="col-12">
        <h2>Photos</h2>
        <input type="file" name="photos[]" accept="image/*" capture="environment" multiple>
        <?php if ($photos): ?>
          <div class="gallery" style="margin-top:8px">
            <?php foreach ($photos as $ph): ?>
              <img src="<?= Util::baseUrl('uploads/'.$ph['filepath']) ?>" alt="">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($isEdit): ?>
      <div class="col-12">
        <h2>Linked Policies</h2>
        <?php if (!$policies): ?>
          <div class="small muted">No direct policy links. Policies can also inherit from parents.</div>
        <?php else: ?>
          <table>
            <thead><tr><th>Policy #</th><th>Insurer</th><th>Type</th><th>Start</th><th>End</th><th>Premium</th></tr></thead>
            <tbody>
              <?php foreach ($policies as $p): ?>
                <tr>
                  <td><a href="<?= Util::baseUrl('index.php?page=policy_edit&id='.(int)$p['id']) ?>"><?= Util::h($p['policy_number']) ?></a></td>
                  <td><?= Util::h($p['insurer']) ?></td>
                  <td><?= Util::h($p['policy_type']) ?></td>
                  <td><?= Util::h($p['start_date']) ?></td>
                  <td><?= Util::h($p['end_date']) ?></td>
                  <td>$<?= number_format($p['premium'],2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <?php if ($isEdit): ?>
      <div class="col-12">
        <h2>Inherited Policies</h2>
        <?php if (!$inheritedPolicies): ?>
          <div class="small muted">No inherited policies from parents.</div>
        <?php else: ?>
          <table>
            <thead><tr><th>Policy #</th><th>Insurer</th><th>Type</th><th>Start</th><th>End</th><th>Premium</th></tr></thead>
            <tbody>
              <?php foreach ($inheritedPolicies as $p): ?>
                <tr>
                  <td><a href="<?= Util::baseUrl('index.php?page=policy_edit&id='.(int)$p['id']) ?>"><?= Util::h($p['policy_number']) ?></a></td>
                  <td><?= Util::h($p['insurer']) ?></td>
                  <td><?= Util::h($p['policy_type']) ?></td>
                  <td><?= Util::h($p['start_date']) ?></td>
                  <td><?= Util::h($p['end_date']) ?></td>
                  <td>$<?= number_format($p['premium'],2) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php endif; ?>

      <div class="col-12 actions" style="margin-top:8px">
        <button class="btn" type="submit">Save</button>
        <a class="btn ghost" href="<?= Util::baseUrl('index.php?page=assets') ?>">Cancel</a>
      </div>
    </div>
  </form>
</div>
