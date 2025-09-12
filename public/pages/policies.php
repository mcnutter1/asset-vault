<?php
$pdo = Database::get();

// Create new policy group quick action
if (($_POST['action'] ?? '') === 'new_group') {
  Util::checkCsrf();
  $name = trim($_POST['display_name'] ?? '');
  $pdo->prepare('INSERT INTO policy_groups(display_name) VALUES (?)')->execute([$name ?: null]);
  $gid = (int)$pdo->lastInsertId();
  Util::redirect('index.php?page=policy_edit&group_id='.$gid);
}

// List latest version per group
$rows = $pdo->query('SELECT p.*, pg.display_name
                     FROM policies p
                     JOIN policy_groups pg ON pg.id=p.policy_group_id
                     JOIN (
                        SELECT policy_group_id, MAX(version_number) AS maxv
                        FROM policies GROUP BY policy_group_id
                     ) mv ON mv.policy_group_id=p.policy_group_id AND mv.maxv=p.version_number
                     ORDER BY p.end_date DESC')->fetchAll();

?>
<div class="card">
  <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
    <h1>Policies</h1>
    <form method="post" class="actions">
      <input type="hidden" name="csrf" value="<?= Util::csrfToken() ?>">
      <input type="hidden" name="action" value="new_group">
      <input name="display_name" placeholder="New Policy Group Name (optional)" style="min-width:240px">
      <button class="btn" type="submit">Create Policy</button>
    </form>
  </div>
  <table>
    <thead><tr><th>Policy #</th><th>Insurer</th><th>Type</th><th>Group</th><th>Start</th><th>End</th><th>Premium</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="<?= Util::baseUrl('index.php?page=policy_edit&id='.(int)$r['id']) ?>"><?= Util::h($r['policy_number']) ?></a></td>
        <td><?= Util::h($r['insurer']) ?></td>
        <td><?= Util::h($r['policy_type']) ?></td>
        <td><?= Util::h($r['display_name']) ?></td>
        <td><?= Util::h($r['start_date']) ?></td>
        <td><?= Util::h($r['end_date']) ?></td>
        <td>$<?= number_format($r['premium'],2) ?></td>
        <td><span class="pill <?= $r['status']==='active'?'primary':($r['status']==='expired'?'warn':'') ?>"><?= Util::h($r['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
