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
$stmt = $pdo->prepare("SELECT id, filename, mime_type, size, caption, uploaded_at FROM files WHERE entity_type='asset' AND entity_id=? AND is_trashed=0 AND mime_type LIKE 'image/%' ORDER BY uploaded_at DESC");
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
// Ensure totals include the root asset so contents sum is available
$rootTotals = pv_totalsFor((int)$asset['id'], $byParent, $replaceSelfValue, $memoTotals);

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

// Coverage lookup for any policy id we have (include coverage definition applicable_types)
if ($policyMeta) {
  $allPolicyIds = implode(',', array_map('intval', array_keys($policyMeta)));
  $allCovRows = $pdo->query('SELECT pc.policy_id, cd.code, cd.name, cd.applicable_types, pc.limit_amount FROM policy_coverages pc JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id WHERE pc.policy_id IN ('.$allPolicyIds.')')->fetchAll();
  foreach ($allCovRows as $cr) { $pid=(int)$cr['policy_id']; if(!isset($policyCoverages[$pid])) $policyCoverages[$pid]=[]; $policyCoverages[$pid][]=$cr; }
}

// Helper: map asset category name to coverage type key(s)
function pv_category_keys(string $catName): array {
  $c = strtolower($catName);
  $map = [
    'home' => ['home'], 'house'=>['home'], 'property'=>['home'], 'residence'=>['home'],
    'electronics'=>['electronics'], 'appliances'=>['home','other'], 'furniture'=>['home','other'],
    'vehicle'=>['auto'], 'car'=>['auto'], 'truck'=>['auto'], 'auto'=>['auto'],
    'boat'=>['boat'], 'jewelry'=>['jewelry'], 'jewelery'=>['jewelry'],
  ];
  return $map[$c] ?? ['other'];
}

// Pre-filter coverages per policy per category key
$policyCovFiltered = []; // [policyId][categoryNameLower] => array of coverage codes
foreach ($policyCoverages as $pid => $covList) {
  foreach ($covList as $cov) {
    $appTypes = array_filter(array_map('trim', explode(',', (string)$cov['applicable_types'])));
    foreach ($appTypes as $atype) { $atype = strtolower($atype); if(!isset($policyCovFiltered[$pid][$atype])) $policyCovFiltered[$pid][$atype]=[]; $policyCovFiltered[$pid][$atype][] = $cov['code']; }
  }
  // De-dup
  foreach ($policyCovFiltered[$pid] ?? [] as $k=>$codes) { $policyCovFiltered[$pid][$k] = array_values(array_unique($codes)); }
}

// For the primary asset: gather coverage codes relevant to its category
$primaryCategoryKeys = $asset['category_name'] ? pv_category_keys($asset['category_name']) : [];
$primaryPolicyCoverageCodes = []; // [policyId] => codes array filtered
if ($policies && $primaryCategoryKeys) {
  foreach ($policies as $p) {
    $pid = (int)$p['id'];
    $codes = [];
    foreach ($primaryCategoryKeys as $ck) { if (!empty($policyCovFiltered[$pid][strtolower($ck)])) { $codes = array_merge($codes, $policyCovFiltered[$pid][strtolower($ck)]); } }
    $primaryPolicyCoverageCodes[$pid] = array_values(array_unique($codes));
  }
}

// Build quick lookup: coverageByPolicy[policy_id][code] = coverage row
$coverageByPolicy = [];
foreach ($policyCoverages as $pid => $covList) {
  foreach ($covList as $row) { $coverageByPolicy[$pid][$row['code']] = $row; }
}

// Determine single primary coverage code per category (priority order)
function pv_primary_coverage_candidates(string $catName): array {
  $c = strtolower($catName);
  return match($c) {
    'home','house','property','residence' => ['dwelling'],
    'electronics' => ['scheduled_property','personal_property'],
    'jewelry','jewelery' => ['scheduled_property'],
    'appliances','furniture' => ['personal_property'],
    'vehicle','car','truck','auto' => ['collision','comprehensive'],
    'boat' => ['boat_hull','boat_equipment'],
    default => ['scheduled_property','personal_property']
  };
}

