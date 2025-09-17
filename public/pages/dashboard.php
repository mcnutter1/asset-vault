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

// Load all assets for hierarchy operations
$allAssets = $pdo->query('SELECT a.id, a.parent_id, a.name, ac.name AS category FROM assets a LEFT JOIN asset_categories ac ON ac.id=a.category_id WHERE a.is_deleted=0')->fetchAll();
$byId = []; $byParent = [];
foreach ($allAssets as $a){ $byId[$a['id']] = $a; $byParent[$a['parent_id'] ?? 0][] = $a; }

// Helper: find root (top-most parent) for an asset id
$rootMemo = [];
$findRoot = function($id) use (&$findRoot, &$byId, &$rootMemo){
  if (isset($rootMemo[$id])) return $rootMemo[$id];
  $cur = $byId[$id] ?? null;
  if (!$cur) return $rootMemo[$id] = $id;
  while ($cur && !empty($cur['parent_id'])) { $cur = $byId[$cur['parent_id']] ?? null; }
  return $rootMemo[$id] = ($cur['id'] ?? $id);
};

// Latest self current value per asset
$rows = $pdo->query("SELECT av.asset_id, av.amount FROM asset_values av JOIN (SELECT asset_id, MAX(valuation_date) mx FROM asset_values WHERE value_type='current' GROUP BY asset_id) t ON t.asset_id=av.asset_id AND t.mx=av.valuation_date WHERE av.value_type='current'")->fetchAll();
$selfCurrent = [];
foreach ($rows as $r){ $selfCurrent[$r['asset_id']] = (float)$r['amount']; }

// Total (plus contents): for each top-level asset, sum selfCurrent across its subtree
$memoTotals = [];
$sumSubtree = function($id) use (&$sumSubtree, &$byParent, &$selfCurrent, &$memoTotals){
  if (isset($memoTotals[$id])) return $memoTotals[$id];
  $sum = $selfCurrent[$id] ?? 0.0;
  foreach ($byParent[$id] ?? [] as $c){ $sum += $sumSubtree($c['id']); }
  return $memoTotals[$id] = $sum;
};

// Stats minus/plus contents
$topLevel = $byParent[0] ?? [];
$totalMinusContents = 0.0; // only top-level self values
foreach ($topLevel as $t) { $totalMinusContents += ($selfCurrent[$t['id']] ?? 0.0); }
$totalPlusContents = 0.0; // sum of subtree for each top-level (equals sum of all self)
foreach ($topLevel as $t) { $totalPlusContents += $sumSubtree($t['id']); }

// Build per-top asset aggregated trends (sum of subtree current values by date)
$allCurr = $pdo->query("SELECT asset_id, valuation_date d, amount FROM asset_values WHERE value_type='current' ORDER BY valuation_date ASC")->fetchAll();
$aggByRootDate = [];
foreach ($allCurr as $r){
  $rid = $findRoot($r['asset_id']);
  $d = $r['d'];
  if (!isset($aggByRootDate[$rid])) $aggByRootDate[$rid] = [];
  if (!isset($aggByRootDate[$rid][$d])) $aggByRootDate[$rid][$d] = 0.0;
  $aggByRootDate[$rid][$d] += (float)$r['amount'];
}
// Prepare series for a subset of top-level assets
$perAssetCards = [];
foreach ($topLevel as $t) {
  $rid = $t['id'];
  $dates = $aggByRootDate[$rid] ?? [];
  ksort($dates);
  $series = [];
  foreach ($dates as $d=>$sum) { $series[] = ['x'=>$d, 'y'=>$sum]; }
  $cat = $t['category'] ?? '';
  $perAssetCards[] = [
    'id' => (int)$rid,
    'name' => $t['name'],
    'category' => $cat,
    'self' => ($selfCurrent[$rid] ?? 0.0),
    'total' => $sumSubtree($rid),
    'series' => $series,
  ];
}
// Limit cards to first 8 for layout
$perAssetCards = array_slice($perAssetCards, 0, 8);
$expiring = $pdo->query("SELECT p.*, pg.display_name FROM policies p JOIN policy_groups pg ON pg.id=p.policy_group_id WHERE p.end_date >= CURDATE() AND p.end_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) ORDER BY p.end_date ASC LIMIT 10")->fetchAll();

