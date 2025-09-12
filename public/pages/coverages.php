<?php
$pdo = Database::get();

// Add new coverage definition
if (($_POST['action'] ?? '') === 'add') {
  Util::checkCsrf();
  $code = trim($_POST['code'] ?? '');
  $name = trim($_POST['name'] ?? '');
  $desc = trim($_POST['description'] ?? '');
  $types = $_POST['types'] ?? [];
  $set = implode(',', array_map('trim', $types));
  $stmt = $pdo->prepare('INSERT INTO coverage_definitions(code, name, description, applicable_types) VALUES (?,?,?,?)');
  $stmt->execute([$code, $name, $desc, $set]);
  Util::redirect('index.php?page=coverages');
}

// Remove coverage definition (only if unused)
if (($_POST['action'] ?? '') === 'remove') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $inUse = (int)$pdo->query('SELECT COUNT(*) FROM policy_coverages WHERE coverage_definition_id='.(int)$id)->fetchColumn();
  if (!$inUse) {
    $pdo->prepare('DELETE FROM coverage_definitions WHERE id=?')->execute([$id]);
  }
  Util::redirect('index.php?page=coverages');
}

$rows = $pdo->query('SELECT * FROM coverage_definitions ORDER BY name')->fetchAll();
?>
<div class="row">
  <div class="col-6">
    <div class="card">
      <h1>Coverage Library</h1>
      <table>
        <thead><tr><th>Code</th><th>Name</th><th>Types</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= Util::h($r['code']) ?></td>
            <td><?= Util::h($r['name']) ?></td>
            <td><span class="small muted"><?= Util::h($r['applicable_types']) ?></span></td>
            <td>
              <form method="post" onsubmit="return confirmAction('Remove coverage definition? (only if unused)')">
                <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn ghost danger" type="submit">Remove</button>
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
      <h2>Add Coverage</h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
        <input type="hidden" name="action" value="add">
        <div class="row">
          <div class="col-6"><label>Code</label><input name="code" required></div>
          <div class="col-6"><label>Name</label><input name="name" required></div>
          <div class="col-12"><label>Description</label><input name="description"></div>
          <div class="col-12">
            <label>Applicable Types</label>
            <?php $types=['home','auto','boat','flood','umbrella','jewelry','electronics','other']; ?>
            <div class="list">
              <?php foreach ($types as $t): ?>
                <label style="display:inline-flex;gap:6px;align-items:center"><input type="checkbox" name="types[]" value="<?= $t ?>"> <?= ucfirst($t) ?></label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="col-12 actions"><button class="btn" type="submit">Add</button></div>
        </div>
      </form>
    </div>
  </div>
</div>

