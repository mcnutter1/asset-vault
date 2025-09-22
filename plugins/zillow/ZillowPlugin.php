<?php

class ZillowPlugin extends BasePlugin
{

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
        $this->dbg('Start query_zillow phase=' . $phase);

        // Load asset and category
        $st = $pdo->prepare('SELECT a.*, ac.name AS category_name FROM assets a LEFT JOIN asset_categories ac ON ac.id=a.category_id WHERE a.id=? LIMIT 1');
        $st->execute([$assetId]);
        $asset = $st->fetch(PDO::FETCH_ASSOC);
        if (!$asset) return $this->fail('Asset not found');

        // Check applicability by category name
        $cat = strtolower((string)($asset['category_name'] ?? ''));
        $appliesNames = array_map('strtolower', (array)($this->meta['actions'][0]['applies_to_categories'] ?? []));
        if ($appliesNames && !in_array($cat, array_map('strtolower', $appliesNames))) {
            // allow proceeding if category empty; otherwise block
            return $this->fail('Plugin not applicable to this asset type');
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
        if (!$facts) return $this->fail('No Zillow data found');
        if (isset($facts['error']) && $facts['error']==='blocked') {
            return $this->fail('Zillow blocked automated access. Try pasting the exact property URL.');
        }

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
        $resp = [ 'ok'=>true, 'html'=>$summary, 'facts'=>$facts, 'updates'=>$updates, 'added_value'=>$addedValue ];
        if ($this->debug) { $resp['debug_html'] = $this->renderDebug(); }
        return $resp;
    }

