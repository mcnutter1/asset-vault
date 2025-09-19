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
  // Private/ID details (stored in person_private)
  $ssn = trim($_POST['priv_ssn'] ?? '');
  $dl = trim($_POST['priv_dl'] ?? '');
  $pp = trim($_POST['priv_passport'] ?? '');
  $med = trim($_POST['priv_medical'] ?? '');
  $dl_state = trim($_POST['priv_dl_state'] ?? '');
  $dl_exp = ($_POST['priv_dl_expiration'] ?? '') ?: null;
  $pp_country = trim($_POST['priv_passport_country'] ?? '');
  $pp_exp = ($_POST['priv_passport_expiration'] ?? '') ?: null;
  $ge_no = trim($_POST['priv_global_entry_number'] ?? '');
  $ge_exp = ($_POST['priv_global_entry_expiration'] ?? '') ?: null;
  $bc_no = trim($_POST['priv_birth_certificate_number'] ?? '');
  $boat_no = trim($_POST['priv_boat_license_number'] ?? '');
  $boat_exp = ($_POST['priv_boat_license_expiration'] ?? '') ?: null;
  $stmt=$pdo->prepare('INSERT INTO person_private(person_id, ssn, driver_license, passport_number, medical_notes, dl_state, dl_expiration, passport_country, passport_expiration, global_entry_number, global_entry_expiration, birth_certificate_number, boat_license_number, boat_license_expiration) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ssn=VALUES(ssn), driver_license=VALUES(driver_license), passport_number=VALUES(passport_number), medical_notes=VALUES(medical_notes), dl_state=VALUES(dl_state), dl_expiration=VALUES(dl_expiration), passport_country=VALUES(passport_country), passport_expiration=VALUES(passport_expiration), global_entry_number=VALUES(global_entry_number), global_entry_expiration=VALUES(global_entry_expiration), birth_certificate_number=VALUES(birth_certificate_number), boat_license_number=VALUES(boat_license_number), boat_license_expiration=VALUES(boat_license_expiration)');
  $stmt->execute([$id,$ssn,$dl,$pp,$med,$dl_state,$dl_exp,$pp_country,$pp_exp,$ge_no,$ge_exp,$bc_no,$boat_no,$boat_exp]);
  // Also upsert dynamic person document values from posted fields
  try {
    // Load doc type and field maps
    $types = [];
    foreach ($pdo->query('SELECT id, code FROM person_doc_types') as $r){ $types[$r['code']] = (int)$r['id']; }
    $fieldIdMap = [];
    foreach ($pdo->query('SELECT id, doc_type_id, name_key FROM person_doc_fields') as $r){ $fieldIdMap[(int)$r['doc_type_id'].'|'.$r['name_key']] = (int)$r['id']; }
    $posted = $_POST['doc_field'] ?? [];
    if (is_array($posted)){
      foreach ($posted as $code=>$kv){
        $code = (string)$code; if (!isset($types[$code])) continue; $typeId = $types[$code];
        // Ensure person_docs record exists
        $pdo->prepare('INSERT IGNORE INTO person_docs(person_id, doc_type_id) VALUES (?,?)')->execute([$id,$typeId]);
        if (!is_array($kv)) continue;
        foreach ($kv as $key=>$val){
          $fid = $fieldIdMap[$typeId.'|'.$key] ?? 0; if (!$fid) continue;
          // Normalize values by input type? For now, store raw string
          $stmt = $pdo->prepare('INSERT INTO person_doc_values(person_id,doc_type_id,field_id,value_text) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)');
          $stmt->execute([$id,$typeId,$fid, ($val===''? null : $val)]);
        }
      }
    }
  } catch (Throwable $e) { /* ignore */ }

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

