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
$assets = $pdo->query('SELECT a.id, a.name, a.parent_id, a.public_token, ac.name AS category, l.name AS locname FROM assets a 
  LEFT JOIN asset_categories ac ON ac.id=a.category_id 
  LEFT JOIN locations l ON l.id=a.location_id
  WHERE a.is_deleted=0 
  ORDER BY a.parent_id IS NOT NULL, a.parent_id, a.name')->fetchAll();
$byParent = [];
foreach ($assets as $a) { $byParent[$a['parent_id'] ?? 0][] = $a; }

// Latest current values per asset
$vals = $pdo->query("SELECT asset_id, amount, valuation_date FROM asset_values WHERE value_type='current' ORDER BY valuation_date ASC")->fetchAll();
$selfValue = [];
foreach ($vals as $v) { $selfValue[$v['asset_id']] = (float)$v['amount']; }

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
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
    <h1>Assets</h1>
    <a class="btn" href="<?= Util::baseUrl('index.php?page=asset_edit') ?>">Add Asset</a>
  </div>
  <table>
    <thead>
      <tr>
        <th>Asset</th>
        <th>Category</th>
        <th>Location</th>
        <th>Value</th>
        <th>Contents</th>
        <th>Total</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($flat as $row): $t = totalsFor($row['id'], $byParent, $selfValue, $memoTotals); ?>
        <tr>
          <td>
            <div style="padding-left: <?= (int)$row['depth']*16 ?>px">
              <?= Util::h($row['name']) ?>
            </div>
          </td>
          <td><?= Util::h($row['category']) ?></td>
          <td><?= $row['locname'] ? Util::h($row['locname']) : '-' ?></td>
          <td>$<?= number_format($t['self'], 2) ?></td>
          <td>$<?= number_format($t['contents'], 2) ?></td>
          <td><strong>$<?= number_format($t['total'], 2) ?></strong></td>
          <td class="actions">
            <a class="btn ghost" href="<?= Util::baseUrl('index.php?page=asset_edit&id='.(int)$row['id']) ?>">Edit</a>
            <?php if (!empty($row['public_token'])): $viewUrl = Util::baseUrl('index.php?page=asset_view&code='.$row['public_token']); ?>
              <a class="btn ghost" href="<?= $viewUrl ?>" target="_blank">View</a>
            <?php else: ?>
              <form method="post" style="display:inline" onsubmit="return confirmAction('Create public link for this asset?')">
                <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                <input type="hidden" name="action" value="gen_token">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="btn ghost" type="submit">Create Link</button>
              </form>
            <?php endif; ?>
            <form method="post" style="display:inline" onsubmit="return confirmAction('Delete asset?')">
              <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
              <button class="btn ghost danger" type="submit">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
