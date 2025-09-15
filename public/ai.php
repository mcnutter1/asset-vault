<?php
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Util.php';
require_once __DIR__ . '/../lib/Ai.php';
require_once __DIR__ . '/../lib/Settings.php';
require_once __DIR__ . '/../lib/PropertyScraper.php';

header('Content-Type: application/json');

function json_out($arr, $code = 200){ http_response_code($code); echo json_encode($arr); exit; }

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false,'error'=>'POST required'], 405);
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $csrf = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
  if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) json_out(['ok'=>false,'error'=>'Invalid CSRF'], 400);

  $action = $_POST['action'] ?? '';
  $assetId = (int)($_POST['asset_id'] ?? 0);
  if (!$assetId) json_out(['ok'=>false,'error'=>'asset_id required'], 400);

  $pdo = Database::get();
  $stmt = $pdo->prepare('SELECT a.*, ac.name AS category_name FROM assets a LEFT JOIN asset_categories ac ON ac.id=a.category_id WHERE a.id=? AND a.is_deleted=0');
  $stmt->execute([$assetId]);
  $asset = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$asset) json_out(['ok'=>false,'error'=>'Asset not found'], 404);

  if ($action === 'estimate') {
    try {
      $aiKey = Settings::get('openai_api_key', Util::config()['openai']['api_key'] ?? null);
      $model = Settings::get('openai_model', 'gpt-4.1');
      $ai = new AiClient($aiKey, $model);
      $category = strtolower($asset['category_name'] ?? '');

      if (strpos($category, 'home') !== false || strpos($category, 'house') !== false || $category === 'home' || strpos($category, 'property') !== false) {
        // House: gather address and format full string
        $addrStmt = $pdo->prepare("SELECT * FROM asset_addresses WHERE asset_id=? AND address_type='physical' ORDER BY updated_at DESC LIMIT 1");
        $addrStmt->execute([$assetId]);
        $addr = $addrStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $address1 = trim(($addr['line1'] ?? '') . ' ' . ($addr['line2'] ?? ''));
        $fullAddress = trim($address1 . ', ' . ($addr['city'] ?? '') . ', ' . ($addr['state'] ?? '') . ' ' . ($addr['postal_code'] ?? ''));
        $house = [
          'address' => $address1,
          'address_full' => $fullAddress,
          'city' => $addr['city'] ?? '',
          'state' => $addr['state'] ?? '',
          'zip' => $addr['postal_code'] ?? '',
          'country' => $addr['country'] ?? '',
          'latitude' => isset($addr['latitude']) ? (float)$addr['latitude'] : null,
          'longitude' => isset($addr['longitude']) ? (float)$addr['longitude'] : null,
          'sq_ft' => null,
          'lot_size_acres' => null,
          'year_built' => $asset['year'] ?? null,
          'beds' => null,
          'baths' => null,
          'condition' => substr(trim(($asset['description'] ?? '') . ' ' . ($asset['notes'] ?? '')), 0, 200),
        ];
        // Try to gather public facts from Zillow/Redfin to improve accuracy
        $facts = PropertyScraper::gather($house, [
          'zillow_url' => $_POST['zillow_url'] ?? null,
          'redfin_url' => $_POST['redfin_url'] ?? null,
        ]);

        // If we have authoritative market value, prefer it (facts-first). Avoid AI guessing.
        $market = null; $sourceUrls = [];
        if (!empty($facts['zestimate_usd'])) { $market = (float)$facts['zestimate_usd']; }
        elseif (!empty($facts['redfin_estimate_usd'])) { $market = (float)$facts['redfin_estimate_usd']; }
        if (!empty($facts['zillow_url'])) $sourceUrls[] = $facts['zillow_url'];
        if (!empty($facts['redfin_url'])) $sourceUrls[] = $facts['redfin_url'];

        if ($market !== null) {
          $repl = null; $assump = [];
          $rate = (float)Settings::get('rebuild_cost_per_sqft', '350');
          if (!empty($facts['sq_ft'])) {
            // Use a conservative high-quality rebuild rate; user can adjust later
            $repl = round($facts['sq_ft'] * $rate, 2);
            $assump[] = "Replacement cost uses $rate USD/sqft x ".$facts['sq_ft']." sqft.";
          } else {
            $assump[] = 'Replacement cost pending sqft; please verify size or enter manually.';
          }
          $assump[] = 'Market value from public sources (Zillow/Redfin).';
          $result = [
            'valuation' => [
              'market_value_usd' => $market,
              'replacement_cost_usd' => $repl,
              'assumptions' => implode(' ', $assump),
              'confidence' => 'high',
              'sources' => $sourceUrls,
            ]
          ];
        } else {
          // No facts found; run AI estimation using exact address
          $result = ValueEstimators::valueHouse($ai, $house, $facts);
          $result['notice'] = 'facts_missing';
        }
        json_out(['ok'=>true,'type'=>'house','data'=>$result]);
      } elseif (strpos($category, 'elect') !== false) {
        // Electronics: build device payload
        $pv = $pdo->prepare("SELECT amount FROM asset_values WHERE asset_id=? AND value_type='purchase' ORDER BY valuation_date DESC LIMIT 1");
        $pv->execute([$assetId]);
        $purchase = $pv->fetchColumn();
        $device = [
          'category' => $asset['category_name'] ?? 'Electronics',
          'brand' => $asset['make'] ?? '',
          'model' => $asset['model'] ?? '',
          'year' => $asset['year'] ?? null,
          'condition' => substr(trim(($asset['description'] ?? '') . ' ' . ($asset['notes'] ?? '')), 0, 200),
          'purchase_price_usd' => $purchase !== false ? (float)$purchase : null,
          'purchase_date' => $asset['purchase_date'] ?? null,
        ];
        $result = ValueEstimators::valueElectronics($ai, $device);
        json_out(['ok'=>true,'type'=>'electronics','data'=>$result]);
      } else {
        json_out(['ok'=>false,'error'=>'Unsupported category for AI'], 200);
      }
    } catch (Throwable $e) {
      json_out(['ok'=>false,'error'=>$e->getMessage()], 200);
    }
  }

  if ($action === 'apply') {
    // Apply valuation into asset_values: expects fields market_value_usd, replacement_cost_usd
    $kind = $_POST['type'] ?? '';
    $market = isset($_POST['market_value_usd']) ? (float)$_POST['market_value_usd'] : null;
    $repl = isset($_POST['replacement_cost_usd']) ? (float)$_POST['replacement_cost_usd'] : null;
    if ($market === null && $repl === null) json_out(['ok'=>false,'error'=>'Nothing to apply'], 400);
    $today = date('Y-m-d');
    if ($market !== null) {
      $stmt = $pdo->prepare('INSERT INTO asset_values(asset_id, value_type, amount, valuation_date, source) VALUES (?,?,?,?,?)');
      $stmt->execute([$assetId, 'current', $market, $today, 'ai']);
    }
    if ($repl !== null) {
      $stmt = $pdo->prepare('INSERT INTO asset_values(asset_id, value_type, amount, valuation_date, source) VALUES (?,?,?,?,?)');
      $stmt->execute([$assetId, 'replace', $repl, $today, 'ai']);
    }
    json_out(['ok'=>true]);
  }

  json_out(['ok'=>false,'error'=>'Invalid action'], 400);
} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}