    // --- Helpers: in-plugin, no core scraper dependency ---
    private function httpGet(string $url): ?string
    {
        $this->dbg('HTTP GET ' . $url);
        $ua = $this->getUserAgent();
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: ' . $ua,
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                    'Referer: https://www.zillow.com/',
                    'Sec-Fetch-Dest: document',
                    'Sec-Fetch-Mode: navigate',
                    'Sec-Fetch-Site: none',
                    'Sec-Fetch-User: ?1',
                    'Sec-Ch-Ua: "Chromium";v="130", "Google Chrome";v="130", "Not?A_Brand";v="99"',
                    'Sec-Ch-Ua-Mobile: ?0',
                    'Sec-Ch-Ua-Platform: "Windows"',
                    'Upgrade-Insecure-Requests: 1',
                    'Cache-Control: no-cache',
                    'Pragma: no-cache',
                ],
                CURLOPT_ENCODING => '',
            ]);
            // Optional proxy
            $proxy = (string)($this->config['proxy_url'] ?? '');
            if ($proxy !== '') {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
                $this->dbg('Using proxy');
            }
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body === false) { $this->dbg('cURL error: ' . curl_error($ch)); }
            $this->dbg('HTTP code: ' . $code . '; length=' . (is_string($body) ? strlen($body) : -1));
            curl_close($ch);
            if ($body === false) return null; // allow parsing even when code>=400
            return $body;
        }
        // Fallback without curl
        $ctx = stream_context_create(['http'=>['timeout'=>20, 'header'=>"User-Agent: Mozilla/5.0\r\n"]]);
        $res = @file_get_contents($url, false, $ctx);
        $this->dbg('HTTP fopen length=' . ($res !== false ? strlen($res) : -1));
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
            if ($data === null) { $this->dbg('JSON decode error: '.json_last_error_msg()); }
            if (is_array($data)) {
                // Prefer gdpClientCache when available (richer data)
                $get = function(array $arr, string $path) {
                    $cur = $arr; foreach (explode('.', $path) as $p) { if (!is_array($cur) || !array_key_exists($p, $cur)) return null; $cur = $cur[$p]; } return $cur; };
                $componentProps = $get($data, 'props.pageProps.componentProps') ?? [];
                if (is_array($componentProps) && isset($componentProps['gdpClientCache'])) {
                    $raw = (string)$componentProps['gdpClientCache'];
                    $gc = json_decode($raw, true);
                    if ($gc === null) { $this->dbg('gdpClientCache decode error: '.json_last_error_msg()); }
                    if (is_array($gc)) {
                        $this->dbg('gdpClientCache entries: '.count($gc));
                        foreach ($gc as $entry) {
                            if (is_array($entry) && isset($entry['property']) && is_array($entry['property'])) {
                                $p = $entry['property'];
                                if (isset($p['zestimate']) && is_numeric($p['zestimate'])) $facts['zestimate_usd'] = (float)$p['zestimate'];
                                if (isset($p['livingArea']) && is_numeric($p['livingArea'])) $facts['sq_ft'] = (int)$p['livingArea'];
                                if (isset($p['bedrooms']) && is_numeric($p['bedrooms'])) $facts['beds'] = (float)$p['bedrooms'];
                                if (isset($p['bathrooms']) && is_numeric($p['bathrooms'])) $facts['baths'] = (float)$p['bathrooms'];
                                if (isset($p['yearBuilt']) && is_numeric($p['yearBuilt'])) $facts['year_built'] = (int)$p['yearBuilt'];
                                if (isset($p['lotAreaValue']) && is_numeric($p['lotAreaValue'])) {
                                    $val = (float)$p['lotAreaValue']; $unit = strtolower((string)($p['lotAreaUnit'] ?? $p['lotAreaUnits'] ?? 'acres'));
                                    $facts['lot_size_acres'] = $unit === 'acres' ? $val : ($unit === 'sqft' ? $val/43560.0 : null);
                                }
                                // Continue scanning other entries but prefer the first full set
                            }
                        }
                    }
                }
                // Fallback: generic deep walker (covers variants and when gdpClientCache absent)
                $walker = function($node, callable $visit) use (&$walker) { if (is_array($node)) { $visit($node); foreach ($node as $v) { $walker($v, $visit); } } };
                $walker($data, function($n) use (&$facts){
                    foreach (['zestimate','homeZestimate'] as $k) if (isset($n[$k]) && is_numeric($n[$k])) $facts['zestimate_usd'] = (float)$n[$k];
                    foreach (['livingArea','livingAreaValue','finishedSqFt'] as $k) if (isset($n[$k]) && is_numeric($n[$k])) $facts['sq_ft'] = (int)$n[$k];
                    if (isset($n['floorSize'])) {
                        $fs = $n['floorSize'];
                        if (is_array($fs)) {
                            if (isset($fs['size']) && is_numeric($fs['size'])) $facts['sq_ft'] = (int)$fs['size'];
                            elseif (isset($fs['value']) && is_numeric($fs['value'])) $facts['sq_ft'] = (int)$fs['value'];
                        } elseif (is_numeric($fs)) { $facts['sq_ft'] = (int)$fs; }
                    }
                    if (isset($n['bedrooms']) && is_numeric($n['bedrooms'])) $facts['beds'] = (float)$n['bedrooms'];
                    if (isset($n['bathrooms']) && is_numeric($n['bathrooms'])) $facts['baths'] = (float)$n['bathrooms'];
                    elseif (isset($n['bathroomsFull']) || isset($n['bathroomsHalf'])) {
                        $full = isset($n['bathroomsFull']) && is_numeric($n['bathroomsFull']) ? (float)$n['bathroomsFull'] : 0.0;
                        $half = isset($n['bathroomsHalf']) && is_numeric($n['bathroomsHalf']) ? (float)$n['bathroomsHalf'] : 0.0;
                        $facts['baths'] = $full + ($half * 0.5);
                    }
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
        $this->dbg('scrapeZillow start; directUrl=' . ($directUrl ?: '')); 
        if ($targetUrl) {
            $p = parse_url($targetUrl);
            $host = strtolower($p['host'] ?? '');
            $path = $p['path'] ?? '';
            if (!preg_match('/(^|\.)zillow\.com$/', $host) || stripos($path, '/homedetails/') === false) {
                $this->dbg('Direct URL invalid or not homedetails');
                $targetUrl = null;
            } else {
                $targetUrl = 'https://www.zillow.com' . $path;
            }
        }
        if (!$targetUrl) {
            // Try API-based search first
            $term = $this->buildSearchQuery($house);
            $this->dbg('Search term: ' . $term);
            $targetUrl = $this->zillowSearchApi($term);
            if (!$targetUrl) {
                // Fallback: HTML search page scrape (may be blocked)
                $q = urlencode($term);
                $searchUrl = "https://www.zillow.com/homes/{$q}_rb/";
                $html = $this->httpGet($searchUrl);
                if ($html && (stripos($html, 'px-captcha') !== false || stripos($html, 'PerimeterX') !== false)) {
                    $this->dbg('Blocked by captcha on search');
                    return ['error' => 'blocked'];
                }
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
        }
        $facts = [];
        if ($targetUrl) {
            $detail = $this->httpGet($targetUrl);
            if ($detail && (stripos($detail, 'px-captcha') !== false || stripos($detail, 'PerimeterX') !== false)) {
                $this->dbg('Blocked by captcha on detail');
                return ['error' => 'blocked'];
            }
            if ($detail) {
                $facts = array_merge($facts, $this->parseZillowDetail($detail));
                $facts['zillow_url'] = $targetUrl;
            }
        }
        $this->dbg('Facts keys: ' . implode(',', array_keys($facts)));
        return $facts;
    }

    private function zillowSearchApi(string $term): ?string
    {
        if ($term === '') return null;
        $url = 'https://www.zillow.com/async-create-search-page-state';
        $payload = [
            'searchQueryState' => [
                'isMapVisible' => true,
                'isListVisible' => true,
                'usersSearchTerm' => $term,
                'mapBounds' => [
                    // Rough USA bounds; Zillow requires mapBounds
                    'north' => 49.38,
                    'east' => -66.94,
                    'south' => 24.52,
                    'west' => -124.77,
                ],
                'filterState' => [ 'isAllHomes' => ['value' => true] ],
                'mapZoom' => 5,
                'pagination' => [ 'currentPage' => 1 ],
            ],
            'wants' => [ 'cat1' => ['listResults','mapResults'], 'cat2' => ['total'] ],
            'requestId' => 10,
            'isDebugRequest' => false,
        ];
        $headers = [
            'Accept: application/json, text/plain, */*',
            'Content-Type: application/json',
            'Origin: https://www.zillow.com',
            'Referer: https://www.zillow.com/homes/',
            'User-Agent: ' . $this->getUserAgent(),
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Sec-Fetch-Dest: empty',
            'Sec-Fetch-Mode: cors',
            'Sec-Fetch-Site: same-origin',
            'Sec-Ch-Ua: "Chromium";v="130", "Google Chrome";v="130", "Not?A_Brand";v="99"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"',
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        // Optional proxy
        $proxy = (string)($this->config['proxy_url'] ?? '');
        if ($proxy !== '') { curl_setopt($ch, CURLOPT_PROXY, $proxy); }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->dbg('Search API HTTP code: ' . $code . '; length=' . (is_string($body) ? strlen($body) : -1));
        if ($body === false || $code >= 400) return null;
        $json = json_decode($body, true);
        if ($json === null) { $this->dbg('Search API JSON decode error: '.json_last_error_msg()); }
        if (!is_array($json)) return null;
        $sr = $json['cat1']['searchResults'] ?? ($json['searchResults'] ?? null);
        if (!is_array($sr)) return null;
        $cands = [];
        foreach (['mapResults','listResults'] as $k) {
            if (!empty($sr[$k]) && is_array($sr[$k])) {
                foreach ($sr[$k] as $r) {
                    $u = $r['detailUrl'] ?? null; if (!$u) continue;
                    $cands[] = (strpos($u, 'http') === 0) ? $u : ('https://www.zillow.com'.$u);
                }
            }
        }
        $cands = array_values(array_unique($cands));
        $this->dbg('API candidates: ' . count($cands));
        if (!$cands) return null;
        // Prefer exact address term match when possible
        $nterm = $this->norm($term); $best = null; $bestScore = -1;
        foreach ($cands as $u) { $n = $this->norm($u); $s = 0; if (strpos($n, $nterm)!==false) $s+=5; if ($s>$bestScore){$best=$u;$bestScore=$s;} }
        return $best ?: $cands[0];
    }

    private function getUserAgent(): string
    {
        $ua = trim((string)($this->config['user_agent'] ?? ''));
        if ($ua !== '') { $this->dbg('Using custom UA'); return $ua; }
        // Default desktop Chrome UA (recent)
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
    }

    private function dbg(string $line): void
    {
        if (!$this->debug) return;
        $this->debugLog[] = '['.date('H:i:s').'] '.$line;
        @error_log('ZillowPlugin: '.$line);
    }

    private function renderDebug(): string
    {
        if (!$this->debug || !$this->debugLog) return '';
        $out = '<div class="debug" style="margin-top:10px"><h3 style="margin:0 0 6px">Debug Log</h3><pre style="white-space:pre-wrap; font-size:11px; line-height:1.4; padding:8px; background:#f8fafc; border:1px solid var(--border); border-radius:6px;">';
        $out .= htmlspecialchars(implode("\n", $this->debugLog), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $out .= '</pre></div>';
        return $out;
    }

    private function fail(string $msg): array
    {
        $resp = ['ok'=>false,'error'=>$msg];
        if ($this->debug) { $resp['debug_html'] = $this->renderDebug(); }
        return $resp;
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
