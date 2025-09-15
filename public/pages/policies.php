<?php
$pdo = Database::get();

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
  <div class="section-head" style="margin-bottom:8px">
    <h1>Policies</h1>
    <a class="btn sm" href="<?= Util::baseUrl('index.php?page=policy_edit&group_id=0') ?>">Add Policy</a>
  </div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Policy #</th><th>Insurer</th><th>Type</th><th>Start</th><th>End</th><th>Premium</th><th>Status</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="<?= Util::baseUrl('index.php?page=policy_edit&id='.(int)$r['id']) ?>"><?= Util::h($r['policy_number']) ?></a></td>
        <td><?= Util::h($r['insurer']) ?></td>
        <td><?= Util::h($r['policy_type']) ?></td>
        <td><?= Util::h($r['start_date']) ?></td>
        <td><?= Util::h($r['end_date']) ?></td>
        <td>$<?= number_format($r['premium'],2) ?></td>
        <td><span class="pill <?= $r['status']==='active'?'primary':($r['status']==='expired'?'warn':'') ?>"><?= Util::h($r['status']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
