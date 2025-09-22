<?php

class ZillowPlugin
{
    private array $meta;
    private array $config;

    public function __construct(array $meta, array $config)
    {
        $this->meta = $meta;
        $this->config = $config;
    }

    public function runAction(string $action, array $ctx = []): array
    {
        switch ($action) {
            case 'query_zillow':
                return $this->queryZillow($ctx);
            default:
                return ['ok'=>false,'error'=>'Unknown action'];
        }
    }

    private function queryZillow(array $ctx): array
    {
        $assetId = (int)($ctx['asset_id'] ?? 0);
        if ($assetId <= 0) return ['ok'=>false,'error'=>'Missing asset_id'];
        $phase = $ctx['phase'] ?? 'run'; // 'describe' | 'run'
        $pdo = Database::get();

        // Load asset and category
        $st = $pdo->prepare('SELECT a.*, ac.name AS category_name FROM assets a LEFT JOIN asset_categories ac ON ac.id=a.category_id WHERE a.id=? LIMIT 1');
        $st->execute([$assetId]);
        $asset = $st->fetch(PDO::FETCH_ASSOC);
        if (!$asset) return ['ok'=>false,'error'=>'Asset not found'];

        // Check applicability by category name
        $cat = strtolower((string)($asset['category_name'] ?? ''));
        $appliesNames = array_map('strtolower', (array)($this->meta['actions'][0]['applies_to_categories'] ?? []));
        if ($appliesNames && !in_array($cat, array_map('strtolower', $appliesNames))) {
            // allow proceeding if category empty; otherwise block
            return ['ok'=>false,'error'=>'Plugin not applicable to this asset type'];
        }

        // Load primary physical address
        $adr = $pdo->prepare("SELECT line1, line2, city, state, postal_code, country FROM asset_addresses WHERE asset_id=? AND address_type='physical' LIMIT 1");
        $adr->execute([$assetId]);
        $addr = $adr->fetch(PDO::FETCH_ASSOC) ?: [];

        // Resolve mapped property definition IDs
        $maps = (array)($this->config['mappings'] ?? []);
        $defId = function(string $k) use ($maps): ?int { $v = $maps[$k] ?? null; if ($v === '' || $v === null) return null; return (int)$v; };

        // If Zillow URL property is mapped, try to fetch direct URL value
        $zurlPropId = $defId('zillow_url');
        $directUrl = null;
        if ($zurlPropId) {
            $vz = $pdo->prepare('SELECT value_text FROM asset_property_values WHERE asset_id=? AND property_def_id=?');
            $vz->execute([$assetId, $zurlPropId]);
            $directUrl = trim((string)($vz->fetchColumn() ?: ''));
        }

        // Phase: describe — if no Zillow URL known, offer an input field
        if ($phase === 'describe') {
            $inputs = [];
            if (!$directUrl) {
                $suggest = '';
                $inputs[] = [
                    'name' => 'zillow_url',
                    'label' => 'Zillow URL (optional)',
                    'type' => 'text',
                    'placeholder' => 'https://www.zillow.com/homedetails/...',
                    'value' => $suggest,
                ];
            }
            // If no inputs needed, UI can auto-run and auto-close if no output
            return ['ok'=>true, 'ui'=>[ 'title'=>'Query Zillow', 'inputs'=>$inputs, 'submitLabel'=>'Run' ], 'autoRun'=>empty($inputs)];
        }

        // Prepare house context for scraper
        $house = [
            'address_full' => trim(($addr['line1'] ?? '') . ' ' . ($addr['line2'] ?? '')),
            'address' => trim($addr['line1'] ?? ''),
            'city' => trim($addr['city'] ?? ''),
            'state' => trim($addr['state'] ?? ''),
            'zip' => trim($addr['postal_code'] ?? ''),
        ];

        // Allow ad-hoc URL from UI
        $uiUrl = trim((string)($ctx['inputs']['zillow_url'] ?? ''));
        if ($uiUrl !== '') { $directUrl = $uiUrl; }

        // Self-contained fetch + parse Zillow
        $facts = $this->scrapeZillow($house, $directUrl);
        if (!$facts) return ['ok'=>false,'error'=>'No Zillow data found'];

        // Build updates for mapped properties
        $updates = [];
        $mapPairs = [
            'zillow_url' => 'zillow_url',
            'sq_ft' => 'sq_ft',
            'beds' => 'beds',
            'baths' => 'baths',
            'year_built' => 'year_built',
            'lot_size_acres' => 'lot_size_acres',
        ];
        foreach ($mapPairs as $mapKey => $factKey) {
            $pid = $defId($mapKey);
            if (!$pid) continue;
            if (!array_key_exists($factKey, $facts)) continue;
            $val = $facts[$factKey];
            if ($val === null || $val === '') continue;
            $ins = $pdo->prepare('INSERT INTO asset_property_values(asset_id, property_def_id, value_text) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)');
            $ins->execute([$assetId, $pid, (string)$val]);
            $updates[] = ['property_def_id'=>$pid, 'key'=>$mapKey, 'value'=>(string)$val];
        }

        // Optionally add current value record from Zestimate
        $addedValue = null;
        if (!empty($this->config['value_update']) && isset($facts['zestimate_usd']) && is_numeric($facts['zestimate_usd'])) {
            $amt = (float)$facts['zestimate_usd'];
            $vs = $pdo->prepare("INSERT INTO asset_values(asset_id, value_type, amount, valuation_date, source, notes) VALUES (?,?,?,?,?,?)");
            $vs->execute([$assetId, 'current', $amt, date('Y-m-d'), 'zillow', 'Zestimate via plugin']);
            $addedValue = $amt;
        }

        $summary = $this->renderSummary($facts, $updates, $addedValue);

        return [ 'ok'=>true, 'html'=>$summary, 'facts'=>$facts, 'updates'=>$updates, 'added_value'=>$addedValue ];
    }

