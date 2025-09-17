<?php
$pdo = Database::get();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { echo '<div class="card"><h1>Not Found</h1></div>'; return; }
$st=$pdo->prepare('SELECT * FROM people WHERE id=?'); $st->execute([$id]); $p=$st->fetch();
if (!$p){ echo '<div class="card"><h1>Not Found</h1></div>'; return; }
$name = trim(($p['first_name']??'').' '.($p['last_name']??''));
$head = $pdo->prepare("SELECT id FROM files WHERE entity_type='person' AND entity_id=? AND mime_type LIKE 'image/%' ORDER BY uploaded_at DESC LIMIT 1"); $head->execute([$id]); $headId=$head->fetchColumn();
$contacts=$pdo->prepare('SELECT * FROM person_contacts WHERE person_id=? ORDER BY is_primary DESC, id DESC'); $contacts->execute([$id]); $contacts=$contacts->fetchAll();
$assets=$pdo->prepare('SELECT a.id,a.name,pa.role FROM person_assets pa JOIN assets a ON a.id=pa.asset_id WHERE pa.person_id=? ORDER BY a.name'); $assets->execute([$id]); $assets=$assets->fetchAll();
$files=$pdo->prepare("SELECT id, filename, mime_type, size FROM files WHERE entity_type='person' AND entity_id=? ORDER BY uploaded_at DESC"); $files->execute([$id]); $files=$files->fetchAll();
$auth=ensure_authenticated(); $roles=$auth['roles']??[]; $isAdmin=in_array('admin',$roles,true); $priv=[]; if ($isAdmin){ $s=$pdo->prepare('SELECT * FROM person_private WHERE person_id=?'); $s->execute([$id]); $priv=$s->fetch()?:[]; }
?>
<div class="card" style="display:grid;grid-template-columns:220px 1fr;gap:16px;align-items:start">
  <div>
    <div style="width:220px;aspect-ratio:1/1;border-radius:12px;overflow:hidden;border:1px solid var(--border);background:#fff;">
      <?php if ($headId): ?><img src="<?= Util::baseUrl('file.php?id='.(int)$headId) ?>" alt="Headshot" style="width:100%;height:100%;object-fit:cover"><?php else: ?><div style="display:flex;align-items:center;justify-content:center;height:100%;color:#9CA3AF">No Photo</div><?php endif; ?>
    </div>
  </div>
  <div>
    <div style="font-size:28px;font-weight:800;letter-spacing:.3px;"><?= Util::h($name ?: 'Unnamed') ?></div>
    <div class="asset-attributes" style="margin-top:6px">
      <div class="asset-attr"><span class="attr-label">Gender</span><span class="attr-value"><?= Util::h($p['gender'] ?? '') ?></span></div>
      <div class="asset-attr"><span class="attr-label">Birthdate</span><span class="attr-value"><?= Util::h($p['dob'] ?? '') ?></span></div>
    </div>
    <?php if ($contacts): ?>
      <div style="margin-top:10px">
        <div class="small muted" style="text-transform:uppercase;letter-spacing:.3px;font-weight:700">Contacts</div>
        <ul class="list" style="margin-top:4px">
          <?php foreach ($contacts as $c): ?>
            <li class="item"><span><?= Util::h($c['label'] ?: ucfirst($c['contact_type'])) ?></span><span><?= Util::h($c['contact_value']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($assets): ?>
      <div style="margin-top:10px">
        <div class="small muted" style="text-transform:uppercase;letter-spacing:.3px;font-weight:700">Assets</div>
        <ul class="list" style="margin-top:4px">
          <?php foreach ($assets as $a): ?>
            <li class="item"><a href="<?= Util::baseUrl('index.php?page=asset_edit&id='.(int)$a['id']) ?>"><?= Util::h($a['name']) ?></a><span><?= Util::h($a['role']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if ($isAdmin && $priv): ?>
      <div style="margin-top:10px">
        <div class="small muted" style="text-transform:uppercase;letter-spacing:.3px;font-weight:700">Private</div>
        <div class="asset-attributes" style="margin-top:4px">
          <div class="asset-attr"><span class="attr-label">SSN</span><span class="attr-value"><?= Util::h($priv['ssn'] ?? '') ?></span></div>
          <div class="asset-attr"><span class="attr-label">Driver License</span><span class="attr-value"><?= Util::h($priv['driver_license'] ?? '') ?></span></div>
          <div class="asset-attr"><span class="attr-label">Passport</span><span class="attr-value"><?= Util::h($priv['passport_number'] ?? '') ?></span></div>
        </div>
        <?php if (!empty($priv['medical_notes'])): ?><div class="small" style="margin-top:6px"><?= nl2br(Util::h($priv['medical_notes'])) ?></div><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($p['notes'])): ?><div class="small" style="margin-top:10px">Notes: <?= nl2br(Util::h($p['notes'])) ?></div><?php endif; ?>
  </div>
</div>

<?php if ($files): ?>
  <div class="card" style="margin-top:12px">
    <div class="section-head"><h2>Files</h2></div>
    <div class="table-wrap"><table>
      <thead><tr><th>File</th><th>Type</th><th>Size</th></tr></thead>
      <tbody>
        <?php foreach ($files as $f): ?>
          <tr><td><a href="<?= Util::baseUrl('file.php?id='.(int)$f['id']) ?>" target="_blank"><?= Util::h($f['filename']) ?></a></td><td><?= Util::h($f['mime_type']) ?></td><td><?= number_format($f['size']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>

