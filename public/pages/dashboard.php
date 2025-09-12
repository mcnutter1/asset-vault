<?php
$pdo = Database::get();

$assetsCount = (int)$pdo->query("SELECT COUNT(*) FROM assets WHERE is_deleted=0")->fetchColumn();
$policiesCount = (int)$pdo->query("SELECT COUNT(*) FROM policies")->fetchColumn();

// Sum of latest current values across all assets
$sqlLatestCurrent = "SELECT SUM(av.amount) FROM asset_values av
  JOIN (SELECT asset_id, MAX(valuation_date) mx FROM asset_values WHERE value_type='current' GROUP BY asset_id) t
    ON t.asset_id=av.asset_id AND t.mx=av.valuation_date
  WHERE av.value_type='current'";
$totalCurrent = (float)$pdo->query($sqlLatestCurrent)->fetchColumn();

// Sum of latest replacement values across all assets
$sqlLatestReplace = "SELECT SUM(av.amount) FROM asset_values av
  JOIN (SELECT asset_id, MAX(valuation_date) mx FROM asset_values WHERE value_type='replace' GROUP BY asset_id) t
    ON t.asset_id=av.asset_id AND t.mx=av.valuation_date
  WHERE av.value_type='replace'";
$totalReplace = (float)$pdo->query($sqlLatestReplace)->fetchColumn();

// Coverage total (approx): sum of property-related coverages on active policies
$coverageCodes = [
  'dwelling','other_structures','personal_property','boat_hull','boat_equipment','flood_building','flood_contents','scheduled_property','collision','comprehensive'
];
$in = str_repeat('?,', count($coverageCodes)-1) . '?';
$stmt = $pdo->prepare("SELECT SUM(pc.limit_amount) FROM policy_coverages pc
  JOIN policies p ON p.id=pc.policy_id
  JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id
  WHERE p.status='active' AND pc.limit_amount IS NOT NULL AND cd.code IN ($in)");
$stmt->execute($coverageCodes);
$totalCoverage = (float)$stmt->fetchColumn();
$overUnder = $totalCoverage - $totalReplace;

// Portfolio trend (approx): sum of current values by valuation date
$trendRows = $pdo->query("SELECT valuation_date d, SUM(amount) s FROM asset_values WHERE value_type='current' GROUP BY valuation_date ORDER BY valuation_date ASC")->fetchAll();
$trend = [];
foreach ($trendRows as $r) { $trend[] = ['x'=>$r['d'], 'y'=>(float)$r['s']]; }

// Top-level assets and their individual trends
$topAssets = $pdo->query("SELECT id, name FROM assets WHERE is_deleted=0 AND parent_id IS NULL ORDER BY name LIMIT 6")->fetchAll();
$perAssetTrends = [];
foreach ($topAssets as $ta) {
  $stmt = $pdo->prepare("SELECT valuation_date d, amount s FROM asset_values WHERE asset_id=? AND value_type='current' ORDER BY valuation_date ASC");
  $stmt->execute([(int)$ta['id']]);
  $rows = $stmt->fetchAll();
  $series = [];
  foreach ($rows as $r) { $series[] = ['x'=>$r['d'], 'y'=>(float)$r['s']]; }
  $perAssetTrends[] = ['id'=>(int)$ta['id'], 'name'=>$ta['name'], 'series'=>$series];
}
$expiring = $pdo->query("SELECT p.*, pg.display_name FROM policies p JOIN policy_groups pg ON pg.id=p.policy_group_id WHERE p.end_date >= CURDATE() AND p.end_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) ORDER BY p.end_date ASC LIMIT 10")->fetchAll();

?>
<div class="row">
  <div class="col-6">
    <div class="card">
      <h1>Overview</h1>
      <div class="list">
        <div class="item"><div>Total Assets</div><div class="pill primary"><?= $assetsCount ?></div></div>
        <div class="item"><div>Total Policies</div><div class="pill primary"><?= $policiesCount ?></div></div>
        <div class="item"><div>Portfolio (Current)</div><div class="pill primary">$<?= number_format($totalCurrent,2) ?></div></div>
        <div class="item"><div>Replacement Cost</div><div class="pill">$<?= number_format($totalReplace,2) ?></div></div>
        <div class="item"><div>Coverage (Active)</div><div class="pill">$<?= number_format($totalCoverage,2) ?></div></div>
        <div class="item"><div>Over / Under</div><div class="pill <?= $overUnder>=0?'primary':'danger' ?>"><?= ($overUnder>=0?'+':'-') ?>$<?= number_format(abs($overUnder),2) ?></div></div>
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

<div class="row" style="margin-top:16px">
  <div class="col-12">
    <div class="card">
      <h1>Portfolio Trend</h1>
      <div style="width:100%;height:220px">
        <canvas data-autodraw data-series='<?= Util::h(json_encode($trend)) ?>' style="width:100%;height:220px"></canvas>
      </div>
      <div class="small muted">Trend uses sum of reported current values by date.</div>
    </div>
  </div>
</div>

<?php if ($perAssetTrends): ?>
<div class="row" style="margin-top:16px">
  <?php foreach ($perAssetTrends as $pt): ?>
  <div class="col-6">
    <div class="card">
      <h2><?= Util::h($pt['name']) ?></h2>
      <div style="width:100%;height:180px">
        <canvas data-autodraw data-series='<?= Util::h(json_encode($pt['series'])) ?>' style="width:100%;height:180px"></canvas>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
