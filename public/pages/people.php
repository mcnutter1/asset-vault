<?php
$pdo = Database::get();
$q = trim($_GET['q'] ?? '');
$stmt = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM files f WHERE f.entity_type='person' AND f.entity_id=p.id) AS items_count FROM people p WHERE CONCAT_WS(' ', p.first_name, p.last_name) LIKE ? ORDER BY p.last_name, p.first_name");
$stmt->execute(['%'.$q.'%']);
$people = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <div class="section-head" style="margin-bottom:8px">
    <h1>Family IDs</h1>
    <div class="actions">
      <form method="get" class="actions" style="gap:6px">
        <input type="hidden" name="page" value="people">
        <input name="q" placeholder="Search name…" value="<?= Util::h($q) ?>">
        <button class="btn sm">Search</button>
      </form>
      <a class="btn sm" href="<?= Util::baseUrl('index.php?page=person_edit') ?>">+ Add Person</a>
    </div>
  </div>
  <?php if (!$people): ?>
    <div class="small muted">No people found.</div>
  <?php else: ?>
    <div class="person-grid">
      <?php foreach ($people as $p): $name = trim(($p['first_name']??'').' '.($p['last_name']??'')); $initials = strtoupper(substr($p['first_name']??'',0,1).substr($p['last_name']??'',0,1)); $items=(int)($p['items_count']??0); ?>
        <a class="person-card" href="<?= Util::baseUrl('index.php?page=person_view&id='.(int)$p['id']) ?>">
          <div class="pc-top">
            <div class="pc-name"><?= Util::h($name ?: 'Unnamed') ?></div>
            <div class="pc-avatar"><?= Util::h($initials ?: 'ID') ?></div>
          </div>
          <div class="pc-foot"><span class="pc-items"><?= $items ?> <?= $items===1?'item':'items' ?></span></div>
        </a>
      <?php endforeach; ?>
      <a class="person-card add" href="<?= Util::baseUrl('index.php?page=person_edit') ?>">
        <div class="pc-top"><div class="pc-name">New Family ID</div><div class="pc-avatar">+</div></div>
        <div class="pc-foot"><span class="pc-items">Add this item</span></div>
      </a>
    </div>
  <?php endif; ?>
</div>
