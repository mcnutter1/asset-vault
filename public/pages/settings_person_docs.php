<?php
$pdo = Database::get();

// Safety: ensure person docs tables exist (idempotent). If DB lacks CREATE, degrade gracefully.
$ensureError = null;
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS person_doc_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    allow_front_photo TINYINT(1) NOT NULL DEFAULT 1,
    allow_back_photo TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS person_doc_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    doc_type_id INT NOT NULL,
    name_key VARCHAR(100) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    input_type ENUM('text','date','number','checkbox') NOT NULL DEFAULT 'text',
    sort_order INT NOT NULL DEFAULT 0,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_doc_name (doc_type_id, name_key),
    CONSTRAINT fk_pdf_doc FOREIGN KEY (doc_type_id) REFERENCES person_doc_types(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS person_docs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    doc_type_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_person_doctype (person_id, doc_type_id),
    CONSTRAINT fk_pdocs_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
    CONSTRAINT fk_pdocs_doctype FOREIGN KEY (doc_type_id) REFERENCES person_doc_types(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS person_doc_values (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    person_id INT NOT NULL,
    doc_type_id INT NOT NULL,
    field_id INT NOT NULL,
    value_text TEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_person_doc_field (person_id, doc_type_id, field_id),
    CONSTRAINT fk_pdval_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
    CONSTRAINT fk_pdval_type FOREIGN KEY (doc_type_id) REFERENCES person_doc_types(id) ON DELETE CASCADE,
    CONSTRAINT fk_pdval_field FOREIGN KEY (field_id) REFERENCES person_doc_fields(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
  $ensureError = $e->getMessage();
}

function slugify_code($s){ $s=strtolower(trim((string)$s)); $s=preg_replace('/[^a-z0-9]+/','_', $s); $s=trim($s,'_'); return $s ?: 'doc'; }

// Handle actions for types
$action = $_POST['action'] ?? '';
if ($action === 'add_doc_type') {
  Util::checkCsrf();
  $code = slugify_code($_POST['code'] ?? '');
  $name = trim($_POST['name'] ?? '');
  $front = !empty($_POST['allow_front_photo']) ? 1 : 0;
  $back = !empty($_POST['allow_back_photo']) ? 1 : 0;
  $sort = (int)($_POST['sort_order'] ?? 0);
  if ($code !== '' && $name !== ''){
    $st = $pdo->prepare('INSERT IGNORE INTO person_doc_types(code,name,allow_front_photo,allow_back_photo,sort_order,is_active) VALUES (?,?,?,?,?,1)');
    $st->execute([$code,$name,$front,$back,$sort]);
  }
  Util::redirect('index.php?page=settings&tab=person_docs');
}
if ($action === 'update_doc_type') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $code = slugify_code($_POST['code'] ?? '');
  $name = trim($_POST['name'] ?? '');
  $front = !empty($_POST['allow_front_photo']) ? 1 : 0;
  $back = !empty($_POST['allow_back_photo']) ? 1 : 0;
  $sort = (int)($_POST['sort_order'] ?? 0);
  $act = !empty($_POST['is_active']) ? 1 : 0;
  if ($id > 0){
    $st = $pdo->prepare('UPDATE person_doc_types SET code=?, name=?, allow_front_photo=?, allow_back_photo=?, sort_order=?, is_active=? WHERE id=?');
    $st->execute([$code,$name,$front,$back,$sort,$act,$id]);
  }
  Util::redirect('index.php?page=settings&tab=person_docs');
}
if ($action === 'delete_doc_type') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0){ $pdo->prepare('DELETE FROM person_doc_types WHERE id=?')->execute([$id]); }
  Util::redirect('index.php?page=settings&tab=person_docs');
}