// Helper to choose coverage row for a given policy and category
function pv_choose_coverage_for_policy(array $policy, string $categoryName, array $coverageByPolicy): ?array {
  $pid = (int)$policy['id'];
  if (empty($coverageByPolicy[$pid])) return null;
  foreach (pv_primary_coverage_candidates($categoryName) as $cand) {
    if (isset($coverageByPolicy[$pid][$cand])) return $coverageByPolicy[$pid][$cand];
  }
  return null;
}

// Header: compute chosen coverage per direct policy for the primary asset
$primaryPolicyChosen = [];
if ($policies) {
  foreach ($policies as $p) {
    $primaryPolicyChosen[(int)$p['id']] = pv_choose_coverage_for_policy($p, (string)$asset['category_name'], $coverageByPolicy);
  }
}

?>
<div class="card asset-header">
  <div class="asset-primary" style="gap:16px;">
    <div class="top-block">
      <div class="header-title" style="margin:0 0 2px; font-size:26px; font-weight:800; letter-spacing:.3px;">
        <?= Util::h($asset['name']) ?>
      </div>
      <div class="asset-tags">
        <span class="pill">Category: <?= Util::h($asset['category_name']) ?></span>
        <?php if ($asset['location_name']): ?><span class="pill">Location: <?= Util::h($asset['location_name']) ?></span><?php endif; ?>
        <?php if ($parent): ?><span class="pill">Parent: <?= Util::h($parent['name']) ?></span><?php endif; ?>
        <?php if ($primaryPolicyChosen): ?>
          <?php $pCount = count($primaryPolicyChosen); ?><span class="pill primary">Policies: <?= $pCount ?></span>
        <?php endif; ?>
      </div>
      <?php if ($asset['description']): ?><div class="small" style="line-height:1.5; margin-top:6px; max-width:760px;"><?= nl2br(Util::h($asset['description'])) ?></div><?php endif; ?>
    </div>
  <?php $contentsSum = $rootTotals['contents']; $totalVal = $rootTotals['total']; ?>
    <div class="asset-attributes">
      <div class="asset-attr"><span class="attr-label">Make</span><span class="attr-value"><?= Util::h($asset['make']) ?: '—' ?></span></div>
      <div class="asset-attr"><span class="attr-label">Model</span><span class="attr-value"><?= Util::h($asset['model']) ?: '—' ?></span></div>
      <div class="asset-attr"><span class="attr-label">Year</span><span class="attr-value"><?= Util::h($asset['year']) ?: '—' ?></span></div>
      <div class="asset-attr"><span class="attr-label">Token</span><span class="attr-value" style="font-family:monospace; font-size:11px; overflow:hidden; text-overflow:ellipsis; max-width:160px; display:inline-block;">
        <?= Util::h(substr($asset['public_token'] ?? '',0,20)) ?>
      </span></div>
    </div>
    <div class="insurance-block">
      <div class="insurance-header">Insurance</div>
      <?php if ($policies): ?>
        <div class="policy-list">
          <?php foreach ($policies as $pol): $pid=(int)$pol['id']; $chosen = $primaryPolicyChosen[$pid] ?? null; ?>
            <div class="policy-item">
              <div><span class="p-label">Policy #</span><span class="p-value"><?= Util::h($pol['policy_number']) ?></span></div>
              <div><span class="p-label">Insurer</span><span class="p-value"><?= Util::h($pol['insurer']) ?></span></div>
              <div><span class="p-label">Type</span><span class="p-value"><?= Util::h($pol['policy_type']) ?></span></div>
              <div><span class="p-label">Primary Coverage</span><span class="p-value"><?= $chosen? '<span class="coverage-badge">'.Util::h($chosen['code']).'</span>' : '—' ?></span></div>
              <div><span class="p-label">Limit</span><span class="p-value"><?= ($chosen && $chosen['limit_amount']!==null)? '$'.number_format((float)$chosen['limit_amount'],0) : '—' ?></span></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="small muted">No policies linked.</div>
      <?php endif; ?>
    </div>
    <?php if ($photos): ?>
      <div class="mini-gallery" style="margin-top:12px;">
        <div class="small muted" style="margin:0 0 6px; font-weight:600;letter-spacing:.5px;text-transform:uppercase;">Photos</div>
        <div class="gallery" style="margin-top:0; grid-template-columns:repeat(auto-fit,minmax(110px,1fr));">
          <?php foreach ($photos as $ph): ?>
            <img data-file-id="<?= (int)$ph['id'] ?>" data-filename="<?= Util::h($ph['filename']) ?>" data-size="<?= (int)$ph['size'] ?>" data-uploaded="<?= Util::h($ph['uploaded_at']) ?>" src="<?= Util::baseUrl('file.php?id='.(int)$ph['id']) ?>" alt="<?= Util::h($ph['filename']) ?>">
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Dashboard-style stat cards for values -->
<div class="row" style="margin-top:16px">
  <div class="col-3"><div class="card stat"><div class="stat-title">Current Value</div><div class="stat-value"><?= $currentValue!==null? '$'.number_format($currentValue,2) : '—' ?></div><div class="muted">Latest reported</div></div></div>
  <div class="col-3"><div class="card stat"><div class="stat-title">Replacement</div><div class="stat-value"><?= $replaceValue!==null? '$'.number_format($replaceValue,2) : '—' ?></div><div class="muted">Latest replacement</div></div></div>
  <div class="col-3"><div class="card stat"><div class="stat-title">Contents Sum</div><div class="stat-value"><?= $contentsSum? '$'.number_format($contentsSum,2) : '—' ?></div><div class="muted">Sum of nested items</div></div></div>
  <div class="col-3"><div class="card stat"><div class="stat-title">Total Protected</div><div class="stat-value"><span><?= $totalVal? '$'.number_format($totalVal,2) : '—' ?></span></div><div class="muted">Self + contents</div></div></div>
