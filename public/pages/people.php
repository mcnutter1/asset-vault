<?php
$pdo = Database::get();
$q = trim($_GET['q'] ?? '');
$stmt = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM files f WHERE f.entity_type='person' AND f.entity_id=p.id) AS items_count FROM people p WHERE CONCAT_WS(' ', p.first_name, p.last_name) LIKE ? ORDER BY p.last_name, p.first_name");
$stmt->execute(['%'.$q.'%']);
$people = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Simple color palette for avatars (soft backgrounds with readable text)
$palette = [
  ['bg'=>'#E8F1FF','fg'=>'#2563eb'], // blue
  ['bg'=>'#FCE8EC','fg'=>'#db2777'], // pink
  ['bg'=>'#EEE5FF','fg'=>'#7c3aed'], // purple
  ['bg'=>'#E6F7F3','fg'=>'#0f766e'], // teal
  ['bg'=>'#FFF3D6','fg'=>'#b45309'], // amber
  ['bg'=>'#E6F6FF','fg'=>'#0369a1'], // sky
];
?>

<div class="people-hero">
  <div class="topline">
    <div class="title">Family IDs</div>
    <a class="btn circle" title="Add Person" href="<?= Util::baseUrl('index.php?page=person_edit') ?>">+</a>
    <a class="rec-link" href="#" onclick="return false">⚡ {placeholder}</a>
  </div>
  <form method="get" class="searchbar" role="search">
    <input type="hidden" name="page" value="people">
    <input name="q" value="<?= Util::h($q) ?>" placeholder="Search" aria-label="Search people">
  </form>
</div>

<div class="people-section-title">People</div>

<?php if (!$people): ?>
  <div class="small muted">No people found.</div>
<?php endif; ?>

<div class="person-grid">
  <?php foreach ($people as $p):
    $name = trim(($p['first_name']??'').' '.($p['last_name']??''));
    $initials = strtoupper(substr($p['first_name']??'',0,1).substr($p['last_name']??'',0,1));
    $items=(int)($p['items_count']??0);
    $idx = abs(crc32($name ?: (string)$p['id'])) % count($palette);
    $bg = $palette[$idx]['bg']; $fg = $palette[$idx]['fg'];
  ?>
    <a class="person-card" href="<?= Util::baseUrl('index.php?page=person_view&id='.(int)$p['id']) ?>">
      <div class="pc-top">
        <div class="pc-name"><?= Util::h($name ?: 'Unnamed') ?></div>
        <div class="pc-avatar" style="background: <?= $bg ?>; color: <?= $fg ?>;">
          <?= Util::h($initials ?: 'ID') ?>
        </div>
      </div>
      <div class="pc-foot">
        <span class="pc-items">⚡ <?= $items ?> <?= $items===1?'item':'items' ?></span>
      </div>
    </a>
  <?php endforeach; ?>


</div>
