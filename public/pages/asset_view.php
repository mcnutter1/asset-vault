<?php
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/Util.php';
$pdo = Database::get();
$code = $_GET['code'] ?? '';
if (!$code) { http_response_code(404); echo '<div class="card"><h1>Not Found</h1></div>'; return; }

$stmt = $pdo->prepare('SELECT a.*, ac.name AS category_name, al.name AS location_name FROM assets a 
  LEFT JOIN asset_categories ac ON ac.id=a.category_id 
  LEFT JOIN asset_locations al ON al.id=a.asset_location_id
  WHERE a.public_token=? AND a.is_deleted=0');
$stmt->execute([$code]);
$asset = $stmt->fetch();
if (!$asset) { http_response_code(404); echo '<div class="card"><h1>Not Found</h1></div>'; return; }

// Photos
$photos = [];
$stmt = $pdo->prepare("SELECT id, filename FROM files WHERE entity_type='asset' AND entity_id=? AND mime_type LIKE 'image/%' ORDER BY uploaded_at DESC");
$stmt->execute([(int)$asset['id']]);
$photos = $stmt->fetchAll();

// Value summaries (current + replacement cost)
$curr = $pdo->prepare("SELECT amount FROM asset_values WHERE asset_id=? AND value_type='current' ORDER BY valuation_date DESC LIMIT 1");
$curr->execute([(int)$asset['id']]);
$currentValue = $curr->fetchColumn();
if ($currentValue !== false) $currentValue = (float)$currentValue; else $currentValue = null;

$repl = $pdo->prepare("SELECT amount FROM asset_values WHERE asset_id=? AND value_type='replace' ORDER BY valuation_date DESC LIMIT 1");
$repl->execute([(int)$asset['id']]);
$replaceValue = $repl->fetchColumn();
if ($replaceValue !== false) $replaceValue = (float)$replaceValue; else $replaceValue = null;

// Parent asset (if any)
$parent = null;
if (!empty($asset['parent_id'])) {
  $ps = $pdo->prepare('SELECT id, name FROM assets WHERE id=?');
  $ps->execute([(int)$asset['parent_id']]);
  $parent = $ps->fetch();
}

// Linked policies (direct)
$policies = [];
$stmt = $pdo->prepare('SELECT p.id, p.policy_number, p.insurer, p.policy_type, p.start_date, p.end_date FROM policy_assets pa JOIN policies p ON p.id=pa.policy_id WHERE pa.asset_id=? ORDER BY p.end_date DESC');
$stmt->execute([(int)$asset['id']]);
$policies = $stmt->fetchAll();

// Coverages for those policies
$policyCoverages = [];
if ($policies) {
  $ids = implode(',', array_map('intval', array_column($policies, 'id')));
  $covRows = $pdo->query('SELECT pc.policy_id, cd.code, cd.name, pc.limit_amount FROM policy_coverages pc JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id WHERE pc.policy_id IN ('.$ids.')')->fetchAll();
  foreach ($covRows as $cr) {
    $pid = (int)$cr['policy_id'];
    if (!isset($policyCoverages[$pid])) $policyCoverages[$pid] = [];
    $policyCoverages[$pid][] = $cr;
  }
}

// Build contents (children) table with values
$allAssets = $pdo->query('SELECT a.id, a.name, a.parent_id, ac.name AS category, al.name AS locname FROM assets a 
  LEFT JOIN asset_categories ac ON ac.id=a.category_id 
  LEFT JOIN asset_locations al ON al.id=a.asset_location_id
  WHERE a.is_deleted=0 ORDER BY a.parent_id, a.name')->fetchAll();
$byParent = [];
foreach ($allAssets as $a) { $byParent[$a['parent_id'] ?? 0][] = $a; }

// Latest replacement cost per asset
$replaceVals = $pdo->query("SELECT asset_id, amount, valuation_date FROM asset_values WHERE value_type='replace' ORDER BY valuation_date ASC")->fetchAll();
$replaceSelfValue = [];
foreach ($replaceVals as $v) { $replaceSelfValue[$v['asset_id']] = (float)$v['amount']; }

$memoTotals = [];
function pv_totalsFor($id, $byParent, $replaceSelfValue, &$memoTotals){
  if (isset($memoTotals[$id])) return $memoTotals[$id];
  $self = $replaceSelfValue[$id] ?? 0.0;
  $children = $byParent[$id] ?? [];
  $contents = 0.0;
  foreach ($children as $c){
    $t = pv_totalsFor($c['id'], $byParent, $replaceSelfValue, $memoTotals);
    $contents += $t['total'];
  }
  return $memoTotals[$id] = ['self'=>$self, 'contents'=>$contents, 'total'=>$self+$contents];
}

$flat = [];
function pv_flatten($parentId, $byParent, $depth, &$flat){
  if (empty($byParent[$parentId])) return;
  foreach ($byParent[$parentId] as $a){
    $a['depth'] = $depth;
    $flat[] = $a;
    pv_flatten($a['id'], $byParent, $depth+1, $flat);
  }
}
pv_flatten((int)$asset['id'], $byParent, 0, $flat);

// Policy inheritance / coverage for contents
// Preload policy_assets for all assets (for simple inheritance: parent policies with applies_to_children=1)
$policyAssets = $pdo->query('SELECT policy_id, asset_id, applies_to_children FROM policy_assets')->fetchAll();
$policiesByAsset = [];
foreach ($policyAssets as $pa) { $aid=(int)$pa['asset_id']; if(!isset($policiesByAsset[$aid])) $policiesByAsset[$aid]=[]; $policiesByAsset[$aid][] = $pa; }
// Build parent map
$parentMap = [];
foreach ($allAssets as $aRow) { $parentMap[(int)$aRow['id']] = $aRow['parent_id'] ? (int)$aRow['parent_id'] : null; }

function pv_applicablePolicies($assetId, $policiesByAsset, $parentMap) {
  $out = [];
  $cur = $assetId; // include self always
  $seen = [];
  while ($cur !== null) {
    if (!empty($policiesByAsset[$cur])) {
      foreach ($policiesByAsset[$cur] as $row) {
        $pid = (int)$row['policy_id'];
        $isSelf = ($cur === $assetId);
        if ($isSelf || (!$isSelf && (int)$row['applies_to_children'] === 1)) {
          $out[$pid] = true;
        }
      }
    }
    if (isset($seen[$cur])) break; // safety
    $seen[$cur] = true;
    $cur = $parentMap[$cur] ?? null;
  }
  return array_keys($out);
}

// Prefetch policy + coverage dictionaries for quick lookup
$policyMeta = [];
if ($policies) {
  foreach ($policies as $p) { $policyMeta[(int)$p['id']] = $p; }
}
// Also include inherited policies not in $policies list yet
foreach ($policyAssets as $paRow) { if (!isset($policyMeta[(int)$paRow['policy_id']])) { $pinfo = $pdo->prepare('SELECT id, policy_number, policy_type FROM policies WHERE id=?'); $pinfo->execute([(int)$paRow['policy_id']]); if ($r=$pinfo->fetch()) $policyMeta[(int)$r['id']]=$r; } }

// Coverage lookup for any policy id we have
if ($policyMeta) {
  $allPolicyIds = implode(',', array_map('intval', array_keys($policyMeta)));
  $allCovRows = $pdo->query('SELECT pc.policy_id, cd.code, cd.name, pc.limit_amount FROM policy_coverages pc JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id WHERE pc.policy_id IN ('.$allPolicyIds.')')->fetchAll();
  foreach ($allCovRows as $cr) { $pid=(int)$cr['policy_id']; if(!isset($policyCoverages[$pid])) $policyCoverages[$pid]=[]; $policyCoverages[$pid][]=$cr; }
}

?>
<div class="card">
  <div class="header-card">
    <div class="header-left">
      <div class="header-title"><?= Util::h($asset['name']) ?></div>
      <div class="header-meta">
        <span class="pill">Category: <?= Util::h($asset['category_name']) ?></span>
        <?php if ($asset['location_name']): ?><span class="pill">Location: <?= Util::h($asset['location_name']) ?></span><?php endif; ?>
        <?php if ($parent): ?><span class="pill">Parent: <?= Util::h($parent['name']) ?></span><?php endif; ?>
        <span class="value-pill">Current: <?= $currentValue!==null? ('$'.number_format($currentValue,2)) : '—' ?></span>
        <span class="value-pill">Replace: <?= $replaceValue!==null? ('$'.number_format($replaceValue,2)) : '—' ?></span>
        <?php if ($policies): ?>
          <?php foreach ($policies as $pol): $pid=(int)$pol['id']; $covs=$policyCoverages[$pid]??[]; $covCodes = $covs? implode(',', array_slice(array_map(fn($c)=>$c['code'],$covs),0,3)) : 'no-cov'; ?>
            <span class="pill">Policy <?= Util::h($pol['policy_number']) ?> (<?= Util::h($pol['policy_type']) ?><?= $covs? ': '.Util::h($covCodes) : '' ?>)</span>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php if ($asset['description']): ?><p><?= nl2br(Util::h($asset['description'])) ?></p><?php endif; ?>

  <div class="row">
    <div class="col-4"><label>Make</label><div><?= Util::h($asset['make']) ?></div></div>
    <div class="col-4"><label>Model</label><div><?= Util::h($asset['model']) ?></div></div>
    <div class="col-4"><label>Year</label><div><?= Util::h($asset['year']) ?></div></div>
  </div>

  <?php if ($photos): ?>
    <h2>Photos</h2>
    <div class="gallery" style="margin-top:8px">
      <?php foreach ($photos as $ph): ?>
        <img src="<?= Util::baseUrl('file.php?id='.(int)$ph['id']) ?>" alt="<?= Util::h($ph['filename']) ?>">
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($flat): ?>
<div class="card" style="margin-top:16px">
  <h2>Contents</h2>
  <table>
    <thead><tr><th>Asset</th><th>Category</th><th>Location</th><th>Coverage</th><th>Replace</th><th>Contents</th><th>Total</th></tr></thead>
    <tbody>
      <?php foreach ($flat as $row): $t = pv_totalsFor($row['id'], $byParent, $replaceSelfValue, $memoTotals); $appPols = pv_applicablePolicies($row['id'], $policiesByAsset, $parentMap); $coverageCodes=[]; foreach ($appPols as $pid){ if(!empty($policyCoverages[$pid])){ foreach ($policyCoverages[$pid] as $cc){ $coverageCodes[$cc['code']]=true; } } } $coverageDisplay = $coverageCodes? implode(',', array_slice(array_keys($coverageCodes),0,5)) : '—'; ?>
        <tr>
          <td><div style="padding-left: <?= (int)$row['depth']*16 ?>px"><?= Util::h($row['name']) ?></div></td>
          <td><?= Util::h($row['category']) ?></td>
          <td><?= $row['locname'] ? Util::h($row['locname']) : '-' ?></td>
          <td><?= Util::h($coverageDisplay) ?></td>
          <td>$<?= number_format($t['self'], 2) ?></td>
          <td>$<?= number_format($t['contents'], 2) ?></td>
          <td><strong>$<?= number_format($t['total'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