// Fields actions
if ($action === 'add_doc_field') {
  Util::checkCsrf();
  $tid = (int)($_POST['doc_type_id'] ?? 0);
  $key = strtolower(trim($_POST['name_key'] ?? ''));
  $name = trim($_POST['display_name'] ?? '');
  $type = $_POST['input_type'] ?? 'text';
  $sort = (int)($_POST['sort_order'] ?? 0);
  $req = !empty($_POST['is_required']) ? 1 : 0;
  if ($tid>0 && $key!=='' && $name!==''){
    $st = $pdo->prepare('INSERT IGNORE INTO person_doc_fields(doc_type_id,name_key,display_name,input_type,sort_order,is_required) VALUES (?,?,?,?,?,?)');
    $st->execute([$tid,$key,$name,$type,$sort,$req]);
  }
  Util::redirect('index.php?page=settings&tab=person_docs&dtype='.$tid);
}
if ($action === 'update_doc_field') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $tid = (int)($_POST['doc_type_id'] ?? 0);
  $name = trim($_POST['display_name'] ?? '');
  $type = $_POST['input_type'] ?? 'text';
  $sort = (int)($_POST['sort_order'] ?? 0);
  $req = !empty($_POST['is_required']) ? 1 : 0;
  if ($id>0){
    $st = $pdo->prepare('UPDATE person_doc_fields SET display_name=?, input_type=?, sort_order=?, is_required=? WHERE id=?');
    $st->execute([$name,$type,$sort,$req,$id]);
  }
  Util::redirect('index.php?page=settings&tab=person_docs&dtype='.$tid);
}
if ($action === 'delete_doc_field') {
  Util::checkCsrf();
  $id = (int)($_POST['id'] ?? 0);
  $tid = (int)($_POST['doc_type_id'] ?? 0);
  if ($id>0){ $pdo->prepare('DELETE FROM person_doc_fields WHERE id=?')->execute([$id]); }
  Util::redirect('index.php?page=settings&tab=person_docs&dtype='.$tid);
}

