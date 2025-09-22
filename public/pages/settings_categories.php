<?php
$pdo = Database::get();

// Actions: add, update, delete — scoped to asset_categories only
$action = $_POST['action'] ?? '';
if ($action === 'add_category') {
  Util::checkCsrf();
  $name = trim($_POST['name'] ?? '');
  $desc = trim($_POST['description'] ?? '');
  if ($name !== '') {
    $stmt = $pdo->prepare('INSERT INTO asset_categories(name, description) VALUES (?, ?)');
    $stmt->execute([$name, $desc !== '' ? $desc : null]);
  }
  Util::redirect('index.php?page=settings&tab=asset_categories');
}
if ($action === 'update_category') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $name = trim($_POST['name'] ?? '');
  $desc = trim($_POST['description'] ?? '');
  if ($id > 0 && $name !== '') {
    $stmt = $pdo->prepare('UPDATE asset_categories SET name=?, description=? WHERE id=?');
    $stmt->execute([$name, $desc !== '' ? $desc : null, $id]);
  }
  Util::redirect('index.php?page=settings&tab=asset_categories');
}
if ($action === 'delete_category') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    // Deleting is safe: assets.category_id has FK ON DELETE SET NULL
    $stmt = $pdo->prepare('DELETE FROM asset_categories WHERE id=?');
    $stmt->execute([$id]);
  }
  Util::redirect('index.php?page=settings&tab=asset_categories');
}

// Load existing categories (alphabetical)
try {
  $cats = $pdo->query('SELECT id, name, description FROM asset_categories ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $cats = [];
  echo '<div class="small muted">Could not load categories: '.Util::h($e->getMessage()).'</div>';
}
?>

<div class="settings-section">
  <h2>Add Category</h2>
  <p class="settings-description">Create a new asset category. Other pages already use this list for classification.</p>
  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
    <input type="hidden" name="action" value="add_category">
    <div class="col-4"><label>Name</label><input name="name" placeholder="e.g., Tools" required></div>
    <div class="col-8"><label>Description</label><input name="description" placeholder="Optional"></div>
    <div class="col-12 actions"><button class="btn" type="submit">Add Category</button></div>
  </form>
  <hr class="divider" />
  <div class="small muted">Deleting a category will set existing assets to no category (not delete them).</div>
  <hr class="divider" />
</div>

<div class="settings-section">
  <h2>Categories</h2>
  <?php if (!$cats): ?>
    <div class="small muted">No categories yet.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Description</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td>
              <form method="post" class="actions" style="gap:6px; align-items:center">
                <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                <input type="hidden" name="action" value="update_category">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input name="name" value="<?= Util::h($c['name']) ?>" required>
            </td>
            <td>
                <input name="description" value="<?= Util::h($c['description'] ?? '') ?>">
            </td>
            <td class="actions">
                <button class="btn sm" type="submit">Save</button>
              </form>
              <form method="post" onsubmit="return confirmAction('Delete this category? Assets will keep their data but lose this classification.')">
                <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn sm ghost danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

