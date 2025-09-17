<?php
$pdo = Database::get();
$q = trim($_GET['q'] ?? '');
$stmt = $pdo->prepare("SELECT p.*, (SELECT id FROM files f WHERE f.entity_type='person' AND f.entity_id=p.id AND f.mime_type LIKE 'image/%' ORDER BY f.uploaded_at DESC LIMIT 1) AS headshot_id FROM people p WHERE CONCAT_WS(' ', p.first_name, p.last_name) LIKE ? ORDER BY p.last_name, p.first_name");
$stmt->execute(['%'.$q.'%']);
$people = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <div class="section-head" style="margin-bottom:8px">
    <h1>People</h1>
    <div class="actions">
      <form method="get" class="actions" style="gap:6px">
        <input type="hidden" name="page" value="people">
        <input name="q" placeholder="Search name…" value="<?= Util::h($q) ?>">
        <button class="btn sm">Search</button>
      </form>
      <a class="btn sm" href="<?= Util::baseUrl('index.php?page=person_edit') ?>">Add Person</a>
    </div>
  </div>
  <?php if (!$people): ?>
    <div class="small muted">No people found.</div>
  <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;">
      <?php foreach ($people as $p): $name = trim(($p['first_name']??'').' '.($p['last_name']??'')); $img = $p['headshot_id']? Util::baseUrl('file.php?id='.(int)$p['headshot_id']) : null; ?>
        <div class="card" style="padding:10px; text-align:center">
          <a href="<?= Util::baseUrl('index.php?page=person_view&id='.(int)$p['id']) ?>" style="text-decoration:none;color:inherit">
            <div style="width:100%;aspect-ratio:1/1;border-radius:12px;overflow:hidden;border:1px solid var(--border);background:#fff;margin-bottom:8px;">
              <?php if ($img): ?><img src="<?= $img ?>" alt="Headshot" style="width:100%;height:100%;object-fit:cover"><?php else: ?><div style="display:flex;align-items:center;justify-content:center;height:100%;color:#9CA3AF">No Photo</div><?php endif; ?>
            </div>
            <div style="font-weight:800;"><?= Util::h($name ?: 'Unnamed') ?></div>
          </a>
          <div class="actions" style="justify-content:center;margin-top:6px">
            <a class="btn sm ghost" href="<?= Util::baseUrl('index.php?page=person_edit&id='.(int)$p['id']) ?>">Edit</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