// Add a document type to this person
if ($isEdit && (($_POST['action'] ?? '')==='add_person_doc')){
  Util::checkCsrf();
  $typeId = (int)($_POST['doc_type_id'] ?? 0);
  if ($typeId>0){ $pdo->prepare('INSERT IGNORE INTO person_docs(person_id, doc_type_id) VALUES (?,?)')->execute([$id,$typeId]); }
  Util::redirect('index.php?page=person_edit&id='.$id.'#docs');
}
// Remove a document from this person
if ($isEdit && (($_POST['action'] ?? '')==='delete_person_doc')){
  Util::checkCsrf();
  $typeId = (int)($_POST['doc_type_id'] ?? 0);
  if ($typeId>0){
    // Delete values and doc link
    $pdo->prepare('DELETE FROM person_doc_values WHERE person_id=? AND doc_type_id=?')->execute([$id,$typeId]);
    $pdo->prepare('DELETE FROM person_docs WHERE person_id=? AND doc_type_id=?')->execute([$id,$typeId]);
    // Also move any captioned files to Trash
    try {
      $st = $pdo->prepare('SELECT code FROM person_doc_types WHERE id=?'); $st->execute([$typeId]); $code = $st->fetchColumn();
      if ($code){
        $cap1 = $code.'_front'; $cap2 = $code.'_back';
        $up = $pdo->prepare("UPDATE files SET is_trashed=1, trashed_at=NOW() WHERE entity_type='person' AND entity_id=? AND caption IN (?,?)");
        $up->execute([$id,$cap1,$cap2]);
      }
    } catch (Throwable $e) { /* ignore */ }
  }
  Util::redirect('index.php?page=person_edit&id='.$id.'#docs');
}

// Link policy to person (with optional coverage)
if ($isEdit && (($_POST['action'] ?? '')==='link_policy')){
  Util::checkCsrf();
  $pid = (int)($_POST['link_policy_id'] ?? 0);
  $role = $_POST['link_policy_role'] ?? 'named_insured';
  $cov = isset($_POST['link_policy_cov']) && $_POST['link_policy_cov']!=='' ? (int)$_POST['link_policy_cov'] : null;
  $stmt = $pdo->prepare('INSERT IGNORE INTO policy_people(policy_id, person_id, role, coverage_definition_id) VALUES (?,?,?,?)');
  $stmt->execute([$pid,$id,$role,$cov]);
  Util::redirect('index.php?page=person_edit&id='.$id);
}
// Unlink policy
if ($isEdit && (($_POST['action'] ?? '')==='unlink_policy')){
  Util::checkCsrf();
  $ppid = (int)($_POST['pp_id'] ?? 0);
  $stmt = $pdo->prepare('DELETE FROM policy_people WHERE id=? AND person_id=?');
  $stmt->execute([$ppid,$id]);
  Util::redirect('index.php?page=person_edit&id='.$id);
}

