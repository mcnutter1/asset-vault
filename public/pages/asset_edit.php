<?php
$pdo = Database::get();
$cfg = Util::config();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

// Fetch categories and potential parents
$cats = $pdo->query('SELECT id, name FROM asset_categories ORDER BY name')->fetchAll();
$parents = $pdo->query('SELECT id, name FROM assets WHERE is_deleted=0 ORDER BY name')->fetchAll();

// Save asset
if (($_POST['action'] ?? '') === 'save') {
  Util::checkCsrf();
  $name = trim($_POST['name'] ?? '');
  $category_id = $_POST['category_id'] ? (int)$_POST['category_id'] : null;
  $parent_id = $_POST['parent_id'] ? (int)$_POST['parent_id'] : null;
  $description = trim($_POST['description'] ?? '');
  $location = ''; // deprecated free-text location removed from UI
  $make = trim($_POST['make'] ?? '');
  $model = trim($_POST['model'] ?? '');
  $serial_number = trim($_POST['serial_number'] ?? '');
  $year = $_POST['year'] !== '' ? (int)$_POST['year'] : null;
  $purchase_date = $_POST['purchase_date'] ?: null;
  $notes = trim($_POST['notes'] ?? '');

  if ($isEdit) {
    $asset_location_id = isset($_POST['asset_location_id']) && $_POST['asset_location_id']!=='' ? (int)$_POST['asset_location_id'] : null;
    $stmt = $pdo->prepare('UPDATE assets SET name=?, category_id=?, parent_id=?, description=?, location=?, make=?, model=?, serial_number=?, year=?, odometer_miles=?, hours_used=?, purchase_date=?, notes=?, asset_location_id=? WHERE id=?');
    $stmt->execute([$name, $category_id, $parent_id, $description, $location, $make, $model, $serial_number, $year, $odometer_miles, $hours_used, $purchase_date, $notes, $asset_location_id, $id]);
    $pdo->prepare('INSERT INTO audit_log(entity_type, entity_id, action, details) VALUES ("asset", ?, "update", NULL)')->execute([$id]);
  } else {
    $asset_location_id = isset($_POST['asset_location_id']) && $_POST['asset_location_id']!=='' ? (int)$_POST['asset_location_id'] : null;
    // generate public token
    $token = bin2hex(random_bytes(12));
    $stmt = $pdo->prepare('INSERT INTO assets(name, category_id, parent_id, description, location, make, model, serial_number, year, odometer_miles, hours_used, purchase_date, notes, asset_location_id, public_token) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$name, $category_id, $parent_id, $description, $location, $make, $model, $serial_number, $year, $odometer_miles, $hours_used, $purchase_date, $notes, $asset_location_id, $token]);
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

  // Handle photo uploads (store in DB)
  if (!empty($_FILES['photos']['name'][0])) {
    for ($i=0; $i<count($_FILES['photos']['name']); $i++) {
      if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['photos']['tmp_name'][$i];
        $orig = $_FILES['photos']['name'][$i];
        $mime = $_FILES['photos']['type'][$i] ?: 'application/octet-stream';
        $size = (int)$_FILES['photos']['size'][$i];
        $content = file_get_contents($tmp);
        $stmt = $pdo->prepare("INSERT INTO files(entity_type, entity_id, filename, mime_type, size, content) VALUES ('asset', ?, ?, ?, ?, ?)");
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->bindValue(2, $orig, PDO::PARAM_STR);
        $stmt->bindValue(3, $mime, PDO::PARAM_STR);
        $stmt->bindValue(4, $size, PDO::PARAM_INT);
        $stmt->bindParam(5, $content, PDO::PARAM_LOB);
        $stmt->execute();
      }
    }
  }

  // Handle saving address if visible
  if (!empty($_POST['addr_line1']) || !empty($_POST['addr_city'])) {
    // Determine addrType again (based on chosen category_id)
    $catName = '';
    if (!empty($category_id)) {
      $stmt = $pdo->prepare('SELECT name FROM asset_categories WHERE id=?');
      $stmt->execute([$category_id]);
      $cn = $stmt->fetchColumn();
      $catName = strtolower($cn ?: '');
    }
    $addrType = (in_array($catName, ['home','house','property'])) ? 'physical' : ((in_array($catName, ['vehicle','car','boat'])) ? 'storage' : '');
    if ($addrType) {
      $line1 = trim($_POST['addr_line1'] ?? '');
      $line2 = trim($_POST['addr_line2'] ?? '');
      $city = trim($_POST['addr_city'] ?? '');
      $state = trim($_POST['addr_state'] ?? '');
      $postal = trim($_POST['addr_postal'] ?? '');
      $country = trim($_POST['addr_country'] ?? '');
      // Upsert via delete+insert for simplicity
      $pdo->prepare('DELETE FROM asset_addresses WHERE asset_id=? AND address_type=?')->execute([$id, $addrType]);
      if ($line1 || $city) {
        $stmt = $pdo->prepare('INSERT INTO asset_addresses(asset_id, address_type, line1, line2, city, state, postal_code, country) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$id, $addrType, $line1, $line2, $city, $state, $postal, $country]);
      }
    }
  }

  Util::redirect('index.php?page=asset_edit&id='.$id);
}

// Fetch existing asset
$asset = [
  'name'=>'','category_id'=>'','parent_id'=>'','description'=>'','location'=>'','make'=>'','model'=>'','serial_number'=>'','year'=>'','purchase_date'=>'','notes'=>'','asset_location_id'=>'','public_token'=>''
];
if ($isEdit) {
  $stmt = $pdo->prepare('SELECT * FROM assets WHERE id=?');
  $stmt->execute([$id]);
  $asset = $stmt->fetch() ?: $asset;
}
// After we know $asset, load parent-defined locations (optional)
$parentLocations = [];
$formParentId = null;
if ($isEdit) { $formParentId = $asset['parent_id'] ?? null; }
if ($formParentId === null && isset($_POST['parent_id']) && $_POST['parent_id']!=='') { $formParentId = (int)$_POST['parent_id']; }
if ($formParentId) {
  $stmt = $pdo->prepare('SELECT id, name FROM asset_locations WHERE asset_id=? ORDER BY name');
  $stmt->execute([$formParentId]);
  $parentLocations = $stmt->fetchAll();
}
// Build map of all parent asset -> locations for client-side switch (optional)
$allParentLocs = $pdo->query('SELECT asset_id, id, name FROM asset_locations ORDER BY name')->fetchAll();
$locMap = [];
foreach ($allParentLocs as $pl){ $aid = (int)$pl['asset_id']; if (!isset($locMap[$aid])) $locMap[$aid] = []; $locMap[$aid][] = ['id'=>(int)$pl['id'], 'name'=>$pl['name']]; }

// ensure public_token exists for existing records
if ($isEdit && empty($asset['public_token'])) {
  $tok = bin2hex(random_bytes(12));
  $pdo->prepare('UPDATE assets SET public_token=? WHERE id=?')->execute([$tok, $id]);
  $asset['public_token'] = $tok;
}
// Values history
$values = [];
if ($isEdit) {
  $stmt = $pdo->prepare('SELECT value_type, amount, valuation_date FROM asset_values WHERE asset_id=? ORDER BY valuation_date ASC');
  $stmt->execute([$id]);
  $values = $stmt->fetchAll();
}
// Latest current value for header
$currentValue = null;
if ($isEdit) {
  $stmt = $pdo->prepare("SELECT amount FROM asset_values WHERE asset_id=? AND value_type='current' ORDER BY valuation_date DESC LIMIT 1");
  $stmt->execute([$id]);
  $cv = $stmt->fetchColumn();
  if ($cv !== false) $currentValue = (float)$cv;
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
// Files (images) for this asset from DB
$photos = [];
if ($isEdit) {
  $stmt = $pdo->prepare("SELECT id, filename, mime_type, size, caption, uploaded_at FROM files WHERE entity_type='asset' AND entity_id=? AND mime_type LIKE 'image/%' ORDER BY uploaded_at DESC");
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

<?php if ($isEdit): ?>
<div class="page-header">
  <div class="header-card">
    <div class="header-left">
      <div class="header-title"><?= Util::h($asset['name']) ?></div>
      <div class="header-meta">
        <?php $publicUrl = Util::baseUrl('index.php?page=asset_view&code='.$asset['public_token']); ?>
        <a class="btn ghost" href="<?= $publicUrl ?>" target="_blank">Open Public View</a>
        <span class="value-pill">Current: <?= $currentValue!==null? ('$'.number_format($currentValue,2)) : '—' ?></span>
      </div>
      <div class="small muted">Public URL: <a href="<?= $publicUrl ?>" target="_blank"><?= $publicUrl ?></a></div>
    </div>
    <div class="header-right">
      <img alt="QR" style="height:110px;border:1px solid var(--border);border-radius:10px;background:#fff" src="https://chart.googleapis.com/chart?cht=qr&chs=180x180&chl=<?= urlencode($publicUrl) ?>&choe=UTF-8">
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row">
  <div class="col-8">
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
    <h1><?= $isEdit ? 'Edit Asset' : 'Add Asset' ?></h1>
    <a class="btn ghost" href="<?= Util::baseUrl('index.php?page=assets') ?>">Back</a>
  </div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
    <input type="hidden" name="action" value="save">
  <div class="row">
      <?php // Determine category early for conditional fields ?>
      <?php $catNameEarly = ''; if (!empty($asset['category_id'])) { foreach ($cats as $c) if ($c['id']==$asset['category_id']) { $catNameEarly = strtolower($c['name']); break; } } ?>
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
        <select name="parent_id" id="parent_id">
          <option value="">--</option>
          <?php foreach ($parents as $p): if ($isEdit && $p['id']==$id) continue; ?>
            <option value="<?= (int)$p['id'] ?>" <?= $asset['parent_id']==$p['id']?'selected':'' ?>><?= Util::h($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-3">
        <label>Location (from Parent)</label>
        <?php if ($formParentId && $parentLocations): ?>
          <select name="asset_location_id" id="asset_location_id">
            <option value="">--</option>
            <?php foreach ($parentLocations as $loc): ?>
              <option value="<?= (int)$loc['id'] ?>" <?= $asset['asset_location_id']==$loc['id']?'selected':'' ?>><?= Util::h($loc['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="small muted">Locations defined on the parent asset.</div>
        <?php elseif (!$formParentId): ?>
          <div class="small muted">Select a Parent Asset to choose a location (optional).</div>
        <?php else: ?>
          <select name="asset_location_id" id="asset_location_id">
            <option value="">--</option>
          </select>
          <div class="small muted">No locations defined on the parent asset (optional).</div>
        <?php endif; ?>
      </div>
      <div class="col-12">
        <label>Description</label>
        <textarea name="description" rows="3"><?= Util::h($asset['description']) ?></textarea>
      </div>
      
      <div class="col-4"><label>Make</label><input name="make" value="<?= Util::h($asset['make']) ?>"></div>
      <div class="col-4"><label>Model</label><input name="model" value="<?= Util::h($asset['model']) ?>"></div>
      <div class="col-4"><label>Serial #</label><input name="serial_number" value="<?= Util::h($asset['serial_number']) ?>"></div>
      <div class="col-4"><label>Year</label><input type="number" min="1900" max="2100" name="year" value="<?= Util::h($asset['year']) ?>"></div>
      <?php if (in_array($catNameEarly, ['vehicle','car','truck','auto','suv'])): ?>
        <div class="col-4"><label>Odometer (miles)</label><input type="number" step="1" min="0" name="odometer_miles" value="<?= Util::h($asset['odometer_miles'] ?? '') ?>"></div>
        <div class="col-4"><label>Hours Used</label><input type="number" step="1" min="0" name="hours_used" value="<?= Util::h($asset['hours_used'] ?? '') ?>"></div>
      <?php endif; ?>
      <?php if ($catNameEarly === 'boat'): ?>
        <div class="col-4"><label>Hours Used</label><input type="number" step="1" min="0" name="hours_used" value="<?= Util::h($asset['hours_used'] ?? '') ?>"></div>
      <?php endif; ?>
      <div class="col-4"><label>Purchase Date</label><input type="date" name="purchase_date" value="<?= Util::h($asset['purchase_date']) ?>"></div>
      <div class="col-12"><label>Notes</label><textarea name="notes" rows="2"><?= Util::h($asset['notes']) ?></textarea></div>

      <?php
        // Determine category name to conditionally show address
        $catName = '';
        if (!empty($asset['category_id'])) {
          foreach ($cats as $c) if ($c['id'] == $asset['category_id']) { $catName = strtolower($c['name']); break; }
        }
        $addrType = (in_array($catName, ['home','house','property'])) ? 'physical' : ((in_array($catName, ['vehicle','car','boat'])) ? 'storage' : '');
        $addr = null;
        if ($isEdit && $addrType) {
          $stmt = $pdo->prepare('SELECT * FROM asset_addresses WHERE asset_id=? AND address_type=?');
          $stmt->execute([$id, $addrType]);
          $addr = $stmt->fetch();
        }
      ?>
      <?php if ($addrType): ?>
        <div class="col-12">
          <h2><?= ucfirst($addrType) ?> Address</h2>
        </div>
        <?php if (in_array($catName, ['home','house','property'])): ?>
          <div class="col-6"><label>Address Line 1</label><input name="addr_line1" value="<?= Util::h($addr['line1'] ?? '') ?>"></div>
          <div class="col-6"><label>Address Line 2</label><input name="addr_line2" value="<?= Util::h($addr['line2'] ?? '') ?>"></div>
          <div class="col-4"><label>City</label><input name="addr_city" value="<?= Util::h($addr['city'] ?? '') ?>"></div>
          <div class="col-4"><label>State</label><input name="addr_state" value="<?= Util::h($addr['state'] ?? '') ?>"></div>
          <div class="col-4"><label>Postal Code</label><input name="addr_postal" value="<?= Util::h($addr['postal_code'] ?? '') ?>"></div>
          <div class="col-4"><label>Country</label><input name="addr_country" value="<?= Util::h($addr['country'] ?? '') ?>"></div>
        <?php else: ?>
          <?php $savedAddrs = $pdo->query('SELECT * FROM saved_addresses ORDER BY name')->fetchAll(); ?>
          <div class="col-12">
            <label>Select Saved Address</label>
            <select id="saved_address_id" name="saved_address_id">
              <option value="">--</option>
              <?php foreach ($savedAddrs as $sa): ?>
                <option value="<?= (int)$sa['id'] ?>"><?= Util::h($sa['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="small muted">Storage addresses must be selected from saved addresses (Settings → Addresses).</div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="col-12 section-head">
        <h2>Valuations</h2>
        <?php if ($isEdit): ?>
          <button class="btn outline" id="aiBtn" type="button">AI Estimate</button>
        <?php endif; ?>
      </div>
      <div class="col-12">
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
      </div>
      <?php if ($values): ?>
        <div class="col-12">
          <div class="small" style="margin-top:8px">History (most recent last)</div>
          <table>
            <thead><tr><th>Type</th><th>Amount</th><th>Date</th></tr></thead>
            <tbody>
              <?php foreach ($values as $v): ?>
                <tr><td><?= Util::h($v['value_type']) ?></td><td>$<?= number_format($v['amount'],2) ?></td><td><?= Util::h($v['valuation_date']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (!empty($seriesCurrent)): ?>
          <div class="col-12">
            <div class="small" style="margin-top:8px">Current Value Trend</div>
            <div style="width:100%;height:160px">
              <canvas data-autodraw data-series='<?= Util::h(json_encode($seriesCurrent)) ?>' style="width:100%;height:160px"></canvas>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="col-12">
        <h2>Photos</h2>
        <input type="file" name="photos[]" accept="image/*" capture="environment" multiple>
        <?php if ($photos): ?>
          <div class="gallery" style="margin-top:8px">
            <?php foreach ($photos as $ph): ?>
              <img src="<?= Util::baseUrl('file.php?id='.(int)$ph['id']) ?>" alt="<?= Util::h($ph['filename']) ?>">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

<?php if ($isEdit): ?>
<!-- AI Modal -->
<div class="modal-backdrop" id="aiModal">
  <div class="modal" style="width:min(760px,92vw)">
    <div class="head"><strong>AI Valuation</strong><button class="x" data-modal-close="aiModal">✕</button></div>
    <div class="body">
      <div class="row" style="margin-bottom:8px">
        <div class="col-12 actions"><button class="btn" id="aiRun" type="button">Estimate</button></div>
      </div>
      <div id="aiLoading" style="display:none;align-items:center;gap:8px"><div class="spinner"></div><div>Fetching sources and contacting AI…</div></div>
      <div id="aiResult" style="display:none">
        <div class="row">
          <div class="col-12"><h3>Parsed Facts</h3></div>
          <div class="col-6"><label>Zestimate</label><input id="fact_zestimate" readonly></div>
          <div class="col-6"><label>Square Feet</label><input id="fact_sqft" readonly></div>
          <div class="col-4"><label>Beds</label><input id="fact_beds" readonly></div>
          <div class="col-4"><label>Baths</label><input id="fact_baths" readonly></div>
          <div class="col-4"><label>Year Built</label><input id="fact_year" readonly></div>
          <div class="col-12"><label>Zillow Link</label><input id="fact_url" readonly></div>
        </div>
        <div class="row">
          <div class="col-6"><label>Market Value (USD)</label><input id="ai_market" type="number" step="0.01"></div>
          <div class="col-6"><label>Replacement Cost (USD)</label><input id="ai_replace" type="number" step="0.01"></div>
          <div class="col-12"><label>Confidence</label><input id="ai_confidence" readonly></div>
          <div class="col-12"><label>Assumptions</label><textarea id="ai_assumptions" rows="3" readonly></textarea></div>
          <div class="col-12"><label>Sources</label><input id="ai_sources" readonly></div>
        </div>
      </div>
      <div id="aiNotice" class="small muted" style="display:none"></div>
      <div id="aiError" class="small muted" style="display:none;color:#dc2626"></div>
    </div>
    <div class="foot">
      <button class="btn ghost" data-modal-close="aiModal">Close</button>
      <button class="btn" id="aiApply" style="display:none">Apply to Asset</button>
    </div>
  </div>
</div>
<?php endif; ?>

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
<script>
  (function(){
    var map = <?= json_encode($locMap) ?>;
    var parentSel = document.getElementById('parent_id');
    var locSel = document.getElementById('asset_location_id');
    if (parentSel && locSel) {
      parentSel.addEventListener('change', function(){
        var pid = this.value ? parseInt(this.value, 10) : 0;
        // reset options
        while (locSel.firstChild) locSel.removeChild(locSel.firstChild);
        var opt = document.createElement('option'); opt.value=''; opt.textContent='--'; locSel.appendChild(opt);
        if (pid && map[pid]) {
          map[pid].forEach(function(l){ var o = document.createElement('option'); o.value = l.id; o.textContent = l.name; locSel.appendChild(o); });
        }
      });
    }
  })();
  // AI handler
  (function(){
    var aiBtn = document.getElementById('aiBtn');
    if (!aiBtn) return;
    aiBtn.addEventListener('click', function(){
      var modal = document.getElementById('aiModal');
      if (modal) modal.classList.add('show');
      // reset state
      document.getElementById('aiLoading').style.display='none';
      document.getElementById('aiResult').style.display='none';
      document.getElementById('aiError').style.display='none';
      document.getElementById('aiApply').style.display='none';
      var run = document.getElementById('aiRun');
      run.onclick = function(){
        var loading = document.getElementById('aiLoading');
        var result = document.getElementById('aiResult');
        var errorEl = document.getElementById('aiError');
        var applyBtn = document.getElementById('aiApply');
        loading.style.display='flex'; result.style.display='none'; errorEl.style.display='none'; applyBtn.style.display='none';
        fetch('<?= Util::baseUrl('ai.php') ?>', {
          method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body: new URLSearchParams({ action:'estimate', asset_id:'<?= (int)$id ?>', csrf:'<?= Util::csrfToken() ?>' })
        }).then(r=>r.json()).then(data=>{
          if (!data.ok) throw new Error(data.error||'Failed');
          var facts = data.data.facts || {};
          document.getElementById('fact_zestimate').value = (facts.zestimate_usd? ('$'+Number(facts.zestimate_usd).toLocaleString()):'');
          document.getElementById('fact_sqft').value = facts.sq_ft? Number(facts.sq_ft).toLocaleString():'';
          document.getElementById('fact_beds').value = facts.beds??'';
          document.getElementById('fact_baths').value = facts.baths??'';
          document.getElementById('fact_year').value = facts.year_built??'';
          document.getElementById('fact_url').value = facts.zillow_url??'';
          var val = data.data.valuation || {};
          document.getElementById('ai_market').value = val.market_value_usd ?? '';
          document.getElementById('ai_replace').value = val.replacement_cost_usd ?? '';
          document.getElementById('ai_confidence').value = val.confidence ?? '';
          document.getElementById('ai_assumptions').value = val.assumptions ?? '';
          document.getElementById('ai_sources').value = (val.sources||[]).join(', ');
          if (data.data.notice === 'facts_missing') {
            var n = document.getElementById('aiNotice');
            n.textContent = 'No authoritative facts were found; showing AI estimation based on the exact address.';
            n.style.display='block';
          }
          loading.style.display='none'; result.style.display='block'; applyBtn.style.display='inline-flex';
          applyBtn.onclick = function(){
            var mv = document.getElementById('ai_market').value;
            var rc = document.getElementById('ai_replace').value;
            fetch('<?= Util::baseUrl('ai.php') ?>', {
              method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
              body: new URLSearchParams({ action:'apply', asset_id:'<?= (int)$id ?>', csrf:'<?= Util::csrfToken() ?>', market_value_usd: mv, replacement_cost_usd: rc })
            }).then(r=>r.json()).then(d=>{
              if (!d.ok) throw new Error(d.error||'Failed to apply');
              location.reload();
            }).catch(e=>{ errorEl.textContent = e.message; errorEl.style.display='block'; });
          }
        }).catch(e=>{ document.getElementById('aiLoading').style.display='none'; document.getElementById('aiError').textContent = e.message; document.getElementById('aiError').style.display='block'; });
      };
    });
  })();
 </script>
  </div>
  </div>
  <div class="col-4">
    <div class="card settings sticky">
      <h1>Configuration</h1>
      <?php if ($isEdit): ?>
      <div style="margin-top:8px">
        <h2>Locations for Contents</h2>
        <?php
          // Manage locations owned by this asset
          if (($_POST['action'] ?? '') === 'add_loc') {
            Util::checkCsrf();
            $n = trim($_POST['loc_name'] ?? '');
            $d = trim($_POST['loc_desc'] ?? '');
            if ($n !== '') {
              $stmt = $pdo->prepare('INSERT INTO asset_locations(asset_id, name, description) VALUES (?,?,?)');
              $stmt->execute([$id, $n, $d]);
              Util::redirect('index.php?page=asset_edit&id='.$id);
            }
          }
          if (($_POST['action'] ?? '') === 'del_loc') {
            Util::checkCsrf();
            $lid = (int)($_POST['loc_id'] ?? 0);
            // unlink children using this location
            $pdo->prepare('UPDATE assets SET asset_location_id=NULL WHERE parent_id=? AND asset_location_id=?')->execute([$id, $lid]);
            $pdo->prepare('DELETE FROM asset_locations WHERE id=? AND asset_id=?')->execute([$lid, $id]);
            Util::redirect('index.php?page=asset_edit&id='.$id);
          }
          $ownedLocs = $pdo->prepare('SELECT * FROM asset_locations WHERE asset_id=? ORDER BY name');
          $ownedLocs->execute([$id]);
          $ownedLocs = $ownedLocs->fetchAll();
        ?>
        <?php if (!$ownedLocs): ?>
          <div class="small muted">No locations defined.</div>
        <?php else: ?>
          <div class="label-list" style="margin-bottom:8px">
            <?php foreach ($ownedLocs as $ol): ?>
              <span class="label">
                <?= Util::h($ol['name']) ?>
                <form method="post" style="display:inline" onsubmit="return confirmAction('Delete location?')">
                  <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                  <input type="hidden" name="action" value="del_loc">
                  <input type="hidden" name="loc_id" value="<?= (int)$ol['id'] ?>">
                  <button class="x" title="Remove">×</button>
                </form>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <button class="btn" data-modal-open="locModal">Add Location</button>
      </div>
      <?php else: ?>
        <div class="small muted">Save the asset first to configure locations and public link.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($isEdit): ?>
<!-- Add Location Modal -->
<div class="modal-backdrop" id="locModal">
  <div class="modal">
    <div class="head"><strong>Add Location</strong><button class="x" data-modal-close="locModal">✕</button></div>
    <div class="body">
      <form method="post" id="locForm">
        <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
        <input type="hidden" name="action" value="add_loc">
        <div class="row">
          <div class="col-12"><label>Name</label><input name="loc_name" required></div>
          <div class="col-12"><label>Description</label><input name="loc_desc"></div>
        </div>
      </form>
    </div>
    <div class="foot">
      <button class="btn ghost" data-modal-close="locModal">Cancel</button>
      <button class="btn" onclick="document.getElementById('locForm').submit()">Add</button>
    </div>
  </div>
</div>
<?php endif; ?>
