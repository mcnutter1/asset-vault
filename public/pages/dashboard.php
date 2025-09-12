<?php
$pdo = Database::get();

$assetsCount = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE is_deleted=0")->fetchColumn();
$policiesCount = (int)$pdo->query("SELECT COUNT(*) FROM policies")->fetchColumn();
$expiring = $pdo->query("SELECT p.*, pg.display_name FROM policies p JOIN policy_groups pg ON pg.id=p.policy_group_id WHERE p.end_date >= CURDATE() AND p.end_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) ORDER BY p.end_date ASC LIMIT 10")->fetchAll();

?>
<div class="row">
  <div class="col-6">
    <div class="card">
      <h1>Overview</h1>
      <div class="list">
        <div class="item"><div>Total Assets</div><div class="pill primary"><?= $assetsCount ?></div></div>
        <div class="item"><div>Total Policies</div><div class="pill primary"><?= $policiesCount ?></div></div>
      </div>
    </div>
  </div>
  <div class="col-6">
    <div class="card">
      <h1>Upcoming Expirations</h1>
      <?php if (!$expiring): ?>
        <div class="small muted">No policies expiring in next 60 days.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr><th>Policy</th><th>Insurer</th><th>End</th><th>Status</th></tr>
          </thead>
          <tbody>
            <?php foreach ($expiring as $p): ?>
              <tr>
                <td><a href="<?= Util::baseUrl('index.php?page=policy_edit&id='.(int)$p['id']) ?>"><?= Util::h($p['policy_number']) ?></a></td>
                <td><?= Util::h($p['insurer']) ?></td>
                <td><?= Util::h($p['end_date']) ?></td>
                <td><span class="pill <?= $p['status']==='active'?'primary':'warn' ?>"><?= Util::h($p['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
