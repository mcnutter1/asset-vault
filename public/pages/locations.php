<?php
$pdo = Database::get();

// Create / update location
if (($_POST['action'] ?? '') === 'save') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $name = trim($_POST['name'] ?? '');
  $desc = trim($_POST['description'] ?? '');
  $parent_id = $_POST['parent_id'] ? (int)$_POST['parent_id'] : null;
  if ($id) {
    $stmt = $pdo->prepare('UPDATE locations SET name=?, description=?, parent_id=? WHERE id=?');
    $stmt->execute([$name, $desc, $parent_id, $id]);
  } else {
    $stmt = $pdo->prepare('INSERT INTO locations(name, description, parent_id) VALUES (?,?,?)');
    $stmt->execute([$name, $desc, $parent_id]);
  }
  Util::redirect('index.php?page=locations');
}

// Delete location
if (($_POST['action'] ?? '') === 'delete') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  // Unlink assets first
  $pdo->prepare('UPDATE assets SET location_id=NULL WHERE location_id=?')->execute([$id]);
  $pdo->prepare('DELETE FROM locations WHERE id=?')->execute([$id]);
  Util::redirect('index.php?page=locations');
}

$locs = $pdo->query('SELECT * FROM locations ORDER BY parent_id, name')->fetchAll();
$byId = [];
foreach ($locs as $l) $byId[$l['id']] = $l;

?>
<div class="row">
  <div class="col-6">
    <div class="card">
      <h1>Locations</h1>
      <table>
        <thead><tr><th>Name</th><th>Parent</th><th>Description</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($locs as $l): ?>
            <tr>
              <td><?= Util::h($l['name']) ?></td>
              <td><?= $l['parent_id'] ? Util::h($byId[$l['parent_id']]['name'] ?? '') : '-' ?></td>
              <td><?= Util::h($l['description']) ?></td>
              <td>
                <form method="post" onsubmit="return confirmAction('Delete location? Assets will be unlinked.')" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                  <button class="btn ghost danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-6">
    <div class="card">
      <h2>Add / Edit</h2>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
        <input type="hidden" name="action" value="save">
        <div class="col-4"><label>ID (for edit, optional)</label><input name="id" type="number" min="0"></div>
        <div class="col-8"><label>Name</label><input name="name" required></div>
        <div class="col-6"><label>Parent</label>
          <select name="parent_id">
            <option value="">--</option>
            <?php foreach ($locs as $l): ?>
              <option value="<?= (int)$l['id'] ?>"><?= Util::h($l['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><label>Description</label><input name="description"></div>
        <div class="col-12 actions"><button class="btn" type="submit">Save</button></div>
      </form>
    </div>
  </div>
</div>