</div>

<!-- Details & photos moved inside header card above -->

<?php if ($flat): ?>
<div class="card" style="margin-top:16px">
  <div class="section-head"><h2>Contents</h2><div class="small muted">Replacement + nested totals</div></div>
  <div class="table-wrap">
  <table>
    <thead><tr><th>Asset</th><th>Category</th><th>Location</th><th>Coverage</th><th>Replace</th><th>Contents</th><th>Total</th></tr></thead>
    <tbody>
      <?php foreach ($flat as $row): $t = pv_totalsFor($row['id'], $byParent, $replaceSelfValue, $memoTotals);
        // Determine applicable policies (self + inherited) then select first coverage candidate available
        $appPols = pv_applicablePolicies($row['id'], $policiesByAsset, $parentMap);
        $chosenCoverageCode = '—';
        foreach (pv_primary_coverage_candidates($row['category'] ?: '') as $cand) {
          $found = false;
            foreach ($appPols as $pid) {
              if (!empty($coverageByPolicy[$pid][$cand])) { $chosenCoverageCode = $coverageByPolicy[$pid][$cand]['code']; $found = true; break; }
            }
          if ($found) break;
        }
      ?>
        <tr>
          <td><div style="padding-left: <?= (int)$row['depth']*16 ?>px"><?= Util::h($row['name']) ?></div></td>
          <td><?= Util::h($row['category']) ?></td>
          <td><?= $row['locname'] ? Util::h($row['locname']) : '-' ?></td>
          <td><?= Util::h($chosenCoverageCode) ?></td>
          <td>$<?= number_format($t['self'], 2) ?></td>
          <td>$<?= number_format($t['contents'], 2) ?></td>
          <td><strong>$<?= number_format($t['total'], 2) ?></strong></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>
