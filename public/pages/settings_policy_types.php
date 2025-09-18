<?php
$pdo = Database::get();

function slugify($s){ $s=strtolower(trim($s)); $s=preg_replace('/[^a-z0-9]+/','_', $s); $s=trim($s,'_'); return $s ?: 'type'; }

// Handle actions
$action = $_POST['action'] ?? '';
if ($action === 'add_type') {
  Util::checkCsrf();
  $code = slugify($_POST['code'] ?? '');
  $name = trim($_POST['name'] ?? '');
  $sort = (int)($_POST['sort_order'] ?? 0);
  if ($name !== '') {
    $stmt = $pdo->prepare('INSERT IGNORE INTO policy_types(code,name,sort_order,is_active) VALUES (?,?,?,1)');
    $stmt->execute([$code,$name,$sort]);
    echo '<div class="small">Type added.</div>';
  }
}
if ($action === 'update_type') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $code = slugify($_POST['code'] ?? '');
  $name = trim($_POST['name'] ?? '');
  $sort = (int)($_POST['sort_order'] ?? 0);
  $active = isset($_POST['is_active']) ? 1 : 0;
  $stmt = $pdo->prepare('UPDATE policy_types SET code=?, name=?, sort_order=?, is_active=? WHERE id=?');
  $stmt->execute([$code,$name,$sort,$active,$id]);
  echo '<div class="small">Type updated.</div>';
}
if ($action === 'delete_type') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  // Prevent deleting if policies reference this code (soft business rule)
  $row = $pdo->prepare('SELECT code FROM policy_types WHERE id=?');
  $row->execute([$id]); $code = $row->fetchColumn();
  if ($code !== false) {
    $count = (int)$pdo->prepare('SELECT COUNT(*) FROM policies WHERE policy_type=?')->execute([$code]) ?: 0;
    // If referenced, just deactivate instead of delete
    if ($count > 0) { $pdo->prepare('UPDATE policy_types SET is_active=0 WHERE id=?')->execute([$id]); echo '<div class="small">Type is in use; deactivated instead of deleting.</div>'; }
    else { $pdo->prepare('DELETE FROM policy_types WHERE id=?')->execute([$id]); echo '<div class="small">Type deleted.</div>'; }
  }
}

// Load list
$types = $pdo->query('SELECT pt.*, (SELECT COUNT(*) FROM policies p WHERE p.policy_type=pt.code) AS use_count FROM policy_types pt ORDER BY pt.sort_order, pt.name')->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="settings-section">
  <h2>Add Policy Type</h2>
  <p class="settings-description">Create a reusable policy type to organize policies and filter coverages.</p>
  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
    <input type="hidden" name="action" value="add_type">
    <div class="col-4"><label>Code (slug)</label><input name="code" placeholder="e.g., auto"></div>
    <div class="col-6"><label>Display Name</label><input name="name" placeholder="Auto"></div>
    <div class="col-2"><label>Sort</label><input type="number" name="sort_order" value="0"></div>
    <div class="col-12"><button class="btn" type="submit">Add Type</button></div>
  </form>
</div>

<div class="settings-section">
  <h2>Manage Policy Types</h2>
  <?php if (!$types): ?>
    <div class="small muted">No types yet.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Code</th><th>Name</th><th>Sort</th><th>Active</th><th>In Use</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($types as $t): ?>
          <tr>
            <td>
              <form method="post" class="actions" style="gap:6px; align-items:center">
                <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                <input type="hidden" name="action" value="update_type">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <input name="code" value="<?= Util::h($t['code']) ?>" style="max-width:160px">
            </td>
            <td><input name="name" value="<?= Util::h($t['name']) ?>" style="max-width:260px"></td>
            <td><input type="number" name="sort_order" value="<?= (int)$t['sort_order'] ?>" style="max-width:90px"></td>
            <td><label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="is_active" <?= $t['is_active']?'checked':'' ?>> Active</label></td>
            <td><?= (int)$t['use_count'] ?></td>
            <td class="actions">
                <button class="btn sm" type="submit">Save</button>
              </form>
              <form method="post" onsubmit="return confirmAction('Are you sure?')">
                <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                <input type="hidden" name="action" value="delete_type">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn sm ghost danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="small muted">Deleting a type that is in use will deactivate it instead.</div>
  <?php endif; ?>
</div>

