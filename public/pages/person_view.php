<?php
$pdo = Database::get();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { echo '<div class="card"><h1>Not Found</h1></div>'; return; }
$st=$pdo->prepare('SELECT * FROM people WHERE id=?'); $st->execute([$id]); $p=$st->fetch();
if (!$p){ echo '<div class="card"><h1>Not Found</h1></div>'; return; }
$name = trim(($p['first_name']??'').' '.($p['last_name']??''));
$contacts=$pdo->prepare('SELECT * FROM person_contacts WHERE person_id=? ORDER BY is_primary DESC, id DESC'); $contacts->execute([$id]); $contacts=$contacts->fetchAll();
$assets=$pdo->prepare('SELECT a.id,a.name,pa.role FROM person_assets pa JOIN assets a ON a.id=pa.asset_id WHERE pa.person_id=? ORDER BY a.name'); $assets->execute([$id]); $assets=$assets->fetchAll();
$files=$pdo->prepare("SELECT id, filename, mime_type, size, uploaded_at, caption FROM files WHERE entity_type='person' AND entity_id=? ORDER BY uploaded_at DESC"); $files->execute([$id]); $files=$files->fetchAll();
$auth=ensure_authenticated(); $roles=$auth['roles']??[]; $isAdmin=in_array('admin',$roles,true); $priv=[]; $s=$pdo->prepare('SELECT * FROM person_private WHERE person_id=?'); $s->execute([$id]); $priv=$s->fetch()?:[];

function findByCaption($files,$cap){ foreach($files as $f){ if (strtolower(trim($f['caption'] ?? ''))===strtolower($cap)) return $f; } return null; }
function daysUntilBirthday($dob){ if(!$dob) return null; $ts=strtotime($dob); if(!$ts) return null; $m=(int)date('n',$ts); $d=(int)date('j',$ts); $y=(int)date('Y'); $next=strtotime($y.'-'.$m.'-'.$d); if($next<strtotime('today')) $next=strtotime(($y+1).'-'.$m.'-'.$d); $diff=(int)ceil(($next - strtotime('today'))/86400); return $diff; }

$initials = strtoupper(substr($p['first_name']??'',0,1).substr($p['last_name']??'',0,1)); if ($initials==='') $initials='ID';
$pal = [ ['#E8F1FF','#2563eb'], ['#FCE8EC','#db2777'], ['#EEE5FF','#7c3aed'], ['#E6F7F3','#0f766e'], ['#FFF3D6','#b45309'], ['#E6F6FF','#0369a1'] ];
$pidx = abs(crc32($name ?: (string)$id)) % count($pal); $bg=$pal[$pidx][0]; $fg=$pal[$pidx][1];
?>

<div class="person-header">
  <div class="ph-left">
    <div class="ph-avatar" style="background: <?= $bg ?>; color: <?= $fg ?>;"><?= Util::h($initials) ?></div>
    <div class="ph-meta">
      <div class="ph-name"><?= Util::h($name ?: 'Unnamed') ?></div>
      <div class="ph-sub small muted"><?php $days=daysUntilBirthday($p['dob']??null); if($days!==null): ?>Birthday in <?= (int)$days ?> days<?php endif; ?></div>
    </div>
  </div>
  <div class="ph-actions">
    <a class="btn sm" href="<?= Util::baseUrl('index.php?page=person_edit&id='.$id) ?>">Edit</a>
  </div>
</div>

<div class="tabbar">
  <a href="#" class="active" data-tab-link="overview">Overview</a>
  <a href="#" data-tab-link="files">Files</a>
  <a href="#" data-tab-link="notes">Notes</a>
</div>

