<?php
$pdo = Database::get();
$cfg = Util::config();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

// Fetch categories and potential parents
$cats = $pdo->query('SELECT id, name FROM asset_categories ORDER BY name')->fetchAll();
$parents = $pdo->query('SELECT id, name FROM assets WHERE is_deleted=0 ORDER BY name')->fetchAll();

// Helper to normalize image uploads (HEIC->JPEG, downscale large images, fix orientation)
if (!function_exists('av_normalize_upload_image')) {
  function av_normalize_upload_image(string $tmp, string $origName, string $mime): array {
    // Detect real mime from content when possible
    if (function_exists('finfo_open')) {
      $fi = finfo_open(FILEINFO_MIME_TYPE);
      if ($fi) { $det = @finfo_file($fi, $tmp); if ($det) $mime = $det; @finfo_close($fi); }
    }
    $outMime = $mime;
    $outName = $origName;
    $content = @file_get_contents($tmp);
    if ($content === false) { return [$content, $outMime, $outName]; }

    // HEIC/HEIF -> JPEG via Imagick when available
    if (preg_match('~^image/(heic|heif)$~i', (string)$mime)) {
      if (extension_loaded('imagick')) {
        try {
          $img = new Imagick($tmp);
          if (method_exists($img, 'autoOrient')) { @$img->autoOrient(); }
          $img->setImageFormat('jpeg');
          $img->setImageCompressionQuality(85);
          $content = (string)$img->getImageBlob();
          $outMime = 'image/jpeg';
          $outName = preg_replace('/\.(heic|heif)$/i', '.jpg', $origName);
        } catch (Throwable $e) { /* keep original on failure */ }
      }
      return [$content, $outMime, $outName];
    }

    // Resize/compress with Imagick if present; otherwise attempt GD safely
    $maxDim = 2400; // pixels
    $maxProcessSize = 8 * 1024 * 1024; // only process if <= 8MB already uploaded
    if (strlen($content) <= $maxProcessSize && preg_match('~^image/(jpeg|png|webp)$~i', (string)$mime)) {
      if (extension_loaded('imagick')) {
        try {
          $im = new Imagick($tmp);
          if (method_exists($im,'autoOrient')) { @$im->autoOrient(); }
          $w=$im->getImageWidth(); $h=$im->getImageHeight();
          if (max($w,$h) > $maxDim) { $im->thumbnailImage($maxDim, $maxDim, true); }
          $im->setImageFormat('jpeg');
          $im->setImageCompressionQuality(85);
          $content2 = (string)$im->getImageBlob();
          if ($content2) { $content=$content2; $outMime='image/jpeg'; if (!preg_match('/\.(jpe?g)$/i',$outName)) { $outName=preg_replace('/\.[^.]*$/','.jpg',$outName); } }
        } catch (Throwable $e) { /* fallthrough */ }
      } else {
        $info = @getimagesize($tmp);
        if ($info && isset($info[0], $info[1])) {
          $w=(int)$info[0]; $h=(int)$info[1];
          $scale = max($w, $h) > $maxDim ? ($maxDim / max($w, $h)) : 1.0;
          $needsScale = $scale < 0.999;
          $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
          $img = null;
          if ((stripos($mime, 'jpeg') !== false || $ext === 'jpg' || $ext === 'jpeg') && function_exists('imagecreatefromjpeg')) {
            $img = @imagecreatefromjpeg($tmp);
            if ($img && function_exists('exif_read_data') && function_exists('imagerotate')) {
              $exif = @exif_read_data($tmp);
              if (!empty($exif['Orientation'])) {
                switch ((int)$exif['Orientation']) {
                  case 3: $img = @imagerotate($img, 180, 0); break;
                  case 6: $img = @imagerotate($img, -90, 0); break;
                  case 8: $img = @imagerotate($img, 90, 0); break;
                }
              }
            }
          } elseif ((stripos($mime, 'png') !== false || $ext === 'png') && function_exists('imagecreatefrompng')) {
            $img = @imagecreatefrompng($tmp);
          } elseif ((stripos($mime, 'webp') !== false || $ext === 'webp') && function_exists('imagecreatefromwebp')) {
            $img = @imagecreatefromwebp($tmp);
          }
          if ($img && function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled') && function_exists('imagejpeg')) {
            if ($needsScale) {
              $newW = (int)round($w * $scale); $newH = (int)round($h * $scale);
              $dst = imagecreatetruecolor($newW, $newH);
              imagecopyresampled($dst, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
              imagedestroy($img);
              $img = $dst;
            }
            ob_start(); @imagejpeg($img, null, 85); $content2 = ob_get_clean();
            if ($content2) { $content = $content2; $outMime='image/jpeg'; if (!preg_match('/\.(jpe?g)$/i', $outName)) { $outName = preg_replace('/\.[^.]*$/', '.jpg', $outName); } }
            imagedestroy($img);
          }
        }
      }
    }

    return [$content, $outMime, $outName];
  }
}

// Delete asset
if (($_POST['action'] ?? '') === 'delete_asset') {
  Util::checkCsrf();
  $did = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($did > 0) {
    $pdo->prepare('UPDATE assets SET is_deleted=1 WHERE id=?')->execute([$did]);
    $pdo->prepare('INSERT INTO audit_log(entity_type, entity_id, action, details) VALUES ("asset", ?, "delete", NULL)')->execute([$did]);
  }
  Util::redirect('index.php?page=assets');
}

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

  // Handle photo uploads (store in DB) with error feedback and normalization
  $uploadErrors = [];
  if (!empty($_FILES['photos']['name'][0])) {
    for ($i=0; $i<count($_FILES['photos']['name']); $i++) {
      $err = (int)($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE);
      if ($err === UPLOAD_ERR_OK) {
        $tmp = $_FILES['photos']['tmp_name'][$i];
        $orig = $_FILES['photos']['name'][$i];
        $mime = $_FILES['photos']['type'][$i] ?: 'application/octet-stream';
        // Normalize/convert if needed (HEIC->JPEG, downscale large images)
        [$content, $outMime, $outName] = av_normalize_upload_image($tmp, $orig, $mime);
        if ($content !== false && $content !== null) {
          $size = strlen($content);
          $stmt = $pdo->prepare("INSERT INTO files(entity_type, entity_id, filename, mime_type, size, content) VALUES ('asset', ?, ?, ?, ?, ?)");
          $stmt->bindValue(1, $id, PDO::PARAM_INT);
          $stmt->bindValue(2, $outName, PDO::PARAM_STR);
          $stmt->bindValue(3, $outMime, PDO::PARAM_STR);
          $stmt->bindValue(4, $size, PDO::PARAM_INT);
          $stmt->bindParam(5, $content, PDO::PARAM_LOB);
          $stmt->execute();
        } else {
          $uploadErrors[] = $orig . ' could not be read.';
        }
      } else if ($err !== UPLOAD_ERR_NO_FILE) {
        // Collect user-friendly error
        $msg = $_FILES['photos']['name'][$i] . ': ';
        switch ($err) {
          case UPLOAD_ERR_INI_SIZE:
          case UPLOAD_ERR_FORM_SIZE:
            $msg .= 'File is too large for the server limits (upload_max_filesize).';
            break;
          case UPLOAD_ERR_PARTIAL:
            $msg .= 'Upload was interrupted (partial upload).';
            break;
          case UPLOAD_ERR_NO_TMP_DIR:
            $msg .= 'Server missing temp folder.';
            break;
          case UPLOAD_ERR_CANT_WRITE:
            $msg .= 'Failed to write file to disk.';
            break;
          case UPLOAD_ERR_EXTENSION:
            $msg .= 'Upload blocked by a PHP extension.';
            break;
          default:
            $msg .= 'Upload error (code '.$err.').';
        }
        $uploadErrors[] = $msg;
      }
    }
  }

  // Handle saving address if visible (manual entry)
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
  // Handle saving from stored address selector (storage addresses for vehicles/boats)
  if (isset($_POST['saved_address_id']) && $_POST['saved_address_id']!=='') {
    $saId = (int)$_POST['saved_address_id'];
    // Determine addrType again based on chosen category_id
    $catName = '';
    if (!empty($category_id)) {
      $stmt = $pdo->prepare('SELECT name FROM asset_categories WHERE id=?');
      $stmt->execute([$category_id]);
      $cn = $stmt->fetchColumn();
      $catName = strtolower($cn ?: '');
    }
    $addrType = in_array($catName, ['vehicle','car','boat']) ? 'storage' : '';
    if ($addrType) {
      $stmt = $pdo->prepare('SELECT line1, line2, city, state, postal_code, country FROM saved_addresses WHERE id=?');
      $stmt->execute([$saId]);
      if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $pdo->prepare('DELETE FROM asset_addresses WHERE asset_id=? AND address_type=?')->execute([$id, $addrType]);
        $ins = $pdo->prepare('INSERT INTO asset_addresses(asset_id, address_type, line1, line2, city, state, postal_code, country) VALUES (?,?,?,?,?,?,?,?)');
        $ins->execute([$id, $addrType, $row['line1'], $row['line2'], $row['city'], $row['state'], $row['postal_code'], $row['country']]);
      }
    }
  }

  // Save dynamic custom properties for the selected category
  if (!empty($category_id)) {
    $stmt = $pdo->prepare('SELECT id, input_type FROM asset_property_defs WHERE is_active=1 AND (is_core=0 OR is_core IS NULL) AND category_id=?');
    $stmt->execute([$category_id]);
    $defs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($defs as $d) {
      $pid = (int)$d['id'];
      $type = $d['input_type'];
      $field = 'prop_'.$pid;
      $val = ($type === 'checkbox') ? (isset($_POST[$field]) ? '1' : '0') : trim($_POST[$field] ?? '');
      $ins = $pdo->prepare('INSERT INTO asset_property_values(asset_id, property_def_id, value_text) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)');
      $ins->execute([$id, $pid, $val]);
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
  $stmt = $pdo->prepare("SELECT id, filename, mime_type, size, caption, uploaded_at FROM files WHERE entity_type='asset' AND entity_id=? AND is_trashed=0 AND mime_type LIKE 'image/%' ORDER BY uploaded_at DESC");
  $stmt->execute([$id]);
  $photos = $stmt->fetchAll();
}
// Linked policies (direct) with coverage mapping
$policies = [];
if ($isEdit) {
  $stmt = $pdo->prepare('SELECT p.id, p.policy_number, p.insurer, p.status, p.policy_type,
                                cd.name AS cov_name, pc.limit_amount, pa.coverage_definition_id
                         FROM policy_assets pa
                         JOIN policies p ON p.id=pa.policy_id
                         LEFT JOIN coverage_definitions cd ON cd.id=pa.coverage_definition_id
                         LEFT JOIN policy_coverages pc ON pc.policy_id=p.id AND pc.coverage_definition_id=pa.coverage_definition_id
                         WHERE pa.asset_id=? ORDER BY p.policy_number ASC');
  $stmt->execute([$id]);
  $policies = $stmt->fetchAll();
}

// Handle link/unlink of policy from asset with coverage mapping
if ($isEdit && ((($_POST['lp_action'] ?? '') === 'link_policy') || (($_POST['action'] ?? '') === 'link_policy'))) {
  Util::checkCsrf();
  $pid = (int)($_POST['policy_id'] ?? 0);
  $apply = !empty($_POST['applies_to_children']) ? 1 : 0;
  $cov = isset($_POST['coverage_definition_id']) && $_POST['coverage_definition_id']!=='' ? (int)$_POST['coverage_definition_id'] : null;
  $childCov = isset($_POST['children_coverage_definition_id']) && $_POST['children_coverage_definition_id']!=='' ? (int)$_POST['children_coverage_definition_id'] : null;
  $stmt = $pdo->prepare('INSERT IGNORE INTO policy_assets(policy_id, asset_id, applies_to_children, coverage_definition_id, children_coverage_definition_id)
                         VALUES (?,?,?,?,?)');
  $stmt->execute([$pid, $id, $apply, $cov, $childCov]);
  Util::redirect('index.php?page=asset_edit&id='.$id);
}
if ($isEdit && ((($_POST['lp_action'] ?? '') === 'unlink_policy') || (($_POST['action'] ?? '') === 'unlink_policy'))) {
  Util::checkCsrf();
  $pid = (int)($_POST['policy_id'] ?? 0);
  $covId = isset($_POST['coverage_definition_id']) && $_POST['coverage_definition_id']!=='' ? (int)$_POST['coverage_definition_id'] : null;
  if ($covId) {
    $stmt = $pdo->prepare('DELETE FROM policy_assets WHERE policy_id=? AND asset_id=? AND coverage_definition_id=?');
    $stmt->execute([$pid, $id, $covId]);
  } else {
    $stmt = $pdo->prepare('DELETE FROM policy_assets WHERE policy_id=? AND asset_id=?');
    $stmt->execute([$pid, $id]);
  }
  Util::redirect('index.php?page=asset_edit&id='.$id);
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
  <form method="post" enctype="multipart/form-data" id="assetForm">
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
      <?php
        // Dynamic properties for this category
        $propDefs = [];
        if (!empty($asset['category_id'])) {
          $stmt = $pdo->prepare('SELECT * FROM asset_property_defs WHERE is_active=1 AND (is_core=0 OR is_core IS NULL) AND category_id=? ORDER BY sort_order, display_name');
          $stmt->execute([$asset['category_id']]);
          $propDefs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $propVals = [];
        if ($isEdit && $propDefs) {
          $ids = implode(',', array_map('intval', array_column($propDefs, 'id')));
          $rows = $pdo->query('SELECT property_def_id, value_text FROM asset_property_values WHERE asset_id='.(int)$id.' AND property_def_id IN ('.$ids.')')->fetchAll(PDO::FETCH_ASSOC);
          foreach ($rows as $r) { $propVals[(int)$r['property_def_id']] = $r['value_text']; }
        }
      ?>
      <?php if ($propDefs): ?>
        <div class="col-12"><h2>Additional Properties</h2></div>
        <?php foreach ($propDefs as $d): $pid=(int)$d['id']; $v=$propVals[$pid] ?? ''; $t=$d['input_type']; ?>
          <?php if ($t==='checkbox'): ?>
            <div class="col-4"><label><?= Util::h($d['display_name']) ?></label><input type="checkbox" name="prop_<?= $pid ?>" value="1" <?= ($v==='1')?'checked':'' ?>></div>
          <?php elseif ($t==='date'): ?>
            <div class="col-4"><label><?= Util::h($d['display_name']) ?></label><input type="date" name="prop_<?= $pid ?>" value="<?= Util::h($v) ?>"></div>
          <?php elseif ($t==='number'): ?>
            <div class="col-4"><label><?= Util::h($d['display_name']) ?></label><input type="number" step="0.01" name="prop_<?= $pid ?>" value="<?= Util::h($v) ?>"></div>
          <?php else: ?>
            <div class="col-4"><label><?= Util::h($d['display_name']) ?></label><input name="prop_<?= $pid ?>" value="<?= Util::h($v) ?>"></div>
          <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>

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
            <?php if (!empty($addr['line1']) || !empty($addr['city'])): ?>
              <?php
                $addrText = trim(($addr['line1'] ?? ''));
                if (!empty($addr['line2'])) $addrText .= ', '.trim($addr['line2']);
                $cityline = trim(($addr['city'] ?? ''));
                if (!empty($addr['state'])) $cityline .= ($cityline?', ':'').trim($addr['state']);
                if (!empty($addr['postal_code'])) $cityline .= ' '.trim($addr['postal_code']);
                if ($cityline) $addrText .= ($addrText?', ':'').$cityline;
                if (!empty($addr['country'])) $addrText .= ($addrText?', ':'').trim($addr['country']);
              ?>
              <label>Stored Address</label>
              <div id="storedAddrView" style="display:flex; align-items:center; gap:8px;">
                <div><strong><?= Util::h($addrText) ?></strong></div>
                <a href="#" id="changeStoredAddr">Change</a>
              </div>
              <div id="storedAddrSelect" style="display:none">
                <select id="saved_address_id" name="saved_address_id">
                  <option value="">--</option>
                  <?php foreach ($savedAddrs as $sa): ?>
                    <option value="<?= (int)$sa['id'] ?>"><?= Util::h($sa['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="small muted">Stored addresses are configurable in Settings → Addresses.</div>
            <?php else: ?>
              <label>Select Stored Address</label>
              <select id="saved_address_id" name="saved_address_id">
                <option value="">--</option>
                <?php foreach ($savedAddrs as $sa): ?>
                  <option value="<?= (int)$sa['id'] ?>"><?= Util::h($sa['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="small muted">Stored addresses are configurable in Settings → Addresses.</div>
            <?php endif; ?>
          </div>
          <script>
            (function(){
              var link = document.getElementById('changeStoredAddr');
              if (!link) return;
              link.addEventListener('click', function(e){ e.preventDefault();
                var v = document.getElementById('storedAddrView');
                var s = document.getElementById('storedAddrSelect');
                if (v && s){ v.style.display='none'; s.style.display='block'; }
              });
            })();
          </script>
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
        <?php if (!empty($uploadErrors)): ?>
          <div class="small" style="color:#dc2626; margin:6px 0 8px;">
            <?= Util::h(implode("\n", $uploadErrors)) ?>
          </div>
        <?php endif; ?>
        
        <?php if ($photos): ?>
          <div class="gallery sm" id="photoGallery" style="margin-top:8px;">
            <?php foreach ($photos as $ph): ?>
              <div class="thumb" data-file-wrap>
                <button class="thumb-trash" type="button" title="Move to Trash" data-file-trash data-file-id="<?= (int)$ph['id'] ?>">🗑️</button>
                <img data-file-id="<?= (int)$ph['id'] ?>" data-filename="<?= Util::h($ph['filename']) ?>" data-size="<?= (int)$ph['size'] ?>" data-uploaded="<?= Util::h($ph['uploaded_at']) ?>" src="<?= Util::baseUrl('file.php?id='.(int)$ph['id']) ?>" alt="<?= Util::h($ph['filename']) ?>">
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="small muted" id="photoEmpty">No photos yet. Add some to document the asset.</div>
          <div class="gallery sm" id="photoGallery" style="margin-top:8px; display:none;"></div>
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
        <?php
          // Detect if DB schema allows multiple coverages per policy/asset
          $idxCols = $pdo->query("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name='policy_assets' AND index_name='uniq_policy_asset'")->fetchColumn();
          $hasCovCol = (bool)$pdo->query("SHOW COLUMNS FROM policy_assets LIKE 'coverage_definition_id'")->fetch();
          $multiCovOk = $hasCovCol && $idxCols && (strpos($idxCols, 'coverage_definition_id') !== false);
          if (!$multiCovOk): ?>
            <div class="small" style="color:#b91c1c; background:#fef2f2; border:1px solid #fecaca; padding:8px 10px; border-radius:8px; margin:6px 0 10px;">
              Multiple coverages from the same policy cannot be added until the database index is updated.
              Run the following in MySQL (as your app DB user):
              <pre style="white-space:pre-wrap; font-size:11px; line-height:1.4; margin:6px 0 0;">-- allow multiple coverages per policy/asset
ALTER TABLE policy_assets DROP INDEX uniq_policy_asset;
ALTER TABLE policy_assets ADD UNIQUE KEY uniq_policy_asset (policy_id, asset_id, coverage_definition_id);
              </pre>
            </div>
        <?php endif; ?>
        <?php
          $allPolicies = $pdo->query('SELECT id, policy_number, insurer FROM policies ORDER BY end_date DESC')->fetchAll();
          $pcRows = $pdo->query('SELECT pc.policy_id, cd.id AS cov_id, cd.name AS cov_name FROM policy_coverages pc JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id ORDER BY pc.policy_id, cd.name')->fetchAll();
          $pcMap = [];
          foreach ($pcRows as $r) { $pid = (int)$r['policy_id']; if (!isset($pcMap[$pid])) $pcMap[$pid]=[]; $pcMap[$pid][] = ['id'=>(int)$r['cov_id'], 'name'=>$r['cov_name']]; }
        ?>
        <div class="small muted" style="margin-bottom:8px">Use the sidebar button to link additional coverages.</div>
        <?php if (!$policies): ?>
          <div class="small muted">No direct policy links. Policies can also inherit from parents.</div>
        <?php else: ?>
          <div class="table-wrap"><table>
            <thead><tr><th>Policy #</th><th>Insurer</th><th>Coverage</th><th>Limit</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($policies as $p): ?>
                <tr>
                  <td><a href="<?= Util::baseUrl('index.php?page=policy_edit&id='.(int)$p['id']) ?>"><?= Util::h($p['policy_number']) ?></a></td>
                  <td><?= Util::h($p['insurer']) ?></td>
                  <td><?= Util::h($p['cov_name'] ?? '-') ?></td>
                  <td><?= isset($p['limit_amount']) ? ('$'.number_format($p['limit_amount'],2)) : '-' ?></td>
                  <td><span class="pill <?= ($p['status']==='active')?'primary':'warn' ?>"><?= Util::h($p['status']) ?></span></td>
                  <td>
                    <form method="post" onsubmit="return confirmAction('Unlink policy?')">
                      <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                      <input type="hidden" name="lp_action" value="unlink_policy">
                      <input type="hidden" name="policy_id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="coverage_definition_id" value="<?= isset($p['coverage_definition_id']) ? (int)$p['coverage_definition_id'] : '' ?>">
                      <button class="btn sm ghost danger" title="Unlink">🗑️</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
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
  // Populate coverage selects for policy linking (with fallback by policy type)
  (function(){
    var covMap = <?= json_encode($pcMap ?? []) ?>; // policy_id => [{id,name}]
    var polType = <?= json_encode(array_column($pdo->query('SELECT id, policy_type FROM policies')->fetchAll(PDO::FETCH_ASSOC), 'policy_type', 'id')) ?>;
    <?php
      $defs = $pdo->query('SELECT id, name, applicable_types FROM coverage_definitions ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
      $byType = [];
      $all = [];
      foreach ($defs as $d){
        $all[] = ['id'=>(int)$d['id'], 'name'=>$d['name']];
        $apps = array_filter(array_map('trim', explode(',', (string)$d['applicable_types'])));
        foreach ($apps as $t){ $t=strtolower($t); if(!isset($byType[$t])) $byType[$t]=[]; $byType[$t][] = ['id'=>(int)$d['id'], 'name'=>$d['name']]; }
      }
    ?>
    var covByType = <?= json_encode($byType) ?>;
    var covAll = <?= json_encode($all) ?>;

    function fill(sel, arr, includeEmpty){
      while (sel.firstChild) sel.removeChild(sel.firstChild);
      if (includeEmpty){ var o=document.createElement('option'); o.value=''; o.textContent='--'; sel.appendChild(o); }
      (arr||[]).forEach(function(c){ var o=document.createElement('option'); o.value=c.id; o.textContent=c.name; sel.appendChild(o); });
    }
    var pSel = document.getElementById('lm_policy');
    if (pSel){
      var aSel = document.getElementById('lm_cov');
      var cSel = document.getElementById('lm_child_cov');
      function apply(){
        var pid = parseInt(pSel.value,10);
        var list = (covMap && covMap[pid]) ? covMap[pid] : null;
        if (!list || !list.length){
          var t = polType && polType[pid] ? String(polType[pid]).toLowerCase() : '';
          list = (covByType && covByType[t]) ? covByType[t] : covAll;
        }
        fill(aSel, list||[], false);
        fill(cSel, list||[], true);
      }
      pSel.addEventListener('change', apply);
      apply();
    }
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
      </div>
      <?php else: ?>
        <div class="small muted">Save the asset first to configure locations and public link.</div>
      <?php endif; ?>
      <?php if ($isEdit): ?>
      <div style="margin-top:12px">
        <h2>Actions</h2>
        <div class="actions" style="flex-direction:column;gap:6px;align-items:stretch">
          <button class="btn" type="button" data-modal-open="photoModal">Add Photos</button>
          <button class="btn" type="button" data-modal-open="linkPolicyModal">Link Policy / Coverage</button>
          <button class="btn" type="button" data-modal-open="locModal">Add Location</button>
          <?php
            // Plugin action button (Zillow for Home), opens modal with inputs/results
            require_once __DIR__ . '/../../lib/Plugins.php';
            $plug = PluginManager::get('zillow');
            if ($plug && PluginManager::isEnabled('zillow')) {
              $catIdCur = (int)($asset['category_id'] ?? 0);
              $catNameCur = '';
              foreach ($cats as $c) { if ((int)$c['id'] === $catIdCur) { $catNameCur = (string)$c['name']; break; } }
              $homeNames = array_map('strtolower', (array)($plug['actions'][0]['applies_to_categories'] ?? []));
              if ($catNameCur && in_array(strtolower($catNameCur), $homeNames)) {
          ?>
            <button class="btn" type="button" id="btnZillow">Query Zillow</button>
            <!-- Generic Plugin Modal -->
            <div class="modal-backdrop" id="pluginModal">
              <div class="modal" style="width:min(720px,95vw)">
                <div class="head"><strong id="pm_title">Plugin Action</strong><button class="x" data-modal-close="pluginModal">✕</button></div>
                <div class="body">
                  <div id="pm_loading" style="display:none;align-items:center;gap:8px"><div class="spinner"></div><div>Working…</div></div>
                  <form id="pm_form" style="display:none">
                    <div class="row" id="pm_inputs"></div>
                  </form>
                  <div id="pm_result" style="display:none"></div>
                  <div id="pm_error" class="small" style="display:none;color:#dc2626"></div>
                </div>
                <div class="foot">
                  <button class="btn ghost" data-modal-close="pluginModal" type="button">Close</button>
                  <button class="btn" id="pm_run" type="button" style="display:none">Run</button>
                </div>
              </div>
            </div>
            <script>
              (function(){
                const btn = document.getElementById('btnZillow');
                const modal = document.getElementById('pluginModal');
                const title = document.getElementById('pm_title');
                const loading = document.getElementById('pm_loading');
                const form = document.getElementById('pm_form');
                const inputsWrap = document.getElementById('pm_inputs');
                const result = document.getElementById('pm_result');
                const errBox = document.getElementById('pm_error');
                const runBtn = document.getElementById('pm_run');
                function open(){ modal.classList.add('show'); }
                function close(){ modal.classList.remove('show'); }
                function setState(state){
                  loading.style.display = (state==='loading') ? 'flex' : 'none';
                  form.style.display = (state==='form') ? 'block' : 'none';
                  result.style.display = (state==='result') ? 'block' : 'none';
                }
                function setError(msg){ errBox.textContent = msg||''; errBox.style.display = msg? 'block':'none'; }
                async function describe(){
                  setError(''); setState('loading');
                  const fd = new FormData();
                  fd.append('csrf','<?= Util::csrfToken() ?>');
                  fd.append('plugin','zillow');
                  fd.append('action_key','query_zillow');
                  fd.append('asset_id','<?= (int)$id ?>');
                  fd.append('phase','describe');
                  const res = await fetch('<?= Util::baseUrl('plugin_action.php') ?>', { method:'POST', body: fd, headers:{'X-CSRF':'<?= Util::csrfToken() ?>'} });
                  const json = await res.json();
                  if (!(json && json.ok)) { setError(json && json.error ? json.error : 'Failed to initialize'); return; }
                  const ui = json.ui || {}; title.textContent = ui.title || 'Plugin Action';
                  // No inputs: auto-run; if additionally no output later, auto-close
                  inputsWrap.innerHTML = '';
                  const inputs = (ui.inputs||[]);
                  if (!inputs.length) {
                    await run({});
                    return;
                  }
                  inputs.forEach(function(inp){
                    const wrap = document.createElement('div'); wrap.className='col-12';
                    const label = document.createElement('label'); label.textContent = inp.label || inp.name;
                    const el = document.createElement('input'); el.name = 'input_'+(inp.name||''); el.placeholder = inp.placeholder||''; el.value = inp.value||''; el.type = (inp.type||'text');
                    wrap.appendChild(label); wrap.appendChild(el); inputsWrap.appendChild(wrap);
                  });
                  setState('form'); runBtn.style.display='inline-block';
                  runBtn.onclick = async function(){
                    const data = {}; Array.from(form.querySelectorAll('input[name^="input_"]')).forEach(function(i){ data[i.name.substring(6)] = i.value; });
                    await run(data);
                  };
                }
                async function run(data){
                  setError(''); setState('loading'); runBtn.style.display='none';
                  const fd = new FormData();
                  fd.append('csrf','<?= Util::csrfToken() ?>');
                  fd.append('plugin','zillow');
                  fd.append('action_key','query_zillow');
                  fd.append('asset_id','<?= (int)$id ?>');
                  fd.append('phase','run');
                  fd.append('inputs', JSON.stringify(data||{}));
                  const res = await fetch('<?= Util::baseUrl('plugin_action.php') ?>', { method:'POST', body: fd, headers:{'X-CSRF':'<?= Util::csrfToken() ?>'} });
                  const json = await res.json();
                  if (!(json && json.ok)) { setError(json && json.error ? json.error : 'Plugin error'); if (json && json.debug_html) { result.innerHTML = json.debug_html; setState('result'); } else { setState('form'); } return; }
                  // If no html to display, auto-close and toast
                  if (!json.html) { close(); toast('Completed'); setTimeout(function(){ location.reload(); }, 400); return; }
                  result.innerHTML = json.html; if (json.debug_html) { result.insertAdjacentHTML('beforeend', json.debug_html); } setState('result');
                }
                btn && btn.addEventListener('click', function(){ open(); describe(); });
              })();
            </script>
          <?php }
            }

            // Car Values plugin action button (for Vehicle categories)
            $carPlug = PluginManager::get('carvalues');
            if ($carPlug && PluginManager::isEnabled('carvalues')) {
              $catIdCur = (int)($asset['category_id'] ?? 0);
              $catNameCur = '';
              foreach ($cats as $c) { if ((int)$c['id'] === $catIdCur) { $catNameCur = (string)$c['name']; break; } }
              $vehNames = array_map('strtolower', (array)($carPlug['actions'][0]['applies_to_categories'] ?? []));
              if ($catNameCur && in_array(strtolower($catNameCur), $vehNames)) {
          ?>
            <button class="btn" type="button" id="btnCarValue">Estimate Car Value</button>
            <!-- Car Plugin Modal -->
            <div class="modal-backdrop" id="carPluginModal">
              <div class="modal" style="width:min(720px,95vw)">
                <div class="head"><strong id="cpm_title">Plugin Action</strong><button class="x" data-modal-close="carPluginModal">✕</button></div>
                <div class="body">
                  <div id="cpm_loading" style="display:none;align-items:center;gap:8px"><div class="spinner"></div><div>Working…</div></div>
                  <form id="cpm_form" style="display:none">
                    <div class="row" id="cpm_inputs"></div>
                  </form>
                  <div id="cpm_result" style="display:none"></div>
                  <div id="cpm_error" class="small" style="display:none;color:#dc2626"></div>
                </div>
                <div class="foot">
                  <button class="btn ghost" data-modal-close="carPluginModal" type="button">Close</button>
                  <button class="btn" id="cpm_run" type="button" style="display:none">Run</button>
                </div>
              </div>
            </div>
            <script>
              (function(){
                const btn = document.getElementById('btnCarValue');
                const modal = document.getElementById('carPluginModal');
                const title = document.getElementById('cpm_title');
                const loading = document.getElementById('cpm_loading');
                const form = document.getElementById('cpm_form');
                const inputsWrap = document.getElementById('cpm_inputs');
                const result = document.getElementById('cpm_result');
                const errBox = document.getElementById('cpm_error');
                const runBtn = document.getElementById('cpm_run');
                function open(){ modal.classList.add('show'); }
                function close(){ modal.classList.remove('show'); }
                function setState(state){
                  loading.style.display = (state==='loading') ? 'flex' : 'none';
                  form.style.display = (state==='form') ? 'block' : 'none';
                  result.style.display = (state==='result') ? 'block' : 'none';
                }
                function setError(msg){ errBox.textContent = msg||''; errBox.style.display = msg? 'block':'none'; }
                async function describe(){
                  setError(''); setState('loading');
                  const fd = new FormData();
                  fd.append('csrf','<?= Util::csrfToken() ?>');
                  fd.append('plugin','carvalues');
                  fd.append('action_key','query_vehicle_value');
                  fd.append('asset_id','<?= (int)$id ?>');
                  fd.append('phase','describe');
                  const res = await fetch('<?= Util::baseUrl('plugin_action.php') ?>', { method:'POST', body: fd, headers:{'X-CSRF':'<?= Util::csrfToken() ?>'} });
                  const json = await res.json();
                  if (!(json && json.ok)) { setError(json && json.error ? json.error : 'Failed to initialize'); return; }
                  const ui = json.ui || {}; title.textContent = ui.title || 'Plugin Action';
                  inputsWrap.innerHTML = '';
                  const inputs = (ui.inputs||[]);
                  if (!inputs.length) {
                    await run({});
                    return;
                  }
                  inputs.forEach(function(inp){
                    const wrap = document.createElement('div'); wrap.className='col-12';
                    const label = document.createElement('label'); label.textContent = inp.label || inp.name;
                    const el = document.createElement('input'); el.name = 'input_'+(inp.name||''); el.placeholder = inp.placeholder||''; el.value = inp.value||''; el.type = (inp.type||'text');
                    wrap.appendChild(label); wrap.appendChild(el); inputsWrap.appendChild(wrap);
                  });
                  setState('form'); runBtn.style.display='inline-block';
                  runBtn.onclick = async function(){
                    const data = {}; Array.from(form.querySelectorAll('input[name^="input_"]')).forEach(function(i){ data[i.name.substring(6)] = i.value; });
                    await run(data);
                  };
                }
                async function run(data){
                  setError(''); setState('loading'); runBtn.style.display='none';
                  const fd = new FormData();
                  fd.append('csrf','<?= Util::csrfToken() ?>');
                  fd.append('plugin','carvalues');
                  fd.append('action_key','query_vehicle_value');
                  fd.append('asset_id','<?= (int)$id ?>');
                  fd.append('phase','run');
                  fd.append('inputs', JSON.stringify(data||{}));
                  const res = await fetch('<?= Util::baseUrl('plugin_action.php') ?>', { method:'POST', body: fd, headers:{'X-CSRF':'<?= Util::csrfToken() ?>'} });
                  const json = await res.json();
                  if (!(json && json.ok)) { setError(json && json.error ? json.error : 'Plugin error'); if (json && json.debug_html) { result.innerHTML = json.debug_html; setState('result'); } else { setState('form'); } return; }
                  if (!json.html) { close(); toast('Completed'); setTimeout(function(){ location.reload(); }, 400); return; }
                  result.innerHTML = json.html; if (json.debug_html) { result.insertAdjacentHTML('beforeend', json.debug_html); } setState('result');
                }
                btn && btn.addEventListener('click', function(){ open(); describe(); });
              })();
            </script>
          <?php }
            }
          ?>
        </div>
      </div>
      <?php endif; ?>
      <hr class="divider">
      <div class="actions" style="justify-content: space-between;">
        <button class="btn" type="button" onclick="document.getElementById('assetForm').submit()">Save</button>
        <?php if ($isEdit): ?>
        <form method="post" onsubmit="return confirmAction('Delete this asset? This will move it to trash (soft delete).')">
          <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
          <input type="hidden" name="action" value="delete_asset">
          <input type="hidden" name="id" value="<?= (int)$id ?>">
          <button class="btn danger">Delete</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
</div>
</div>

<?php if ($isEdit): ?>
<!-- Link Policy/Coverage Modal -->
<div class="modal-backdrop" id="linkPolicyModal">
  <div class="modal" style="width:min(720px,95vw)">
    <div class="head"><strong>Link Policy / Coverage</strong><button class="x" data-modal-close="linkPolicyModal">✕</button></div>
    <div class="body">
      <?php
        $allPolicies_modal = $pdo->query('SELECT id, policy_number, insurer, policy_type FROM policies ORDER BY end_date DESC')->fetchAll(PDO::FETCH_ASSOC);
        $pcRows_modal = $pdo->query('SELECT pc.policy_id, cd.id AS cov_id, cd.name AS cov_name FROM policy_coverages pc JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id ORDER BY pc.policy_id, cd.name')->fetchAll(PDO::FETCH_ASSOC);
        $pcMap_modal = [];
        foreach ($pcRows_modal as $r) { $pid = (int)$r['policy_id']; if (!isset($pcMap_modal[$pid])) $pcMap_modal[$pid]=[]; $pcMap_modal[$pid][] = ['id'=>(int)$r['cov_id'], 'name'=>$r['cov_name']]; }
      ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
        <input type="hidden" name="lp_action" value="link_policy">
        <div class="row">
          <div class="col-6">
            <label>Policy</label>
            <select name="policy_id" id="lm_policy">
              <?php foreach ($allPolicies_modal as $pp): ?>
                <option value="<?= (int)$pp['id'] ?>"><?= Util::h($pp['policy_number'].' — '.$pp['insurer']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label>Coverage (Asset)</label>
            <select name="coverage_definition_id" id="lm_cov" required></select>
          </div>
          <div class="col-6">
            <label>Applies to Contents</label>
            <select name="applies_to_children">
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>
          <div class="col-6">
            <label>Coverage (Children)</label>
            <select name="children_coverage_definition_id" id="lm_child_cov"><option value="">--</option></select>
          </div>
          <div class="col-12 actions"><button class="btn" type="submit">Add Link</button></div>
        </div>
      </form>
    </div>
    <div class="foot"><button class="btn ghost" data-modal-close="linkPolicyModal">Close</button></div>
  </div>
</div>

<script>
  (function(){
    // Data from PHP
    var covMap = <?= json_encode($pcMap_modal ?? []) ?>; // policy_id => [{id,name}]
    var polType = <?= json_encode(array_column($allPolicies_modal, 'policy_type', 'id')) ?>;
    <?php
      $defs = $pdo->query('SELECT id, name, applicable_types FROM coverage_definitions ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
      $byType = [];
      $all = [];
      foreach ($defs as $d){
        $all[] = ['id'=>(int)$d['id'], 'name'=>$d['name']];
        $apps = array_filter(array_map('trim', explode(',', (string)$d['applicable_types'])));
        foreach ($apps as $t){ $t=strtolower($t); if(!isset($byType[$t])) $byType[$t]=[]; $byType[$t][] = ['id'=>(int)$d['id'], 'name'=>$d['name']]; }
      }
    ?>
    var covByType = <?= json_encode($byType) ?>;
    var covAll = <?= json_encode($all) ?>;

    function fill(sel, arr, includeEmpty){
      if (!sel) return;
      while (sel.firstChild) sel.removeChild(sel.firstChild);
      if (includeEmpty){ var o=document.createElement('option'); o.value=''; o.textContent='--'; sel.appendChild(o); }
      (arr||[]).forEach(function(c){ var o=document.createElement('option'); o.value=c.id; o.textContent=c.name; sel.appendChild(o); });
    }
    function applyCov(){
      var pSel = document.getElementById('lm_policy');
      var aSel = document.getElementById('lm_cov');
      var cSel = document.getElementById('lm_child_cov');
      if (!pSel || !aSel || !cSel) return;
      var pid = parseInt(pSel.value,10);
      var list = (covMap && covMap[pid]) ? covMap[pid] : null;
      if (!list || !list.length){
        var t = polType && polType[pid] ? String(polType[pid]).toLowerCase() : '';
        list = (covByType && covByType[t]) ? covByType[t] : covAll;
      }
      fill(aSel, list||[], false);
      fill(cSel, list||[], true);
    }
    // Initialize on open and on change
    document.querySelectorAll('[data-modal-open="linkPolicyModal"]').forEach(function(btn){
      btn.addEventListener('click', function(){ setTimeout(applyCov, 0); });
    });
    document.addEventListener('change', function(e){ if (e.target && e.target.id==='lm_policy') applyCov(); });
  })();
</script>
<!-- Link Policy/Coverage Modal -->
<div class="modal-backdrop" id="linkPolicyModal">
  <div class="modal" style="width:min(720px,95vw)">
    <div class="head"><strong>Link Policy / Coverage</strong><button class="x" data-modal-close="linkPolicyModal">✕</button></div>
    <div class="body">
      <?php
        $allPolicies_modal = $pdo->query('SELECT id, policy_number, insurer FROM policies ORDER BY end_date DESC')->fetchAll();
        $pcRows_modal = $pdo->query('SELECT pc.policy_id, cd.id AS cov_id, cd.name AS cov_name FROM policy_coverages pc JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id ORDER BY pc.policy_id, cd.name')->fetchAll();
        $pcMap_modal = [];
        foreach ($pcRows_modal as $r) { $pid = (int)$r['policy_id']; if (!isset($pcMap_modal[$pid])) $pcMap_modal[$pid]=[]; $pcMap_modal[$pid][] = ['id'=>(int)$r['cov_id'], 'name'=>$r['cov_name']]; }
      ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
        <input type="hidden" name="action" value="link_policy">
        <div class="row">
          <div class="col-6">
            <label>Policy</label>
            <select name="policy_id" id="lm_policy">
              <?php foreach ($allPolicies_modal as $pp): ?>
                <option value="<?= (int)$pp['id'] ?>"><?= Util::h($pp['policy_number'].' — '.$pp['insurer']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label>Coverage (Asset)</label>
            <select name="coverage_definition_id" id="lm_cov" required></select>
          </div>
          <div class="col-6">
            <label>Applies to Contents</label>
            <select name="applies_to_children">
              <option value="1">Yes</option>
              <option value="0">No</option>
            </select>
          </div>
          <div class="col-6">
            <label>Coverage (Children)</label>
            <select name="children_coverage_definition_id" id="lm_child_cov"><option value="">--</option></select>
          </div>
          <div class="col-12 actions"><button class="btn" type="submit">Add Link</button></div>
        </div>
      </form>
    </div>
    <div class="foot"><button class="btn ghost" data-modal-close="linkPolicyModal">Close</button></div>
  </div>
</div>
<!-- Add Photo Modal -->
<div class="modal-backdrop" id="photoModal">
  <div class="modal" style="width:min(640px, 95vw)">
    <div class="head"><strong>Upload and attach photos</strong><button class="x" data-modal-close="photoModal">✕</button></div>
    <div class="body">
      <div class="uploader">
        <div class="dropzone" id="pm_drop">
          <input id="pm_input" type="file" accept="image/*" multiple aria-label="Choose photos">
          <div class="dz-inner">
            <div class="dz-icon">📷</div>
            <div class="dz-title"><label for="pm_input" style="cursor:pointer">Click to upload</label> or drag and drop</div>
            <div class="dz-sub">Maximum file size 50 MB. JPEG/PNG/HEIC supported.</div>
          </div>
        </div>
        <div id="pm_error" class="uploader-error" style="display:none"></div>
        <div id="pm_list" class="uploader-list"></div>
        <div id="pm_status" class="uploader-status" style="display:none"></div>
      </div>
    </div>
    <div class="foot" style="justify-content: space-between;">
      <button class="btn ghost" type="button" data-modal-close="photoModal">Cancel</button>
      <button class="btn" type="button" id="pm_upload" disabled>Attach files</button>
    </div>
  </div>
</div>
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