// Load types and optionally fields for a type
try { $types = $pdo->query('SELECT * FROM person_doc_types ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC); }
catch (Throwable $e) { $types = []; $ensureError = $ensureError ?: $e->getMessage(); }
$dtype = isset($_GET['dtype']) && $_GET['dtype']!=='' ? (int)$_GET['dtype'] : 0;
$fields = [];
if ($dtype>0){
  try {
    $stmt = $pdo->prepare('SELECT * FROM person_doc_fields WHERE doc_type_id=? ORDER BY sort_order, display_name');
    $stmt->execute([$dtype]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { $fields = []; $ensureError = $ensureError ?: $e->getMessage(); }
}
?>

<div class="settings-section">
  <h2>Add Person Document Type</h2>
  <?php if ($ensureError): ?>
    <div class="small muted">Note: Could not ensure person document tables (DB said: <?= Util::h($ensureError) ?>). If your DB user lacks CREATE, please run migrations manually or grant permissions. The UI will degrade gracefully.</div>
  <?php endif; ?>
  <p class="settings-description">Define ID/Document types for people. Choose whether to allow front/back photos and which fields to collect.</p>
  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
    <input type="hidden" name="action" value="add_doc_type">
    <div class="col-3"><label>Code (slug)</label><input name="code" placeholder="e.g., dl"></div>
    <div class="col-4"><label>Display Name</label><input name="name" placeholder="Driver's License"></div>
    <div class="col-2"><label>Front Photo</label><input type="checkbox" name="allow_front_photo" value="1" checked></div>
    <div class="col-2"><label>Back Photo</label><input type="checkbox" name="allow_back_photo" value="1" checked></div>
    <div class="col-1"><label>Sort</label><input type="number" name="sort_order" value="0"></div>
    <div class="col-12"><button class="btn" type="submit">Add Type</button></div>
  </form>
  <div class="small muted">Built-in defaults are created automatically: driver's license, passport, SSN, birth certificate, global entry, boat license.</div>
  <hr class="divider" />
</div>

<div class="settings-section">
  <h2>Manage Types</h2>
  <?php if (!$types): ?>
    <div class="small muted">No document types defined.</div>
  <?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Code</th><th>Name</th><th>Front</th><th>Back</th><th>Sort</th><th>Active</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($types as $t): ?>
          <tr>
            <td>
              <form method="post" class="actions" style="gap:6px; align-items:center">
                <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                <input type="hidden" name="action" value="update_doc_type">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <input name="code" value="<?= Util::h($t['code']) ?>" style="max-width:120px">
            </td>
            <td><input name="name" value="<?= Util::h($t['name']) ?>" style="min-width:200px"></td>
            <td style="text-align:center"><input type="checkbox" name="allow_front_photo" value="1" <?= $t['allow_front_photo']?'checked':'' ?>></td>
            <td style="text-align:center"><input type="checkbox" name="allow_back_photo" value="1" <?= $t['allow_back_photo']?'checked':'' ?>></td>
            <td><input type="number" name="sort_order" value="<?= (int)$t['sort_order'] ?>" style="width:80px"></td>
            <td style="text-align:center"><input type="checkbox" name="is_active" value="1" <?= $t['is_active']?'checked':'' ?>></td>
            <td class="actions">
                <button class="btn sm" type="submit">Save</button>
              </form>
              <form method="get" style="display:inline-block">
                <input type="hidden" name="page" value="settings">
                <input type="hidden" name="tab" value="person_docs">
                <input type="hidden" name="dtype" value="<?= (int)$t['id'] ?>">
                <button class="btn sm outline" type="submit">Fields…</button>
              </form>
              <form method="post" onsubmit="return confirmAction('Delete type and its fields?')">
                <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                <input type="hidden" name="action" value="delete_doc_type">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn sm ghost danger" type="submit">🗑️</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</div>

<div class="settings-section">
  <h2>Fields</h2>
  <form method="get" class="row" style="margin-bottom:8px">
    <input type="hidden" name="page" value="settings">
    <input type="hidden" name="tab" value="person_docs">
    <div class="col-4">
      <label>Document Type</label>
      <select name="dtype" onchange="this.form.submit()">
        <option value="">Select a type…</option>
        <?php foreach ($types as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= $dtype===(int)$t['id']?'selected':'' ?>><?= Util::h($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <?php if ($dtype>0): ?>
    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
      <input type="hidden" name="action" value="add_doc_field">
      <input type="hidden" name="doc_type_id" value="<?= (int)$dtype ?>">
      <div class="col-3"><label>Key</label><input name="name_key" placeholder="e.g., number"></div>
      <div class="col-4"><label>Display Name</label><input name="display_name" placeholder="e.g., License Number"></div>
      <div class="col-2"><label>Type</label>
        <select name="input_type">
          <?php foreach (['text','date','number','checkbox'] as $t): ?>
            <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-1"><label>Required</label><input type="checkbox" name="is_required" value="1"></div>
      <div class="col-1"><label>Sort</label><input type="number" name="sort_order" value="0"></div>
      <div class="col-12 actions"><button class="btn sm" type="submit">Add Field</button></div>
    </form>

    <?php if (!$fields): ?>
      <div class="small muted">No fields defined for this type.</div>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Key</th><th>Name</th><th>Type</th><th>Required</th><th>Sort</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($fields as $f): ?>
            <tr>
              <td><code><?= Util::h($f['name_key']) ?></code></td>
              <td>
                <form method="post" class="actions" style="gap:6px; align-items:center">
                  <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                  <input type="hidden" name="action" value="update_doc_field">
                  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <input type="hidden" name="doc_type_id" value="<?= (int)$dtype ?>">
                  <input name="display_name" value="<?= Util::h($f['display_name']) ?>" style="min-width:220px">
              </td>
              <td>
                  <select name="input_type">
                    <?php foreach (['text','date','number','checkbox'] as $t): ?>
                      <option value="<?= $t ?>" <?= $f['input_type']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                  </select>
              </td>
              <td style="text-align:center"><input type="checkbox" name="is_required" value="1" <?= $f['is_required']?'checked':'' ?>></td>
              <td><input type="number" name="sort_order" value="<?= (int)$f['sort_order'] ?>" style="width:80px"></td>
              <td class="actions">
                  <button class="btn sm" type="submit">Save</button>
                </form>
                <form method="post" onsubmit="return confirmAction('Delete field?')">
                  <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                  <input type="hidden" name="action" value="delete_doc_field">
                  <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                  <input type="hidden" name="doc_type_id" value="<?= (int)$dtype ?>">
                  <button class="btn sm ghost danger" type="submit">🗑️</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  <?php else: ?>
    <div class="small muted">Select a document type to manage its fields.</div>
  <?php endif; ?>
</div>
