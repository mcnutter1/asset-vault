<?php
$pdo = Database::get();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;

// Save person
if (($_POST['action'] ?? '') === 'save'){
  Util::checkCsrf();
  $first = trim($_POST['first_name'] ?? '');
  $last = trim($_POST['last_name'] ?? '');
  $gender = $_POST['gender'] ?? null; if ($gender==='') $gender=null;
  $dob = $_POST['dob'] ?? null; if ($dob==='') $dob=null;
  $notes = trim($_POST['notes'] ?? '');
  if ($isEdit){
    $stmt=$pdo->prepare('UPDATE people SET first_name=?, last_name=?, gender=?, dob=?, notes=? WHERE id=?');
    $stmt->execute([$first,$last,$gender,$dob,$notes,$id]);
  } else {
    $stmt=$pdo->prepare('INSERT INTO people(first_name,last_name,gender,dob,notes) VALUES (?,?,?,?,?)');
    $stmt->execute([$first,$last,$gender,$dob,$notes]);
    $id=(int)$pdo->lastInsertId(); $isEdit=true;
  }
  // Private block (admin only)
  $auth = ensure_authenticated(); $roles = $auth['roles']??[]; $isAdmin = in_array('admin',$roles,true);
  if ($isAdmin){
    $ssn = trim($_POST['priv_ssn'] ?? '');
    $dl = trim($_POST['priv_dl'] ?? '');
    $pp = trim($_POST['priv_passport'] ?? '');
    $med = trim($_POST['priv_medical'] ?? '');
    $stmt=$pdo->prepare('INSERT INTO person_private(person_id, ssn, driver_license, passport_number, medical_notes) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE ssn=VALUES(ssn), driver_license=VALUES(driver_license), passport_number=VALUES(passport_number), medical_notes=VALUES(medical_notes)');
    $stmt->execute([$id,$ssn,$dl,$pp,$med]);
  }
  // Simple contact add if provided inline
  if (($_POST['new_contact_value'] ?? '') !== ''){
    $ct = $_POST['new_contact_type'] ?? 'phone';
    $cl = trim($_POST['new_contact_label'] ?? '');
    $cv = trim($_POST['new_contact_value'] ?? '');
    $stmt=$pdo->prepare('INSERT INTO person_contacts(person_id,contact_type,label,contact_value,is_primary) VALUES (?,?,?,?,0)');
    $stmt->execute([$id,$ct,$cl,$cv]);
  }
  // Link asset if provided
  if (($_POST['link_asset_id'] ?? '') !== ''){
    $aid=(int)$_POST['link_asset_id']; $role=$_POST['link_asset_role']??'other';
    $pdo->prepare('INSERT IGNORE INTO person_assets(person_id, asset_id, role) VALUES (?,?,?)')->execute([$id,$aid,$role]);
  }
  // Upload files (basic)
  if (!empty($_FILES['files']['name'][0])){
    for($i=0;$i<count($_FILES['files']['name']);$i++){
      if ($_FILES['files']['error'][$i]===UPLOAD_ERR_OK){
        $tmp=$_FILES['files']['tmp_name'][$i]; $orig=$_FILES['files']['name'][$i]; $mime=$_FILES['files']['type'][$i]?:'application/octet-stream'; $size=(int)$_FILES['files']['size'][$i]; $content=@file_get_contents($tmp);
        $stmt=$pdo->prepare("INSERT INTO files(entity_type, entity_id, filename, mime_type, size, content) VALUES ('person', ?, ?, ?, ?, ?)");
        $stmt->bindValue(1,$id,PDO::PARAM_INT); $stmt->bindValue(2,$orig); $stmt->bindValue(3,$mime); $stmt->bindValue(4,$size,PDO::PARAM_INT); $stmt->bindParam(5,$content,PDO::PARAM_LOB); $stmt->execute();
      }
    }
  }
  Util::redirect('index.php?page=person_edit&id='.$id);
}

