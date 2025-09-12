<?php
$pdo = Database::get();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$groupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$isEdit = $id > 0;

// Load policy if editing
$policy = null;
if ($isEdit) {
  $stmt = $pdo->prepare('SELECT * FROM policies WHERE id=?');
  $stmt->execute([$id]);
  $policy = $stmt->fetch();
  $groupId = $policy ? (int)$policy['policy_group_id'] : $groupId;
}

// Create initial policy in a group when saving for first time
if (!$isEdit && $groupId && (($_POST['action'] ?? '') === 'save')) {
  Util::checkCsrf();
  $version_number = 1;
  $policy_number = trim($_POST['policy_number'] ?? '');
  $insurer = trim($_POST['insurer'] ?? '');
  $policy_type = $_POST['policy_type'] ?? 'home';
  $start_date = $_POST['start_date'] ?? '';
  $end_date = $_POST['end_date'] ?? '';
  $premium = (float)($_POST['premium'] ?? 0);
  $status = $_POST['status'] ?? 'active';
  $notes = trim($_POST['notes'] ?? '');
  $stmt = $pdo->prepare('INSERT INTO policies(policy_group_id, version_number, policy_number, insurer, policy_type, start_date, end_date, premium, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
  $stmt->execute([$groupId, $version_number, $policy_number, $insurer, $policy_type, $start_date, $end_date, $premium, $status, $notes]);
  $id = (int)$pdo->lastInsertId();
  $pdo->prepare('INSERT INTO audit_log(entity_type, entity_id, action, details) VALUES ("policy", ?, "create", NULL)')->execute([$id]);
  Util::redirect('index.php?page=policy_edit&id='.$id);
}

// Save updates to existing policy
if ($isEdit && (($_POST['action'] ?? '') === 'save')) {
  Util::checkCsrf();
  $policy_number = trim($_POST['policy_number'] ?? '');
  $insurer = trim($_POST['insurer'] ?? '');
  $policy_type = $_POST['policy_type'] ?? $policy['policy_type'];
  $start_date = $_POST['start_date'] ?? '';
  $end_date = $_POST['end_date'] ?? '';
  $premium = (float)($_POST['premium'] ?? 0);
  $status = $_POST['status'] ?? 'active';
  $notes = trim($_POST['notes'] ?? '');
  $stmt = $pdo->prepare('UPDATE policies SET policy_number=?, insurer=?, policy_type=?, start_date=?, end_date=?, premium=?, status=?, notes=? WHERE id=?');
  $stmt->execute([$policy_number, $insurer, $policy_type, $start_date, $end_date, $premium, $status, $notes, $id]);
  $pdo->prepare('INSERT INTO audit_log(entity_type, entity_id, action, details) VALUES ("policy", ?, "update", NULL)')->execute([$id]);
  Util::redirect('index.php?page=policy_edit&id='.$id);
}

// Create renewal (new version)
if ($isEdit && (($_POST['action'] ?? '') === 'renew')) {
  Util::checkCsrf();
  // Get next version
  $nextVersion = (int)$pdo->query('SELECT COALESCE(MAX(version_number),0)+1 FROM policies WHERE policy_group_id='.(int)$groupId)->fetchColumn();
  $policy_number = trim($_POST['policy_number'] ?? $policy['policy_number']);
  $insurer = trim($_POST['insurer'] ?? $policy['insurer']);
  $policy_type = $_POST['policy_type'] ?? $policy['policy_type'];
  $start_date = $_POST['start_date'] ?? $policy['start_date'];
  $end_date = $_POST['end_date'] ?? $policy['end_date'];
  $premium = (float)($_POST['premium'] ?? $policy['premium']);
  $status = $_POST['status'] ?? 'active';
  $notes = trim($_POST['notes'] ?? $policy['notes']);
  $stmt = $pdo->prepare('INSERT INTO policies(policy_group_id, version_number, policy_number, insurer, policy_type, start_date, end_date, premium, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?)');
  $stmt->execute([$groupId, $nextVersion, $policy_number, $insurer, $policy_type, $start_date, $end_date, $premium, $status, $notes]);
  $newId = (int)$pdo->lastInsertId();
  // Copy coverages from previous version
  $pdo->exec('INSERT INTO policy_coverages(policy_id, coverage_definition_id, limit_amount, deductible_amount, notes)
              SELECT '.$newId.', coverage_definition_id, limit_amount, deductible_amount, notes FROM policy_coverages WHERE policy_id='.(int)$id);
  // Links are not auto-copied to avoid outdated associations.
  $pdo->prepare('INSERT INTO audit_log(entity_type, entity_id, action, details) VALUES ("policy", ?, "renew", NULL)')->execute([$newId]);
  Util::redirect('index.php?page=policy_edit&id='.$newId);
}

// Coverage add/remove
if ($isEdit && (($_POST['action'] ?? '') === 'add_coverage')) {
  Util::checkCsrf();
  $cov = (int)($_POST['coverage_definition_id'] ?? 0);
  $limit = $_POST['limit_amount'] !== '' ? (float)$_POST['limit_amount'] : null;
  $ded = $_POST['deductible_amount'] !== '' ? (float)$_POST['deductible_amount'] : null;
  $notes = trim($_POST['cov_notes'] ?? '');
  $stmt = $pdo->prepare('INSERT INTO policy_coverages(policy_id, coverage_definition_id, limit_amount, deductible_amount, notes) VALUES (?,?,?,?,?)');
  $stmt->execute([$id, $cov, $limit, $ded, $notes]);
  Util::redirect('index.php?page=policy_edit&id='.$id);
}
if ($isEdit && (($_POST['action'] ?? '') === 'remove_coverage')) {
  Util::checkCsrf();
  $cid = (int)($_POST['id'] ?? 0);
  $pdo->prepare('DELETE FROM policy_coverages WHERE id=? AND policy_id=?')->execute([$cid, $id]);
  Util::redirect('index.php?page=policy_edit&id='.$id);
}

// Asset link/unlink
if ($isEdit && (($_POST['action'] ?? '') === 'link_asset')) {
  Util::checkCsrf();
  $aid = (int)($_POST['asset_id'] ?? 0);
  $apply = !empty($_POST['applies_to_children']) ? 1 : 0;
  $stmt = $pdo->prepare('INSERT IGNORE INTO policy_assets(policy_id, asset_id, applies_to_children) VALUES (?,?,?)');
  $stmt->execute([$id, $aid, $apply]);
  Util::redirect('index.php?page=policy_edit&id='.$id);
}
if ($isEdit && (($_POST['action'] ?? '') === 'unlink_asset')) {
  Util::checkCsrf();
  $aid = (int)($_POST['asset_id'] ?? 0);
  $pdo->prepare('DELETE FROM policy_assets WHERE policy_id=? AND asset_id=?')->execute([$id, $aid]);
  Util::redirect('index.php?page=policy_edit&id='.$id);
}

// Load current data for view
$policy = null;
if ($isEdit) {
  $stmt = $pdo->prepare('SELECT * FROM policies WHERE id=?');
  $stmt->execute([$id]);
  $policy = $stmt->fetch();
}
$group = null;
if ($groupId) {
  $stmt = $pdo->prepare('SELECT * FROM policy_groups WHERE id=?');
  $stmt->execute([$groupId]);
  $group = $stmt->fetch();
}

$coverages = [];
if ($isEdit) {
  $stmt = $pdo->prepare('SELECT pc.*, cd.code, cd.name FROM policy_coverages pc JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id WHERE pc.policy_id=? ORDER BY cd.name');
  $stmt->execute([$id]);
  $coverages = $stmt->fetchAll();
}

// Coverage options filtered by type if we have one
$covOpts = [];
if ($policy || isset($_POST['policy_type'])) {
  $type = $policy['policy_type'] ?? ($_POST['policy_type'] ?? 'home');
  $stmt = $pdo->prepare("SELECT * FROM coverage_definitions WHERE FIND_IN_SET(?, applicable_types) ORDER BY name");
  $stmt->execute([$type]);
  $covOpts = $stmt->fetchAll();
} else {
  $covOpts = $pdo->query('SELECT * FROM coverage_definitions ORDER BY name')->fetchAll();
}

// Linked assets
$linkedAssets = [];
if ($isEdit) {
  $stmt = $pdo->prepare('SELECT a.*, pa.applies_to_children FROM policy_assets pa JOIN assets a ON a.id=pa.asset_id WHERE pa.policy_id=? ORDER BY a.name');
  $stmt->execute([$id]);
  $linkedAssets = $stmt->fetchAll();
}
// All assets for selector
$assets = $pdo->query('SELECT id, name FROM assets WHERE is_deleted=0 ORDER BY name')->fetchAll();

// Versions history
$versions = [];
if ($groupId) {
  $stmt = $pdo->prepare('SELECT * FROM policies WHERE policy_group_id=? ORDER BY version_number DESC');
  $stmt->execute([$groupId]);
  $versions = $stmt->fetchAll();
}

?>

<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
    <h1><?= $isEdit ? 'Edit Policy' : 'Create Policy' ?></h1>
    <a class="btn ghost" href="<?= Util::baseUrl('index.php?page=policies') ?>">Back</a>
  </div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
    <input type="hidden" name="action" value="save">
    <div class="row">
      <div class="col-3"><label>Policy #</label><input name="policy_number" value="<?= Util::h($policy['policy_number'] ?? '') ?>" required></div>
      <div class="col-3"><label>Insurer</label><input name="insurer" value="<?= Util::h($policy['insurer'] ?? '') ?>" required></div>
      <div class="col-3">
        <label>Type</label>
        <?php $types=['home','auto','boat','flood','umbrella','jewelry','electronics','other']; $curType = $policy['policy_type'] ?? 'home'; ?>
        <select name="policy_type">
          <?php foreach ($types as $t): ?>
            <option value="<?= $t ?>" <?= $curType===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-3"><label>Status</label>
        <?php $statuses=['active','expired','cancelled','quote']; $curStatus = $policy['status'] ?? 'active'; ?>
        <select name="status">
          <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= $curStatus===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-3"><label>Start Date</label><input type="date" name="start_date" value="<?= Util::h($policy['start_date'] ?? '') ?>" required></div>
      <div class="col-3"><label>End Date</label><input type="date" name="end_date" value="<?= Util::h($policy['end_date'] ?? '') ?>" required></div>
      <div class="col-3"><label>Premium</label><input type="number" step="0.01" name="premium" value="<?= Util::h($policy['premium'] ?? '') ?>" required></div>
      <div class="col-12"><label>Notes</label><textarea name="notes" rows="2"><?= Util::h($policy['notes'] ?? '') ?></textarea></div>
      <div class="col-12 actions" style="margin-top:8px">
        <button class="btn" type="submit">Save</button>
        <?php if ($isEdit): ?>
          <button class="btn outline" name="action" value="renew" onclick="return confirmAction('Create renewal from this version?')">Create Renewal</button>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>

<?php if ($isEdit): ?>
<div class="row" style="margin-top:16px">
  <div class="col-6">
    <div class="card">
      <h2>Coverages</h2>
      <form method="post" class="input-row" style="margin-bottom:8px">
        <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
        <input type="hidden" name="action" value="add_coverage">
        <div>
          <label>Coverage</label>
          <select name="coverage_definition_id">
            <?php foreach ($covOpts as $co): ?>
              <option value="<?= (int)$co['id'] ?>"><?= Util::h($co['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>Limit</label><input type="number" step="0.01" name="limit_amount"></div>
        <div><label>Deductible</label><input type="number" step="0.01" name="deductible_amount"></div>
        <div class="col-12"><label>Notes</label><input name="cov_notes"></div>
        <div class="col-12"><button class="btn" type="submit">Add Coverage</button></div>
      </form>
      <table>
        <thead><tr><th>Coverage</th><th>Limit</th><th>Deductible</th><th>Notes</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($coverages as $c): ?>
            <tr>
              <td><?= Util::h($c['name']) ?></td>
              <td><?= $c['limit_amount']!==null? ('$'.number_format($c['limit_amount'],2)) : '-' ?></td>
              <td><?= $c['deductible_amount']!==null? ('$'.number_format($c['deductible_amount'],2)) : '-' ?></td>
              <td><?= Util::h($c['notes']) ?></td>
              <td>
                <form method="post" onsubmit="return confirmAction('Remove coverage?')">
                  <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                  <input type="hidden" name="action" value="remove_coverage">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn ghost danger">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-6">
    <div class="card">
      <h2>Linked Assets</h2>
      <form method="post" class="input-row" style="margin-bottom:8px">
        <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
        <input type="hidden" name="action" value="link_asset">
        <div>
          <label>Asset</label>
          <select name="asset_id">
            <?php foreach ($assets as $a): ?>
              <option value="<?= (int)$a['id'] ?>"><?= Util::h($a['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Applies to Children</label>
          <select name="applies_to_children">
            <option value="1" selected>Yes</option>
            <option value="0">No</option>
          </select>
        </div>
        <div class="col-12"><button class="btn" type="submit">Link</button></div>
      </form>
      <table>
        <thead><tr><th>Asset</th><th>Children</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($linkedAssets as $la): ?>
            <tr>
              <td><?= Util::h($la['name']) ?></td>
              <td><?= $la['applies_to_children'] ? 'Yes' : 'No' ?></td>
              <td>
                <form method="post" onsubmit="return confirmAction('Unlink asset?')">
                  <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
                  <input type="hidden" name="action" value="unlink_asset">
                  <input type="hidden" name="asset_id" value="<?= (int)$la['id'] ?>">
                  <button class="btn ghost danger">Unlink</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <h2>Versions</h2>
      <table>
        <thead><tr><th>Version</th><th>Start</th><th>End</th><th>Premium</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($versions as $v): ?>
            <tr>
              <td><a href="<?= Util::baseUrl('index.php?page=policy_edit&id='.(int)$v['id']) ?>">v<?= (int)$v['version_number'] ?></a></td>
              <td><?= Util::h($v['start_date']) ?></td>
              <td><?= Util::h($v['end_date']) ?></td>
              <td>$<?= number_format($v['premium'],2) ?></td>
              <td><?= Util::h($v['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