    // --- Helpers: in-plugin, no core scraper dependency ---
    private function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                ],
                CURLOPT_ENCODING => '',
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code >= 400) return null;
            return $body;
        }
        // Fallback without curl
        $ctx = stream_context_create(['http'=>['timeout'=>20, 'header'=>"User-Agent: Mozilla/5.0\r\n"]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res !== false ? $res : null;
    }

    private function norm(string $s): string
    {
        $s = strtolower($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/i', '', $s);
        return $s;
    }

    private function buildSearchQuery(array $house): string
    {
        $parts = [];
        foreach (['address_full','address','city','state','zip'] as $k) { if (!empty($house[$k])) $parts[] = $house[$k]; }
        return trim(implode(', ', array_unique($parts)));
    }

    private function parseZillowDetail(string $html): array
    {
        $facts = [];
        if (preg_match('/<script[^>]*id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/is', $html, $m)) {
            $json = trim($m[1]);
            $data = json_decode($json, true);
            if (is_array($data)) {
                $walker = function($node, callable $visit) use (&$walker) { if (is_array($node)) { $visit($node); foreach ($node as $v) { $walker($v, $visit); } } };
                $walker($data, function($n) use (&$facts){
                    foreach (['zestimate','homeZestimate'] as $k) if (isset($n[$k]) && is_numeric($n[$k])) $facts['zestimate_usd'] = (float)$n[$k];
                    foreach (['livingArea','finishedSqFt','floorSize'] as $k) if (isset($n[$k]) && is_numeric($n[$k])) $facts['sq_ft'] = (int)$n[$k];
                    if (isset($n['bedrooms']) && is_numeric($n['bedrooms'])) $facts['beds'] = (float)$n['bedrooms'];
                    if (isset($n['bathrooms']) && is_numeric($n['bathrooms'])) $facts['baths'] = (float)$n['bathrooms'];
                    if (isset($n['yearBuilt']) && is_numeric($n['yearBuilt'])) $facts['year_built'] = (int)$n['yearBuilt'];
                    if (isset($n['lotAreaValue']) && is_numeric($n['lotAreaValue'])) {
                        $val = (float)$n['lotAreaValue']; $unit = strtolower((string)($n['lotAreaUnits'] ?? 'acres'));
                        $facts['lot_size_acres'] = $unit === 'acres' ? $val : ($unit === 'sqft' ? $val/43560.0 : null);
                    }
                });
            }
        }
        if (preg_match('/Zestimate[^$]*\$([0-9,]+)/i', $html, $m)) { $facts['zestimate_usd'] = (float)str_replace(',', '', $m[1]); }
        if (preg_match('/\"zestimate\"\s*:\s*(\d+)/i', $html, $m)) { $facts['zestimate_usd'] = (float)$m[1]; }
        if (preg_match('/\"livingArea\"\s*:\s*(\d+)/i', $html, $m)) { $facts['sq_ft'] = (int)$m[1]; }
        elseif (preg_match('/([0-9,]+)\s*sq\s*ft/i', $html, $m)) { $facts['sq_ft'] = (int)str_replace(',', '', $m[1]); }
        if (preg_match('/\"bedrooms\"\s*:\s*(\d+(?:\.\d+)?)/i', $html, $m)) { $facts['beds'] = (float)$m[1]; }
        elseif (preg_match('/(\d+)\s*beds?/i', $html, $m)) { $facts['beds'] = (int)$m[1]; }
        if (preg_match('/\"bathrooms(?:TotalInteger)?\"\s*:\s*(\d+(?:\.\d+)?)/i', $html, $m)) { $facts['baths'] = (float)$m[1]; }
        elseif (preg_match('/(\d+(?:\.\d+)?)\s*baths?/i', $html, $m)) { $facts['baths'] = (float)$m[1]; }
        if (preg_match('/\"yearBuilt\"\s*:\s*(\d{4})/i', $html, $m)) { $facts['year_built'] = (int)$m[1]; }
        if (preg_match('/\"lotAreaValue\"\s*:\s*(\d+(?:\.\d+)?).*?\"lotAreaUnits\"\s*:\s*\"(acres|sqft)\"/is', $html, $m)) {
            $val = (float)$m[1]; $unit = strtolower($m[2]); $facts['lot_size_acres'] = $unit === 'acres' ? $val : ($val/43560.0);
        }
        return $facts;
    }

    private function scrapeZillow(array $house, ?string $directUrl = null): array
    {
        $targetUrl = $directUrl;
        if ($targetUrl) {
            $p = parse_url($targetUrl);
            $host = strtolower($p['host'] ?? '');
            $path = $p['path'] ?? '';
            if (!preg_match('/(^|\.)zillow\.com$/', $host) || stripos($path, '/homedetails/') === false) {
                $targetUrl = null;
            } else {
                $targetUrl = 'https://www.zillow.com' . $path;
            }
        }
        if (!$targetUrl) {
            $q = urlencode($this->buildSearchQuery($house));
            $searchUrl = "https://www.zillow.com/homes/$q_rb/";
            $html = $this->httpGet($searchUrl);
            if ($html) {
                $candidates = [];
                if (preg_match_all('/\"detailUrl\"\s*:\s*\"(\\\/homedetails\\\/[^"]+)\"/i', $html, $mm)) { foreach ($mm[1] as $u) { $candidates[] = stripslashes($u); } }
                if (preg_match_all('/https:\\/\\/www\\.zillow\\.com\\/homedetails\\/[A-Za-z0-9\\-_,.%]+/i', addslashes($html), $mm2)) { foreach ($mm2[0] as $u) { $candidates[] = stripcslashes($u); } }
                $candidates = array_values(array_unique($candidates));
                $wantedStreet = $this->norm($house['address'] ?? '');
                $wantedCity = $this->norm($house['city'] ?? '');
                $wantedZip = $this->norm($house['zip'] ?? '');
                $best = null; $bestScore = -1;
                foreach ($candidates as $u) {
                    $full = (strpos($u, 'http') === 0) ? $u : ('https://www.zillow.com' . $u);
                    $n = $this->norm($full);
                    $score = 0;
                    if ($wantedStreet && strpos($n, $wantedStreet) !== false) $score += 5;
                    if ($wantedCity && strpos($n, $wantedCity) !== false) $score += 3;
                    if ($wantedZip && strpos($n, $wantedZip) !== false) $score += 4;
                    if ($score > $bestScore) { $best = $full; $bestScore = $score; }
                }
                if ($best) { $targetUrl = $best; }
                elseif (preg_match('/https:\/\/www\.zillow\.com\/homedetails\/[A-Za-z0-9\-_,.%]+/i', $html, $m)) { $targetUrl = html_entity_decode($m[0]); }
            }
        }
        $facts = [];
        if ($targetUrl) {
            $detail = $this->httpGet($targetUrl);
            if ($detail) {
                $facts = array_merge($facts, $this->parseZillowDetail($detail));
                $facts['zillow_url'] = $targetUrl;
            }
        }
        return $facts;
    }

    private function renderSummary(array $facts, array $updates, $addedValue): string
    {
        $fmt = function($v){ if ($v === null || $v === '') return '—'; if (is_numeric($v)) return (string)$v; return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); };
        ob_start();
        ?>
        <div class="row">
          <div class="col-6"><label>Zestimate</label><input readonly value="<?= isset($facts['zestimate_usd']) ? ('$'.number_format((float)$facts['zestimate_usd'],2)) : '—' ?>"></div>
          <div class="col-6"><label>Square Feet</label><input readonly value="<?= $fmt($facts['sq_ft'] ?? null) ?>"></div>
          <div class="col-4"><label>Beds</label><input readonly value="<?= $fmt($facts['beds'] ?? null) ?>"></div>
          <div class="col-4"><label>Baths</label><input readonly value="<?= $fmt($facts['baths'] ?? null) ?>"></div>
          <div class="col-4"><label>Year Built</label><input readonly value="<?= $fmt($facts['year_built'] ?? null) ?>"></div>
          <div class="col-12"><label>Zillow Link</label><input readonly value="<?= $fmt($facts['zillow_url'] ?? null) ?>"></div>
        </div>
        <?php if ($addedValue !== null): ?>
        <div class="small" style="margin-top:8px">Added Current Value: $<?= number_format((float)$addedValue,2) ?> (source: Zillow)</div>
        <?php endif; ?>
        <?php return (string)ob_get_clean();
    }
}