<div class="person-layout">
  <div class="person-main">
    <div class="card tab-panel show" data-tab-panel="overview">
      <div class="basic-details" style="margin-bottom:12px">
        <div class="section-head"><h2>General Info</h2></div>
        <div class="row">
          <div class="col-6"><label>Birthday</label>
            <div><strong><?= Util::h($p['dob'] ?? '') ?></strong></div>
            <?php $d=daysUntilBirthday($p['dob']??null); if($d!==null): ?><div class="small muted">Birthday in <?= (int)$d ?> days</div><?php endif; ?>
          </div>
          <div class="col-6"><label>Gender</label><div><?= Util::h(ucwords(str_replace('_',' ',$p['gender'] ?? ''))) ?></div></div>
        </div>
      </div>
      <?php
        $defs = [
          ['key'=>'dl','label'=>"Driver's License"],
          ['key'=>'passport','label'=>'Passport'],
          ['key'=>'ssn','label'=>'Social Security'],
          ['key'=>'birth','label'=>'Birth Certificate'],
          ['key'=>'global','label'=>'Global Entry'],
          ['key'=>'boat','label'=>'Boat License'],
        ];
        function mask_mid($s){ $s=preg_replace('/\D+/','',$s); if(strlen($s)<=4) return $s; return str_repeat('•', max(0, strlen($s)-4)).substr($s,-4); }
      ?>
      <?php foreach ($defs as $def): $k=$def['key']; $front=findByCaption($files, $k.'_front'); $back=findByCaption($files, $k.'_back'); ?>
        <div class="id-row">
          <div class="id-title"><?= Util::h($def['label']) ?></div>
          <div class="id-slots">
            <div class="id-slot">
              <?php if ($front && strpos($front['mime_type'],'image/')===0): ?>
                <img class="id-prev" src="<?= Util::baseUrl('file.php?id='.(int)$front['id']) ?>" alt="Front">
              <?php else: ?><div class="dz"><div class="dz-title small muted">No file</div></div><?php endif; ?>
            </div>
            <div class="id-slot">
              <?php if ($back && strpos($back['mime_type'],'image/')===0): ?>
                <img class="id-prev" src="<?= Util::baseUrl('file.php?id='.(int)$back['id']) ?>" alt="Back">
              <?php else: ?><div class="dz"><div class="dz-title small muted">No file</div></div><?php endif; ?>
            </div>
          </div>
          <div class="id-fields row" style="margin-top:6px">
            <?php if ($k==='dl'): ?>
              <div class="col-4"><label>License Number</label><div><?= Util::h(mask_mid($priv['driver_license'] ?? '')) ?></div></div>
              <div class="col-4"><label>State Issued</label><div><?= Util::h($priv['dl_state'] ?? '') ?></div></div>
              <div class="col-4"><label>Expiration</label><div><?= Util::h($priv['dl_expiration'] ?? '') ?></div></div>
            <?php elseif ($k==='passport'): ?>
              <div class="col-4"><label>Passport Number</label><div><?= Util::h(mask_mid($priv['passport_number'] ?? '')) ?></div></div>
              <div class="col-4"><label>Country</label><div><?= Util::h($priv['passport_country'] ?? '') ?></div></div>
              <div class="col-4"><label>Expiration</label><div><?= Util::h($priv['passport_expiration'] ?? '') ?></div></div>
            <?php elseif ($k==='ssn'): ?>
              <div class="col-4"><label>SSN</label><div><?= Util::h(mask_mid($priv['ssn'] ?? '')) ?></div></div>
            <?php elseif ($k==='birth'): ?>
              <div class="col-6"><label>Certificate #</label><div><?= Util::h($priv['birth_certificate_number'] ?? '') ?></div></div>
            <?php elseif ($k==='global'): ?>
              <div class="col-6"><label>Global Entry #</label><div><?= Util::h(mask_mid($priv['global_entry_number'] ?? '')) ?></div></div>
              <div class="col-6"><label>Expiration</label><div><?= Util::h($priv['global_entry_expiration'] ?? '') ?></div></div>
            <?php elseif ($k==='boat'): ?>
              <div class="col-6"><label>Boat License #</label><div><?= Util::h(mask_mid($priv['boat_license_number'] ?? '')) ?></div></div>
              <div class="col-6"><label>Expiration</label><div><?= Util::h($priv['boat_license_expiration'] ?? '') ?></div></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card tab-panel" data-tab-panel="files">
      <?php if ($files): ?>
        <div class="table-wrap" style="margin-top:6px"><table>
          <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Uploaded</th><th>Caption</th></tr></thead>
          <tbody>
            <?php foreach ($files as $f): ?>
              <tr><td><a href="<?= Util::baseUrl('file.php?id='.(int)$f['id']) ?>" target="_blank"><?= Util::h($f['filename']) ?></a></td><td><?= Util::h($f['mime_type']) ?></td><td><?= number_format($f['size']) ?></td><td><?= Util::h($f['uploaded_at']) ?></td><td><?= Util::h($f['caption'] ?? '') ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <div class="small muted">No files yet.</div>
      <?php endif; ?>
    </div>

    <div class="card tab-panel" data-tab-panel="notes">
      <?php if (!empty($p['notes'])): ?>
        <div class="small"><?= nl2br(Util::h($p['notes'])) ?></div>
      <?php else: ?>
        <div class="small muted">No notes yet.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="person-side">
    <?php if ($contacts): ?>
      <div class="card">
        <div class="section-head"><h2>Contact Information</h2></div>
        <ul class="list" style="margin-top:8px">
          <?php foreach ($contacts as $c): ?>
            <li class="item"><span><?= Util::h($c['label'] ?: ucfirst($c['contact_type'])) ?></span><span><?= Util::h($c['contact_value']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($assets): ?>
      <div class="card">
        <div class="section-head"><h2>Linked Assets</h2></div>
        <ul class="list" style="margin-top:8px">
          <?php foreach ($assets as $a): ?>
            <li class="item"><a href="<?= Util::baseUrl('index.php?page=asset_edit&id='.(int)$a['id']) ?>"><?= Util::h($a['name']) ?></a><span><?= Util::h($a['role']) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</div>