// Load data
$person = ['first_name'=>'','last_name'=>'','gender'=>null,'dob'=>null,'notes'=>''];
if ($isEdit){ $st=$pdo->prepare('SELECT * FROM people WHERE id=?'); $st->execute([$id]); $person=$st->fetch()?:$person; }
$contacts=[]; if ($isEdit){ $st=$pdo->prepare('SELECT * FROM person_contacts WHERE person_id=? ORDER BY is_primary DESC, id DESC'); $st->execute([$id]); $contacts=$st->fetchAll(); }
$assets=$pdo->query('SELECT id,name FROM assets WHERE is_deleted=0 ORDER BY name')->fetchAll();
$linkedAssets=[]; if ($isEdit){ $st=$pdo->prepare('SELECT pa.asset_id,a.name,pa.role FROM person_assets pa JOIN assets a ON a.id=pa.asset_id WHERE pa.person_id=? ORDER BY a.name'); $st->execute([$id]); $linkedAssets=$st->fetchAll(); }
$files=[]; if ($isEdit){ $st=$pdo->prepare("SELECT id, filename, mime_type, size, uploaded_at, caption FROM files WHERE entity_type='person' AND entity_id=? AND is_trashed=0 ORDER BY uploaded_at DESC"); $st->execute([$id]); $files=$st->fetchAll(); }
// Doc types and this person's active docs
$docTypes = $pdo->query('SELECT * FROM person_doc_types WHERE is_active=1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
$activeDocs = [];
if ($isEdit){
  $st = $pdo->prepare('SELECT dt.* FROM person_docs pd JOIN person_doc_types dt ON dt.id=pd.doc_type_id WHERE pd.person_id=? ORDER BY dt.sort_order, dt.name');
  $st->execute([$id]); $activeDocs = $st->fetchAll(PDO::FETCH_ASSOC);
}
// Field defs cache and values for this person
$fieldDefs = [];
foreach ($docTypes as $dt){
  $sid = (int)$dt['id'];
  $st = $pdo->prepare('SELECT * FROM person_doc_fields WHERE doc_type_id=? ORDER BY sort_order, display_name');
  $st->execute([$sid]); $fieldDefs[$sid] = $st->fetchAll(PDO::FETCH_ASSOC);
}
$values = [];
if ($isEdit && $activeDocs){
  $ids = implode(',', array_map('intval', array_column($activeDocs,'id')));
  // Pull values per active doc types
  $st = $pdo->prepare('SELECT v.doc_type_id, v.field_id, v.value_text FROM person_doc_values v WHERE v.person_id=?');
  $st->execute([$id]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r){ $values[(int)$r['doc_type_id']][(int)$r['field_id']] = $r['value_text']; }
}
// Policies
$policies = $pdo->query('SELECT id, insurer, policy_number, policy_type FROM policies ORDER BY insurer, policy_number')->fetchAll();
$policyCov = [];
if ($policies) {
  $ids = implode(',', array_map('intval', array_column($policies,'id')));
  $rows = $pdo->query('SELECT pc.policy_id, pc.coverage_definition_id, cd.name FROM policy_coverages pc JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id WHERE pc.policy_id IN ('.$ids.')')->fetchAll();
  foreach ($rows as $r){ $policyCov[(int)$r['policy_id']][] = ['id'=>(int)$r['coverage_definition_id'],'name'=>$r['name']]; }
}
$linkedPolicies = [];
if ($isEdit) {
  $st=$pdo->prepare('SELECT pp.id, p.policy_number, p.insurer, p.policy_type, pp.role, pp.coverage_definition_id, cd.name AS coverage_name FROM policy_people pp JOIN policies p ON p.id=pp.policy_id LEFT JOIN coverage_definitions cd ON cd.id=pp.coverage_definition_id WHERE pp.person_id=? ORDER BY p.insurer, p.policy_number');
  $st->execute([$id]);
  $linkedPolicies = $st->fetchAll();
}
// Private
$auth = ensure_authenticated(); $roles=$auth['roles']??[]; $isAdmin=in_array('admin',$roles,true); $priv=[]; if ($isEdit){ $st=$pdo->prepare('SELECT * FROM person_private WHERE person_id=?'); $st->execute([$id]); $priv=$st->fetch()?:[]; }
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
    <?php if ($isEdit): ?>
      <button class="btn sm outline" type="button" data-modal-open="personPhotoModal">Add Photos</button>
    <?php endif; ?>
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
      <div class="basic-details" style="margin-bottom:12px">
        <div class="section-head"><h2>General Info</h2></div>
        <div class="row">
          <div class="col-3"><label>Gender</label>
            <?php $genders=['','male','female','nonbinary','other','prefer_not']; ?>
            <select name="gender" form="personHeaderForm">
              <?php foreach ($genders as $g): ?>
                <option value="<?= $g ?>" <?= ($person['gender']??'')===$g?'selected':'' ?>><?= $g===''?'Select…':ucwords(str_replace('_',' ',$g)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-3"><label>Birthdate</label><input type="date" name="dob" value="<?= Util::h($person['dob'] ?? '') ?>" form="personHeaderForm"></div>
          <div class="col-6"><label>Notes</label><input name="notes" value="<?= Util::h($person['notes'] ?? '') ?>" form="personHeaderForm" placeholder="Notes (optional)"></div>
        </div>
      </div>
      <div class="id-section" id="docs">
        <?php $pidAttr = $isEdit? 'data-person-id="'.$id.'"' : 'data-disabled="1"'; ?>
        <div class="section-head" style="align-items:center; gap:8px">
          <h2>Identification & Documents</h2>
          <?php if ($isEdit): ?>
            <form method="post" class="actions" style="margin-left:auto; gap:6px">
              <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
              <input type="hidden" name="action" value="add_person_doc">
              <select name="doc_type_id">
                <?php
                  // Offer types not yet active
                  $activeIds = array_map(fn($d)=> (int)$d['id'], $activeDocs);
                  foreach ($docTypes as $t): if (in_array((int)$t['id'],$activeIds,true)) continue; ?>
                    <option value="<?= (int)$t['id'] ?>"><?= Util::h($t['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn sm" type="submit">Add</button>
            </form>
          <?php endif; ?>
        </div>
        <?php if (!$activeDocs): ?>
          <div class="small muted" style="margin-top:6px">No documents yet. Use Add to include IDs or documents.</div>
        <?php endif; ?>
        <?php foreach ($activeDocs as $dt): $code=$dt['code']; $front=findByCaption($files, $code.'_front'); $back=findByCaption($files, $code.'_back'); $tid=(int)$dt['id']; $defs = $fieldDefs[$tid] ?? []; ?>
          <div class="id-row">
            <div class="id-title" style="display:flex; align-items:center; gap:8px">
              <span><?= Util::h($dt['name']) ?></span>
              <?php if ($isEdit): ?>
                <form method="post" onsubmit="return confirmAction('Remove this document?')" style="margin-left:8px">
                  <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                  <input type="hidden" name="action" value="delete_person_doc">
                  <input type="hidden" name="doc_type_id" value="<?= $tid ?>">
                  <button class="btn sm ghost danger" title="Remove">🗑️</button>
                </form>
              <?php endif; ?>
            </div>
            <div class="id-slots">
              <?php if ($dt['allow_front_photo']): ?>
              <div class="id-slot" data-id-slot data-caption="<?= Util::h($code.'_front') ?>" <?= $pidAttr ?>>
                <?php if ($front && strpos($front['mime_type'],'image/')===0): ?>
                  <div class="thumb" data-file-wrap>
                    <button class="thumb-trash" type="button" title="Move to Trash" data-file-trash data-file-id="<?= (int)$front['id'] ?>">🗑️</button>
                    <img class="id-prev" data-file-id="<?= (int)$front['id'] ?>" data-filename="<?= Util::h($front['filename']) ?>" data-size="<?= (int)$front['size'] ?>" data-uploaded="<?= Util::h($front['uploaded_at']) ?>" src="<?= Util::baseUrl('file.php?id='.(int)$front['id']) ?>" alt="Front">
                  </div>
                <?php endif; ?>
                <div class="dz"><div class="dz-icon">⬆️</div><div class="dz-title">Drop front side here</div><div class="dz-sub">or <span class="link">Browse files</span></div></div>
                <input type="file" accept="image/*" hidden>
              </div>
              <?php endif; ?>
              <?php if ($dt['allow_back_photo']): ?>
              <div class="id-slot" data-id-slot data-caption="<?= Util::h($code.'_back') ?>" <?= $pidAttr ?>>
                <?php if ($back && strpos($back['mime_type'],'image/')===0): ?>
                  <div class="thumb" data-file-wrap>
                    <button class="thumb-trash" type="button" title="Move to Trash" data-file-trash data-file-id="<?= (int)$back['id'] ?>">🗑️</button>
                    <img class="id-prev" data-file-id="<?= (int)$back['id'] ?>" data-filename="<?= Util::h($back['filename']) ?>" data-size="<?= (int)$back['size'] ?>" data-uploaded="<?= Util::h($back['uploaded_at']) ?>" src="<?= Util::baseUrl('file.php?id='.(int)$back['id']) ?>" alt="Back">
                  </div>
                <?php endif; ?>
                <div class="dz"><div class="dz-icon">⬆️</div><div class="dz-title">Drop back side here</div><div class="dz-sub">or <span class="link">Browse files</span></div></div>
                <input type="file" accept="image/*" hidden>
              </div>
              <?php endif; ?>
            </div>
            <?php if ($defs): ?>
              <div class="id-fields row" style="margin-top:6px">
                <?php foreach ($defs as $f): $fid=(int)$f['id']; $val=$values[$tid][$fid] ?? ''; $nameKey=$f['name_key']; $type=$f['input_type']; ?>
                  <div class="col-4">
                    <label><?= Util::h($f['display_name']) ?></label>
                    <?php if ($type==='date'): ?>
                      <input type="date" name="doc_field[<?= Util::h($code) ?>][<?= Util::h($nameKey) ?>]" value="<?= Util::h($val) ?>" form="personHeaderForm">
                    <?php elseif ($type==='number'): ?>
                      <input type="number" name="doc_field[<?= Util::h($code) ?>][<?= Util::h($nameKey) ?>]" value="<?= Util::h($val) ?>" form="personHeaderForm">
                    <?php elseif ($type==='checkbox'): ?>
                      <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="doc_field[<?= Util::h($code) ?>][<?= Util::h($nameKey) ?>]" value="1" <?= ($val==='1'?'checked':'') ?> form="personHeaderForm"> Yes</label>
                    <?php else: ?>
                      <input name="doc_field[<?= Util::h($code) ?>][<?= Util::h($nameKey) ?>]" value="<?= Util::h($val) ?>" form="personHeaderForm">
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
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
          <?php if ($isEdit): ?>
          <div class="actions" style="margin-top:8px">
            <button class="btn sm outline" type="button" data-modal-open="personPhotoModal">Open uploader…</button>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($files): ?>
        <div class="table-wrap" style="margin-top:6px"><table>
          <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Uploaded</th><th>Caption</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($files as $f): ?>
              <tr>
                <td><a href="<?= Util::baseUrl('file.php?id='.(int)$f['id']) ?>" target="_blank" data-file-id="<?= (int)$f['id'] ?>" data-filename="<?= Util::h($f['filename']) ?>" data-size="<?= (int)$f['size'] ?>" data-uploaded="<?= Util::h($f['uploaded_at']) ?>"><?= Util::h($f['filename']) ?></a></td>
                <td><?= Util::h($f['mime_type']) ?></td>
                <td><?= number_format($f['size']) ?></td>
                <td><?= Util::h($f['uploaded_at']) ?></td>
                <td><?= Util::h($f['caption'] ?? '') ?></td>
                <td><button class="btn sm ghost danger" type="button" title="Trash" data-file-trash data-file-id="<?= (int)$f['id'] ?>">🗑️</button></td>
              </tr>
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

    <div class="card">
      <div class="section-head"><h2>Policies</h2></div>
      <form method="post" class="row" id="policyLinkForm">
        <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
        <input type="hidden" name="action" value="link_policy">
        <div class="col-6"><label>Policy</label>
          <select name="link_policy_id" id="link_policy_id">
            <option value="">--</option>
            <?php foreach ($policies as $p): $label = trim(($p['insurer']?:'').' '.$p['policy_number']); ?>
              <option value="<?= (int)$p['id'] ?>" data-type="<?= Util::h($p['policy_type']) ?>"><?= Util::h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-4"><label>Coverage</label>
          <select name="link_policy_cov" id="link_policy_cov">
            <option value="">Whole policy</option>
            <?php foreach ($policyCov as $pid=>$list): foreach ($list as $c): ?>
              <option value="<?= (int)$c['id'] ?>" data-policy="<?= (int)$pid ?>"><?= Util::h($c['name']) ?></option>
            <?php endforeach; endforeach; ?>
          </select>
          <div class="small muted">Filtered to selected policy</div>
        </div>
        <div class="col-2"><label>Role</label>
          <select name="link_policy_role"><option>named_insured</option><option>driver</option><option>resident</option><option>listed</option><option>other</option></select>
        </div>
        <div class="col-12 actions"><button class="btn sm" type="submit">Link</button></div>
      </form>

      <?php if ($linkedPolicies): ?>
        <div class="table-wrap" style="margin-top:6px"><table>
          <thead><tr><th>Policy</th><th>Role</th><th>Coverage</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($linkedPolicies as $lp): $lab = trim(($lp['insurer']?:'').' '.$lp['policy_number']); ?>
              <tr>
                <td><?= Util::h($lab) ?></td>
                <td><?= Util::h($lp['role']) ?></td>
                <td><?= Util::h($lp['coverage_name'] ?? '—') ?></td>
                <td>
                  <form method="post" onsubmit="return confirmAction('Unlink policy?')">
                    <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                    <input type="hidden" name="action" value="unlink_policy">
                    <input type="hidden" name="pp_id" value="<?= (int)$lp['id'] ?>">
                    <button class="btn sm ghost danger">🗑️</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Reusable photo uploader modal (person) -->
<?php if ($isEdit): ?>
<div class="modal-backdrop" id="personPhotoModal">
  <div class="modal" style="width:min(640px, 95vw)">
    <div class="head"><strong>Upload and attach photos</strong><button class="x" data-modal-close="personPhotoModal">✕</button></div>
    <div class="body">
      <div class="uploader">
        <div class="dropzone" id="pp_drop">
          <input id="pp_input" type="file" accept="image/*" multiple aria-label="Choose photos">
          <div class="dz-inner">
            <div class="dz-icon">📷</div>
            <div class="dz-title"><label for="pp_input" style="cursor:pointer">Click to upload</label> or drag and drop</div>
            <div class="dz-sub">Maximum file size 50 MB. JPEG/PNG/HEIC supported.</div>
          </div>
        </div>
        <div id="pp_error" class="uploader-error" style="display:none"></div>
        <div id="pp_list" class="uploader-list"></div>
        <div id="pp_status" class="uploader-status" style="display:none"></div>
      </div>
    </div>
    <div class="foot" style="justify-content: space-between;">
      <button class="btn ghost" type="button" data-modal-close="personPhotoModal">Cancel</button>
      <button class="btn" type="button" id="pp_upload" disabled>Attach files</button>
    </div>
  </div>
  <input type="hidden" id="pp_person_id" value="<?= (int)$id ?>">
  <input type="hidden" id="pp_caption" value="">
</div>
<?php endif; ?>
