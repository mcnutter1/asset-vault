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
$files=[]; if ($isEdit){ $st=$pdo->prepare("SELECT id, filename, mime_type, size, uploaded_at FROM files WHERE entity_type='person' AND entity_id=? ORDER BY uploaded_at DESC"); $st->execute([$id]); $files=$st->fetchAll(); }
// Private
$auth = ensure_authenticated(); $roles=$auth['roles']??[]; $isAdmin=in_array('admin',$roles,true); $priv=[]; if ($isAdmin && $isEdit){ $st=$pdo->prepare('SELECT * FROM person_private WHERE person_id=?'); $st->execute([$id]); $priv=$st->fetch()?:[]; }
?>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
    <h1><?= $isEdit ? 'Edit Person' : 'Add Person' ?></h1>
    <a class="btn sm ghost" href="<?= Util::baseUrl('index.php?page=people') ?>">Back</a>
  </div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
    <input type="hidden" name="action" value="save">
    <div class="row">
      <div class="col-4"><label>First Name</label><input name="first_name" value="<?= Util::h($person['first_name']) ?>" required></div>
      <div class="col-4"><label>Last Name</label><input name="last_name" value="<?= Util::h($person['last_name']) ?>" required></div>
      <div class="col-2"><label>Gender</label>
        <?php $genders=['male','female','nonbinary','other','prefer_not']; ?>
        <select name="gender">
          <option value=""></option>
          <?php foreach ($genders as $g): ?>
            <option value="<?= $g ?>" <?= $person['gender']===$g?'selected':'' ?>><?= ucwords(str_replace('_',' ',$g)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-2"><label>Birthdate</label><input type="date" name="dob" value="<?= Util::h($person['dob']) ?>"></div>
      <div class="col-12"><label>Notes</label><textarea name="notes" rows="2"><?= Util::h($person['notes']) ?></textarea></div>

      <div class="col-6">
        <h2>Contacts</h2>
        <div class="table-wrap"><table>
          <thead><tr><th>Type</th><th>Label</th><th>Value</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($contacts as $c): ?>
              <tr>
                <td><?= Util::h($c['contact_type']) ?></td><td><?= Util::h($c['label']) ?></td><td><?= Util::h($c['contact_value']) ?></td>
                <td>
                  <form method="post" onsubmit="return confirmAction('Delete contact?')">
                    <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                    <input type="hidden" name="action" value="delete_contact">
                    <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                    <button class="btn sm ghost danger">🗑️</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <div class="row" style="margin-top:6px">
          <div class="col-3"><label>Type</label><select name="new_contact_type"><option>phone</option><option>email</option><option>other</option></select></div>
          <div class="col-3"><label>Label</label><input name="new_contact_label"></div>
          <div class="col-6"><label>Value</label><input name="new_contact_value"></div>
        </div>
      </div>

      <div class="col-6">
        <h2>Assets</h2>
        <div class="row">
          <div class="col-8"><label>Link Asset</label>
            <select name="link_asset_id"><option value="">--</option><?php foreach ($assets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= Util::h($a['name']) ?></option><?php endforeach; ?></select>
          </div>
          <div class="col-4"><label>Role</label><select name="link_asset_role"><option>owner</option><option>user</option><option>resident</option><option>other</option></select></div>
        </div>
        <?php if ($linkedAssets): ?>
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
        <?php endif; ?>
      </div>

      <div class="col-12">
        <h2>Documents</h2>
        <input type="file" name="files[]" multiple>
        <?php if ($files): ?>
          <div class="table-wrap" style="margin-top:6px"><table>
            <thead><tr><th>File</th><th>Type</th><th>Size</th><th>Uploaded</th></tr></thead>
            <tbody>
              <?php foreach ($files as $f): ?>
                <tr><td><a href="<?= Util::baseUrl('file.php?id='.(int)$f['id']) ?>" target="_blank"><?= Util::h($f['filename']) ?></a></td><td><?= Util::h($f['mime_type']) ?></td><td><?= number_format($f['size']) ?></td><td><?= Util::h($f['uploaded_at']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </div>

      <?php if ($isAdmin): ?>
      <div class="col-12">
        <h2>Private (Admin only)</h2>
        <div class="row">
          <div class="col-3"><label>SSN</label><input name="priv_ssn" value="<?= Util::h($priv['ssn'] ?? '') ?>" autocomplete="off"></div>
          <div class="col-3"><label>Driver License</label><input name="priv_dl" value="<?= Util::h($priv['driver_license'] ?? '') ?>" autocomplete="off"></div>
          <div class="col-3"><label>Passport</label><input name="priv_passport" value="<?= Util::h($priv['passport_number'] ?? '') ?>" autocomplete="off"></div>
          <div class="col-12"><label>Medical Notes</label><textarea name="priv_medical" rows="2"><?= Util::h($priv['medical_notes'] ?? '') ?></textarea></div>
        </div>
        <div class="small muted">Private fields are only visible to Admin users.</div>
      </div>
      <?php endif; ?>

      <div class="col-12 actions" style="margin-top:8px">
        <button class="btn" type="submit">Save</button>
        <a class="btn ghost" href="<?= Util::baseUrl('index.php?page=people') ?>">Cancel</a>
      </div>
    </div>
  </form>
</div>
