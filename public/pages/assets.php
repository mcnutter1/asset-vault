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

// Fetch assets tree
$assets = $pdo->query('SELECT a.id, a.name, a.parent_id, ac.name AS category, l.name AS locname FROM assets a 
  LEFT JOIN asset_categories ac ON ac.id=a.category_id 
  LEFT JOIN locations l ON l.id=a.location_id
  WHERE a.is_deleted=0 
  ORDER BY a.parent_id IS NOT NULL, a.parent_id, a.name')->fetchAll();
$byParent = [];
foreach ($assets as $a) { $byParent[$a['parent_id'] ?? 0][] = $a; }

function renderTree($parentId, $byParent) {
  if (empty($byParent[$parentId])) return;
  echo '<ul class="tree">';
  foreach ($byParent[$parentId] as $a) {
    echo '<li>';
    echo '<span class="name">'.Util::h($a['name']).'</span> <span class="small muted">'.Util::h($a['category'])."</span> ";
    if (!empty($a['locname'])) echo '<span class="pill">'.Util::h($a['locname']).'</span> ';
    echo ' <a class="btn ghost" href="'.Util::baseUrl('index.php?page=asset_edit&id='.(int)$a['id']).'">Edit</a>';
    echo ' <form method="post" style="display:inline" onsubmit="return confirmAction(\'Delete asset?\')">';
    echo '<input type="hidden" name="csrf" value="'.Util::csrfToken().'">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<input type="hidden" name="id" value="'.(int)$a['id'].'">';
    echo '<button class="btn ghost danger" type="submit">Delete</button>';
    echo '</form>';
    renderTree($a['id'], $byParent);
    echo '</li>';
  }
  echo '</ul>';
}
?>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
    <h1>Assets</h1>
    <a class="btn" href="<?= Util::baseUrl('index.php?page=asset_edit') ?>">Add Asset</a>
  </div>
  <?php renderTree(0, $byParent); ?>
</div>