// Delete contact
if ($isEdit && (($_POST['action'] ?? '')==='delete_contact')){
  Util::checkCsrf(); $cid=(int)($_POST['contact_id']??0); $pdo->prepare('DELETE FROM person_contacts WHERE id=? AND person_id=?')->execute([$cid,$id]); Util::redirect('index.php?page=person_edit&id='.$id);
}
// Unlink asset
if ($isEdit && (($_POST['action'] ?? '')==='unlink_asset')){
  Util::checkCsrf(); $aid=(int)($_POST['asset_id']??0); $pdo->prepare('DELETE FROM person_assets WHERE person_id=? AND asset_id=?')->execute([$id,$aid]); Util::redirect('index.php?page=person_edit&id='.$id);
}

// Load data
$person = ['first_name'=>'','last_name'=>'','gender'=>null,'dob'=>null,'notes'=>''];
if ($isEdit){ $st=$pdo->prepare('SELECT * FROM people WHERE id=?'); $st->execute([$id]); $person=$st->fetch()?:$person; }
$contacts=[]; if ($isEdit){ $st=$pdo->prepare('SELECT * FROM person_contacts WHERE person_id=? ORDER BY is_primary DESC, id DESC'); $st->execute([$id]); $contacts=$st->fetchAll(); }
$assets=$pdo->query('SELECT id,name FROM assets WHERE is_deleted=0 ORDER BY name')->fetchAll();
$linkedAssets=[]; if ($isEdit){ $st=$pdo->prepare('SELECT pa.asset_id,a.name,pa.role FROM person_assets pa JOIN assets a ON a.id=pa.asset_id WHERE pa.person_id=? ORDER BY a.name'); $st->execute([$id]); $linkedAssets=$st->fetchAll(); }
$files=[]; if ($isEdit){ $st=$pdo->prepare("SELECT id, filename, mime_type, size, uploaded_at, caption FROM files WHERE entity_type='person' AND entity_id=? ORDER BY uploaded_at DESC"); $st->execute([$id]); $files=$st->fetchAll(); }
// Private
$auth = ensure_authenticated(); $roles=$auth['roles']??[]; $isAdmin=in_array('admin',$roles,true); $priv=[]; if ($isAdmin && $isEdit){ $st=$pdo->prepare('SELECT * FROM person_private WHERE person_id=?'); $st->execute([$id]); $priv=$st->fetch()?:[]; }
?>

<?php
// Avatar + name styling setup
$name = trim(($person['first_name']??'').' '.($person['last_name']??''));
$initials = strtoupper(substr($person['first_name']??'',0,1).substr($person['last_name']??'',0,1)); if ($initials==='') $initials='ID';
$pal = [ ['#E8F1FF','#2563eb'], ['#FCE8EC','#db2777'], ['#EEE5FF','#7c3aed'], ['#E6F7F3','#0f766e'], ['#FFF3D6','#b45309'], ['#E6F6FF','#0369a1'] ];
$pidx = abs(crc32($name ?: (string)$id)) % count($pal); $bg=$pal[$pidx][0]; $fg=$pal[$pidx][1];
function findByCaption($files,$cap){ foreach($files as $f){ if (strtolower(trim($f['caption'] ?? ''))===strtolower($cap)) return $f; } return null; }
function daysUntilBirthday($dob){ if(!$dob) return null; $ts=strtotime($dob); if(!$ts) return null; $m=(int)date('n',$ts); $d=(int)date('j',$ts); $y=(int)date('Y'); $next=strtotime($y.'-'.$m.'-'.$d); if($next<strtotime('today')) $next=strtotime(($y+1).'-'.$m.'-'.$d); $diff=(int)ceil(($next - strtotime('today'))/86400); return $diff; }
?>