// Incomplete assets detection
$assetRows = $pdo->query("SELECT a.id, a.name, a.make, a.model, a.year, a.updated_at, ac.name AS category FROM assets a LEFT JOIN asset_categories ac ON ac.id=a.category_id WHERE a.is_deleted=0 ORDER BY a.name")->fetchAll();
$photoMapRows = $pdo->query("SELECT entity_id aid, COUNT(*) c FROM files WHERE entity_type='asset' AND is_trashed=0 AND mime_type LIKE 'image/%' GROUP BY entity_id")->fetchAll();
$photoMap = []; foreach ($photoMapRows as $r){ $photoMap[(int)$r['aid']] = (int)$r['c']; }
$hasValueRows = $pdo->query("SELECT DISTINCT asset_id FROM asset_values WHERE value_type IN ('current','replace')")->fetchAll();
$hasValue = []; foreach ($hasValueRows as $r){ $hasValue[(int)$r['asset_id']] = true; }
$incompleteAssets = [];
foreach ($assetRows as $a){
  $reasons = [];
  $cat = strtolower($a['category'] ?? '');
  if (($photoMap[(int)$a['id']] ?? 0) <= 0) $reasons[] = 'No photos';
  if (empty($hasValue[(int)$a['id']])) $reasons[] = 'No value';
  $needsMakeModel = !in_array($cat, ['home','house','property','residence']);
  if ($needsMakeModel && (trim((string)$a['make'])==='' && trim((string)$a['model'])==='')) $reasons[] = 'Make/Model missing';
  if (in_array($cat, ['vehicle','car','truck','auto','boat']) && empty($a['year'])) $reasons[] = 'Year missing';
  if ($reasons) { $incompleteAssets[] = ['id'=>(int)$a['id'],'name'=>$a['name'],'category'=>$a['category'],'updated_at'=>$a['updated_at'],'reasons'=>$reasons]; }
}
// limit to 10 for the dashboard
$incompleteAssets = array_slice($incompleteAssets, 0, 10);

?>
<div class="row">
  <div class="col-3"><div class="card stat"><div class="stat-title">Total Assets</div><div class="stat-value"><?= $assetsCount ?></div><div class="muted">Items in portfolio</div></div></div>
  <div class="col-3"><div class="card stat"><div class="stat-title">Asset Value (− Contents)</div><div class="stat-value">$<?= number_format($totalMinusContents,2) ?></div><div class="muted">Top-level items only</div></div></div>
  <div class="col-3"><div class="card stat"><div class="stat-title">Asset Value (+ Contents)</div><div class="stat-value">$<?= number_format($totalPlusContents,2) ?></div><div class="muted">Includes contents</div></div></div>
  <div class="col-3"><div class="card stat"><div class="stat-title">Protection (Over/Under)</div><div class="stat-value <?= $overUnder>=0?'':'muted' ?>"><?= ($overUnder>=0?'+':'−') ?>$<?= number_format(abs($overUnder),2) ?></div><div class="muted">Coverage vs Replacement</div></div></div>
</div>

<div class="row" style="margin-top:16px">
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
  <div class="col-6">
    <div class="card">
      <h1>Incomplete Assets</h1>
      <?php if (!$incompleteAssets): ?>
        <div class="small muted">Great job — no incomplete assets found.</div>
      <?php else: ?>
        <table>
          <thead><tr><th>Asset</th><th>Category</th><th>Issues</th><th>Updated</th></tr></thead>
          <tbody>
            <?php foreach ($incompleteAssets as $ia): ?>
              <tr>
                <td><a href="<?= Util::baseUrl('index.php?page=asset_edit&id='.(int)$ia['id']) ?>"><?= Util::h($ia['name']) ?></a></td>
                <td><?= Util::h($ia['category']) ?></td>
                <td><?= Util::h(implode(', ', $ia['reasons'])) ?></td>
                <td><?= Util::h($ia['updated_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="small muted">Assets listed here are missing key information (photos, values, or details). Click a name to complete.</div>
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

<?php if ($perAssetCards): ?>
<div class="row" style="margin-top:16px">
  <?php foreach ($perAssetCards as $pt): ?>
    <?php
      $icon = '📦';
      $cat = strtolower($pt['category'] ?? '');
      if (strpos($cat,'home')!==false || strpos($cat,'house')!==false) $icon='🏠';
      elseif (strpos($cat,'vehicle')!==false || strpos($cat,'car')!==false) $icon='🚗';
      elseif (strpos($cat,'boat')!==false) $icon='🚤';
      elseif (strpos($cat,'elect')!==false) $icon='📺';
      elseif (strpos($cat,'furn')!==false) $icon='🛋️';
      elseif (strpos($cat,'appliance')!==false) $icon='🧺';
      elseif (strpos($cat,'jewel')!==false) $icon='💍';
    ?>
  <div class="col-3">
    <div class="card mini">
      <div class="top"><div class="icon"><?= $icon ?></div><div class="name"><?= Util::h($pt['name']) ?></div></div>
      <div class="small muted">Self: $<?= number_format($pt['self'],2) ?> • Total: $<?= number_format($pt['total'],2) ?></div>
      <div style="width:100%;height:120px">
        <canvas data-autodraw data-series='<?= Util::h(json_encode($pt['series'])) ?>' style="width:100%;height:120px"></canvas>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
