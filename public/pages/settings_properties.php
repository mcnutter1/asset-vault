<?php
$pdo = Database::get();

// Categories
$cats = $pdo->query('SELECT id, name FROM asset_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$catId = isset($_GET['cat']) && $_GET['cat']!=='' ? (int)$_GET['cat'] : null;

// Create/Update/Delete
if (($_POST['action'] ?? '') === 'add_prop') {
  Util::checkCsrf();
  $cid = isset($_POST['category_id']) && $_POST['category_id']!=='' ? (int)$_POST['category_id'] : null;
  $key = strtolower(trim($_POST['name_key'] ?? ''));
  $name = trim($_POST['display_name'] ?? '');
  $type = $_POST['input_type'] ?? 'text';
  $show = !empty($_POST['show_on_view']) ? 1 : 0;
  $sort = (int)($_POST['sort_order'] ?? 0);
  if ($key !== '' && $name !== '') {
    $stmt = $pdo->prepare('INSERT INTO asset_property_defs(category_id, name_key, display_name, input_type, show_on_view, sort_order, is_active) VALUES (?,?,?,?,?,?,1)');
    $stmt->execute([$cid, $key, $name, $type, $show, $sort]);
  }
  Util::redirect('index.php?page=settings&tab=properties&cat='.(string)$catId);
}
if (($_POST['action'] ?? '') === 'update_prop') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $name = trim($_POST['display_name'] ?? '');
  $type = $_POST['input_type'] ?? 'text';
  $show = !empty($_POST['show_on_view']) ? 1 : 0;
  $sort = (int)($_POST['sort_order'] ?? 0);
  $active = !empty($_POST['is_active']) ? 1 : 0;
  $stmt = $pdo->prepare('UPDATE asset_property_defs SET display_name=?, input_type=?, show_on_view=?, sort_order=?, is_active=? WHERE id=?');
  $stmt->execute([$name, $type, $show, $sort, $active, $id]);
  Util::redirect('index.php?page=settings&tab=properties&cat='.(string)$catId);
}
if (($_POST['action'] ?? '') === 'delete_prop') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $pdo->prepare('DELETE FROM asset_property_defs WHERE id=?')->execute([$id]);
  Util::redirect('index.php?page=settings&tab=properties&cat='.(string)$catId);
}

// Fetch existing props for selected category
if ($catId !== null) {
  $stmt = $pdo->prepare('SELECT * FROM asset_property_defs WHERE category_id '.($catId===0?'IS NULL':'= ?').' ORDER BY sort_order, display_name');
  if ($catId===0) $stmt->execute(); else $stmt->execute([$catId]);
  $props = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
  $props = [];
}
?>

<form method="get" class="row" style="margin-bottom:8px">
  <input type="hidden" name="page" value="settings">
  <input type="hidden" name="tab" value="properties">
  <div class="col-4">
    <label>Asset Type</label>
    <select name="cat" onchange="this.form.submit()">
      <option value="">Select a type…</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= ($catId===$c['id'])?'selected':'' ?>><?= Util::h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if ($catId !== null): ?>
  <div class="settings-section">
    <h2>Add Property</h2>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
      <input type="hidden" name="action" value="add_prop">
      <input type="hidden" name="category_id" value="<?= (int)$catId ?>">
      <div class="col-4"><label>Key</label><input name="name_key" placeholder="e.g., vin"></div>
      <div class="col-4"><label>Display Name</label><input name="display_name" placeholder="e.g., VIN"></div>
      <div class="col-2"><label>Type</label>
        <select name="input_type">
          <?php foreach (['text','date','number','checkbox'] as $t): ?>
            <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-1"><label>Show</label><input type="checkbox" name="show_on_view" value="1" checked></div>
      <div class="col-1"><label>Sort</label><input type="number" name="sort_order" value="0"></div>
      <div class="col-12 actions"><button class="btn sm" type="submit">Add</button></div>
    </form>
  </div>

  <div class="settings-section">
    <h2>Properties</h2>
    <?php if (!$props): ?>
      <div class="small muted">No properties defined for this asset type.</div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Key</th><th>Name</th><th>Type</th><th>Show</th><th>Sort</th><th>Active</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($props as $p): ?>
            <tr>
              <td><code><?= Util::h($p['name_key']) ?></code></td>
              <td>
                <form method="post" class="actions" style="gap:6px">
                  <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                  <input type="hidden" name="action" value="update_prop">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <input name="display_name" value="<?= Util::h($p['display_name']) ?>">
              </td>
              <td>
                  <select name="input_type">
                    <?php foreach (['text','date','number','checkbox'] as $t): ?>
                      <option value="<?= $t ?>" <?= $p['input_type']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                  </select>
              </td>
              <td style="text-align:center"><input type="checkbox" name="show_on_view" value="1" <?= $p['show_on_view']?'checked':'' ?>></td>
              <td><input type="number" name="sort_order" value="<?= (int)$p['sort_order'] ?>" style="width:80px"></td>
              <td style="text-align:center"><input type="checkbox" name="is_active" value="1" <?= $p['is_active']?'checked':'' ?>></td>
              <td class="actions">
                  <button class="btn sm" type="submit">Save</button>
                </form>
                <form method="post" onsubmit="return confirmAction('Delete property?')">
                  <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                  <input type="hidden" name="action" value="delete_prop">
                  <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                  <button class="btn sm ghost danger">🗑️</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>
<?php else: ?>
  <div class="settings-section">
    <div class="small muted">Select an asset type to manage its properties.</div>
  </div>
<?php endif; ?>