<div class="person-header">
  <div class="ph-left">
    <div class="ph-avatar" style="background: <?= $bg ?>; color: <?= $fg ?>;">
      <?= Util::h($initials) ?>
    </div>
    <div class="ph-meta">
      <div class="ph-name">
        <form method="post" id="personHeaderForm" class="ph-form">
          <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
          <input type="hidden" name="action" value="save">
          <div class="row" style="align-items:end; gap:8px">
            <div class="col-6"><input class="ph-input" name="first_name" placeholder="First name" value="<?= Util::h($person['first_name'] ?? '') ?>" required></div>
            <div class="col-6"><input class="ph-input" name="last_name" placeholder="Last name" value="<?= Util::h($person['last_name'] ?? '') ?>" required></div>
          </div>
          <div class="row" style="gap:8px;margin-top:6px">
            <div class="col-3"><select name="gender" class="ph-select"><?php $genders=['','male','female','nonbinary','other','prefer_not']; foreach($genders as $g): if($g===''){ echo '<option value="">Gender</option>'; continue; } ?><option value="<?= $g ?>" <?= ($person['gender']??'')===$g?'selected':'' ?>><?= ucwords(str_replace('_',' ',$g)) ?></option><?php endforeach; ?></select></div>
            <div class="col-4"><input type="date" class="ph-date" name="dob" value="<?= Util::h($person['dob'] ?? '') ?>"></div>
            <?php $days=daysUntilBirthday($person['dob']??null); if($days!==null): ?><div class="col-5 small muted" style="padding-top:10px">Birthday in <?= (int)$days ?> days</div><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="ph-actions">
    <a class="btn ghost sm" href="<?= Util::baseUrl('index.php?page=people') ?>">Back</a>
    <button class="btn sm" type="submit" form="personHeaderForm">Save</button>
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
      <div class="id-section">
        <?php $pidAttr = $isEdit? 'data-person-id="'.$id.'"' : 'data-disabled="1"'; ?>
        <?php
          $defs = [
            ['key'=>'dl','label'=>"Driver's License"],
            ['key'=>'passport','label'=>'Passport'],
            ['key'=>'ssn','label'=>'Social Security'],
            ['key'=>'birth','label'=>'Birth Certificate'],
            ['key'=>'global','label'=>'Global Entry'],
            ['key'=>'boat','label'=>'Boat License'],
          ];
        ?>
        <?php foreach ($defs as $def): $k=$def['key']; $front=findByCaption($files, $k.'_front'); $back=findByCaption($files, $k.'_back'); ?>
          <div class="id-row">
            <div class="id-title"><?= Util::h($def['label']) ?></div>
            <div class="id-slots">
              <div class="id-slot" data-id-slot data-caption="<?= Util::h($k.'_front') ?>" <?= $pidAttr ?>>
                <?php if ($front && strpos($front['mime_type'],'image/')===0): ?>
                  <img class="id-prev" src="<?= Util::baseUrl('file.php?id='.(int)$front['id']) ?>" alt="Front">
                <?php endif; ?>
                <div class="dz"><div class="dz-icon">⬆️</div><div class="dz-title">Drop front side here</div><div class="dz-sub">or <span class="link">Browse files</span></div></div>
                <input type="file" accept="image/*" hidden>
              </div>
              <div class="id-slot" data-id-slot data-caption="<?= Util::h($k.'_back') ?>" <?= $pidAttr ?>>
                <?php if ($back && strpos($back['mime_type'],'image/')===0): ?>
                  <img class="id-prev" src="<?= Util::baseUrl('file.php?id='.(int)$back['id']) ?>" alt="Back">
                <?php endif; ?>
                <div class="dz"><div class="dz-icon">⬆️</div><div class="dz-title">Drop back side here</div><div class="dz-sub">or <span class="link">Browse files</span></div></div>
                <input type="file" accept="image/*" hidden>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card tab-panel" data-tab-panel="files">
      <div class="id-row">
        <div class="id-title">Upload</div>
        <div class="id-content">
          <div class="id-drop" data-files-drop <?= $pidAttr ?>>
            <div class="dz"><div class="dz-icon">⬆️</div><div class="dz-title">Drop files here</div><div class="dz-sub">or <span class="link">Browse files</span></div></div>
            <input type="file" multiple hidden>
          </div>
        </div>
      </div>
      <?php if ($files): ?>
        <div class="table-wrap" style="margin-top:6px"><table>
          <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Uploaded</th><th>Caption</th></tr></thead>
          <tbody>
            <?php foreach ($files as $f): ?>
              <tr><td><a href="<?= Util::baseUrl('file.php?id='.(int)$f['id']) ?>" target="_blank"><?= Util::h($f['filename']) ?></a></td><td><?= Util::h($f['mime_type']) ?></td><td><?= number_format($f['size']) ?></td><td><?= Util::h($f['uploaded_at']) ?></td><td><?= Util::h($f['caption'] ?? '') ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

    <div class="card tab-panel" data-tab-panel="notes">
      <label>Notes</label>
      <textarea name="notes" rows="6" form="personHeaderForm" placeholder="Type a new note…"><?= Util::h($person['notes'] ?? '') ?></textarea>
      <div class="actions" style="margin-top:8px"><button class="btn" type="submit" form="personHeaderForm">Save</button></div>
    </div>
  </div>

  <div class="person-side">
    <div class="card">
      <div class="section-head"><h2>Contact Information</h2></div>
      <div class="row">
        <div class="col-4"><label>Type</label><select name="new_contact_type" form="personHeaderForm"><option value="phone">Phone</option><option value="email">Email</option><option value="other">Other</option></select></div>
        <div class="col-4"><label>Label</label><input name="new_contact_label" form="personHeaderForm" placeholder="Mobile"></div>
        <div class="col-4"><label>Value</label><input name="new_contact_value" form="personHeaderForm" placeholder="(555) 123‑4567"></div>
        <div class="col-12 actions"><button class="btn sm" type="submit" form="personHeaderForm">Add</button></div>
      </div>
      <?php if ($contacts): ?>
        <ul class="list" style="margin-top:8px">
          <?php foreach ($contacts as $c): ?>
            <li class="item">
              <span><?= Util::h($c['label'] ?: ucfirst($c['contact_type'])) ?></span>
              <span style="display:flex;gap:8px;align-items:center">
                <span><?= Util::h($c['contact_value']) ?></span>
                <form method="post" onsubmit="return confirmAction('Delete contact?')">
                  <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                  <input type="hidden" name="action" value="delete_contact">
                  <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn sm ghost danger">🗑️</button>
                </form>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <?php if ($linkedAssets): ?>
    <div class="card">
      <div class="section-head"><h2>Linked Assets</h2></div>
      <div class="table-wrap" style="margin-top:6px"><table>
        <thead><tr><th>Asset</th><th>Role</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($linkedAssets as $la): ?>
            <tr>
              <td><?= Util::h($la['name']) ?></td><td><?= Util::h($la['role']) ?></td>
              <td>
                <form method="post" onsubmit="return confirmAction('Unlink asset?')">
                  <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                  <input type="hidden" name="action" value="unlink_asset">
                  <input type="hidden" name="asset_id" value="<?= (int)$la['asset_id'] ?>">
                  <button class="btn sm ghost danger">🗑️</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="section-head"><h2>Link Asset</h2></div>
      <div class="row">
        <div class="col-8"><label>Asset</label>
          <select name="link_asset_id" form="personHeaderForm"><option value="">--</option><?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= Util::h($a['name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-4"><label>Role</label><select name="link_asset_role" form="personHeaderForm"><option>owner</option><option>user</option><option>resident</option><option>other</option></select></div>
        <div class="col-12 actions"><button class="btn sm" type="submit" form="personHeaderForm">Link</button></div>
      </div>
    </div>
  </div>
</div>
