<?php

require_once __DIR__ . '/../../lib/Ai.php';

class CarValuesPlugin extends BasePlugin
{
    public function runAction(string $action, array $ctx = []): array
    {
        switch ($action) {
            case 'query_vehicle_value':
                return $this->queryVehicleValue($ctx);
            default:
                return ['ok'=>false,'error'=>'Unknown action'];
        }
    }

    private function queryVehicleValue(array $ctx): array
    {
        $assetId = (int)($ctx['asset_id'] ?? 0);
        if ($assetId <= 0) return ['ok'=>false,'error'=>'Missing asset_id'];
        $phase = $ctx['phase'] ?? 'run'; // 'describe' | 'run'
        $pdo = Database::get();
        $this->dbg('Start query_vehicle_value phase=' . $phase);

        // Load asset and category
        $st = $pdo->prepare('SELECT a.*, ac.name AS category_name FROM assets a LEFT JOIN asset_categories ac ON ac.id=a.category_id WHERE a.id=? LIMIT 1');
        $st->execute([$assetId]);
        $asset = $st->fetch(PDO::FETCH_ASSOC);
        if (!$asset) return $this->fail('Asset not found');

        // Check applicability by category name from plugin meta
        $cat = strtolower((string)($asset['category_name'] ?? ''));
        $appliesNames = array_map('strtolower', (array)($this->meta['actions'][0]['applies_to_categories'] ?? []));
        if ($appliesNames && !in_array($cat, array_map('strtolower', $appliesNames))) {
            return $this->fail('Plugin not applicable to this asset type');
        }

        // Resolve mapped property definition IDs
        $maps = (array)($this->config['mappings'] ?? []);
        $defId = function(string $k) use ($maps): ?int { $v = $maps[$k] ?? null; if ($v === '' || $v === null) return null; return (int)$v; };

        // Helper to get property value for a mapping (fallback to empty)
        $getPropVal = function(int $propId) use ($pdo, $assetId): ?string {
            $q = $pdo->prepare('SELECT value_text FROM asset_property_values WHERE asset_id=? AND property_def_id=?');
            $q->execute([$assetId, $propId]);
            $v = $q->fetchColumn();
            return $v !== false ? (string)$v : null;
        };

        // Pull known values from asset core columns and mapped properties
        $year = (string)($asset['year'] ?? '');
        $make = trim((string)($asset['make'] ?? ''));
        $model = trim((string)($asset['model'] ?? ''));
        $trim = '';
        $vin = trim((string)($asset['serial_number'] ?? ''));
        $odo = $asset['odometer_miles'] ?? null;

        if ($defId('year')) { $pv = trim((string)($getPropVal($defId('year')) ?? '')); if ($pv !== '') $year = $pv; }
        if ($defId('make')) { $pv = trim((string)($getPropVal($defId('make')) ?? '')); if ($pv !== '') $make = $pv; }
        if ($defId('model')) { $pv = trim((string)($getPropVal($defId('model')) ?? '')); if ($pv !== '') $model = $pv; }
        if ($defId('trim')) { $pv = trim((string)($getPropVal($defId('trim')) ?? '')); if ($pv !== '') $trim = $pv; }
        if ($defId('vin')) { $pv = trim((string)($getPropVal($defId('vin')) ?? '')); if ($pv !== '') $vin = $pv; }

        $this->dbg('Preload vehicle core fields: year=' . $year . ' make=' . $make . ' model=' . $model . ' trim=' . ($trim!==''?$trim:'(none)') . ' vin=' . ($vin ? '[redacted]' : '')); 
        if ($odo !== null) { $this->dbg('Odometer: ' . (int)$odo . ' mi'); }

        // Phase: describe — if key inputs missing, offer fields
        if ($phase === 'describe') {
            $inputs = [];
            $needFields = [];
            if ($year === '') $needFields[] = 'year';
            if ($make === '') $needFields[] = 'make';
            if ($model === '') $needFields[] = 'model';
            if ($trim === '') $needFields[] = 'trim';
            // VIN is optional, but if missing we can still proceed; still show if empty
            if ($vin === '') $needFields[] = 'vin';

            foreach ($needFields as $f) {
                $label = ($f === 'trim') ? 'Trim Level' : (strtoupper(substr($f,0,1)) . substr($f,1));
                $type = ($f === 'year') ? 'number' : 'text';
                $placeholder = '';
                if ($f === 'vin') $placeholder = '17-char VIN (optional)';
                if ($f === 'trim') $placeholder = 'e.g., EX, Limited, Sport';
                $val = '';
                if ($f === 'year') $val = $year;
                elseif ($f === 'make') $val = $make;
                elseif ($f === 'model') $val = $model;
                elseif ($f === 'vin') $val = $vin;
                elseif ($f === 'trim') $val = $trim;
                $inputs[] = [ 'name'=>$f, 'label'=>$label, 'type'=>$type, 'placeholder'=>$placeholder, 'value'=>$val ];
            }
            return ['ok'=>true, 'ui'=>[ 'title'=>'Estimate Car Value', 'inputs'=>$inputs, 'submitLabel'=>'Run' ], 'autoRun'=>empty($inputs)];
        }

        // Allow overrides from UI
        $uiYear = trim((string)($ctx['inputs']['year'] ?? ''));
        $uiMake = trim((string)($ctx['inputs']['make'] ?? ''));
        $uiModel = trim((string)($ctx['inputs']['model'] ?? ''));
        $uiVin = trim((string)($ctx['inputs']['vin'] ?? ''));
        $uiTrim = trim((string)($ctx['inputs']['trim'] ?? ''));
        if ($uiYear !== '') $year = $uiYear;
        if ($uiMake !== '') $make = $uiMake;
        if ($uiModel !== '') $model = $uiModel;
        if ($uiVin !== '') $vin = $uiVin;
        if ($uiTrim !== '') $trim = $uiTrim;

        // Basic validation (need at least Y/M/Model)
        if ($year === '' || $make === '' || $model === '') {
            return $this->fail('Year, Make, and Model are required');
        }

        // Build vehicle info for AI estimator
        $vehicle = [
            'year' => (int)$year,
            'make' => $make,
            'model' => $model,
            'vin' => $vin,
        ];
        if ($odo !== null && is_numeric($odo)) { $vehicle['odometer_miles'] = (int)$odo; }
        if ($trim !== '') { $vehicle['trim_level'] = $trim; }
        $this->dbg('Vehicle payload prepared: year=' . $vehicle['year'] . ' make=' . $vehicle['make'] . ' model=' . $vehicle['model'] . ' trim=' . ($trim!==''?$trim:'(none)') . ' vin=' . ($vin ? '[redacted]' : ''));

        // Call AI estimator using OpenAI key from settings
        try {
            $aiModel = trim((string)($this->config['ai_model'] ?? '')) ?: Settings::get('openai_model', 'gpt-4.1');
            $apiKey = Settings::get('openai_api_key', Util::config()['openai']['api_key'] ?? null);
            $ai = new AiClient($apiKey, $aiModel);
            $this->dbg('AI call model=' . $aiModel . ' (key from settings)');
            $res = ValueEstimators::valueVehicle($ai, $vehicle);
        } catch (Throwable $e) {
            $this->dbg('AI error: ' . $e->getMessage());
            return $this->fail('Valuation service unavailable: ' . $e->getMessage());
        }

        $valuation = (array)($res['valuation'] ?? []);
        $mv = isset($valuation['market_value_usd']) && is_numeric($valuation['market_value_usd']) ? (float)$valuation['market_value_usd'] : null;
        $rc = isset($valuation['replacement_cost_usd']) && is_numeric($valuation['replacement_cost_usd']) ? (float)$valuation['replacement_cost_usd'] : null;
        $assumptions = trim((string)($valuation['assumptions'] ?? ''));
        $confidence = trim((string)($valuation['confidence'] ?? ''));
        $sources = (array)($valuation['sources'] ?? []);
        $this->dbg('AI result: market=' . ($mv!==null?$mv:'null') . ' replacement=' . ($rc!==null?$rc:'null') . ' confidence=' . $confidence);

        // Build updates for mapped properties (year/make/model/vin only)
        $updates = [];
        $mapPairs = [
            'year' => (string)$year,
            'make' => (string)$make,
            'model' => (string)$model,
            'vin' => (string)$vin,
            'trim' => (string)$trim,
        ];

        $this->dbg('Preparing property updates; mapped keys: ' . implode(',', array_keys($maps)));
        foreach ($mapPairs as $mapKey => $val) {
            $sel = (string)($maps[$mapKey] ?? '');
            if ($sel === '' || $val === '' || $val === null) continue;
            if (strpos($sel, 'core:') === 0) {
                $core = substr($sel, 5);
                $this->dbg('Core mapping selected for '.$mapKey.' => '.$core);
                $col = null;
                switch ($core) {
                    case 'year': $col = 'year'; break;
                    case 'make': $col = 'make'; break;
                    case 'model': $col = 'model'; break;
                    case 'vin':
                    case 'serial_number': $col = 'serial_number'; break;
                    case 'odometer_miles': $col = 'odometer_miles'; break;
                    case 'hours_used': $col = 'hours_used'; break;
                }
                if ($col) {
                    $sql = 'UPDATE assets SET '.$col.'=? WHERE id=? LIMIT 1';
                    $stUp = $pdo->prepare($sql);
                    $upVal = in_array($col, ['year','odometer_miles','hours_used'], true) ? (int)$val : (string)$val;
                    $stUp->execute([$upVal, $assetId]);
                    $updates[] = ['core_field'=>$col, 'key'=>$mapKey, 'value'=>(string)$val];
                }
                continue;
            }
            if (ctype_digit($sel)) {
                $pid = (int)$sel;
                if ($pid > 0) {
                    // If the selected property def is core/protected, update asset column instead
                    $pd = $pdo->prepare('SELECT name_key, is_core FROM asset_property_defs WHERE id=?');
                    $pd->execute([$pid]);
                    $prow = $pd->fetch(PDO::FETCH_ASSOC) ?: [];
                    if (!empty($prow) && (int)($prow['is_core'] ?? 0) === 1) {
                        $core = (string)($prow['name_key'] ?? '');
                        $this->dbg('Numeric mapping points to core def '.$core.'; updating asset core field');
                        $col = null;
                        switch ($core) {
                            case 'year': $col = 'year'; break;
                            case 'make': $col = 'make'; break;
                            case 'model': $col = 'model'; break;
                            case 'serial_number': $col = 'serial_number'; break;
                            case 'odometer_miles': $col = 'odometer_miles'; break;
                            case 'hours_used': $col = 'hours_used'; break;
                        }
                        if ($col) {
                            $sql = 'UPDATE assets SET '.$col.'=? WHERE id=? LIMIT 1';
                            $stUp = $pdo->prepare($sql);
                            $upVal = in_array($col, ['year','odometer_miles','hours_used'], true) ? (int)$val : (string)$val;
                            $stUp->execute([$upVal, $assetId]);
                            $updates[] = ['core_field'=>$col, 'key'=>$mapKey, 'value'=>(string)$val];
                            continue;
                        }
                    }
                    // Non-core def: write to dynamic property values
                    $ins = $pdo->prepare('INSERT INTO asset_property_values(asset_id, property_def_id, value_text) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text)');
                    $ins->execute([$assetId, $pid, (string)$val]);
                    $updates[] = ['property_def_id'=>$pid, 'key'=>$mapKey, 'value'=>(string)$val];
                }
            }
        }

        // Automatically add asset_values for market (current) and replacement
        $addedValue = null;
        if ($mv !== null) {
            $this->dbg('Adding asset_values current=' . $mv);
            $vs = $pdo->prepare("INSERT INTO asset_values(asset_id, value_type, amount, valuation_date, source, notes) VALUES (?,?,?,?,?,?)");
            $vs->execute([$assetId, 'current', $mv, date('Y-m-d'), 'carvalues', 'AI vehicle estimate']);
            $addedValue = $mv;
        }
        if ($rc !== null) {
            $this->dbg('Adding asset_values replace=' . $rc);
            $vs = $pdo->prepare("INSERT INTO asset_values(asset_id, value_type, amount, valuation_date, source, notes) VALUES (?,?,?,?,?,?)");
            $vs->execute([$assetId, 'replace', $rc, date('Y-m-d'), 'carvalues', 'AI replacement estimate']);
        }

        // Render summary
        $summary = $this->renderSummary($vehicle, $mv, $rc, $assumptions, $confidence, $sources, $updates, $addedValue);
        $resp = [ 'ok'=>true, 'html'=>$summary, 'valuation'=>$valuation, 'updates'=>$updates, 'added_value'=>$addedValue ];
        if ($this->debug) { $resp['debug_html'] = $this->renderDebug(); }
        return $resp;
    }

    private function renderSummary(array $vehicle, $mv, $rc, string $assumptions, string $confidence, array $sources, array $updates, $addedValue): string
    {
        $fmt = function($v){ if ($v === null || $v === '') return '—'; if (is_numeric($v)) return (string)$v; return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); };
        ob_start();
        ?>
        <div class="row">
          <div class="col-12"><label>Vehicle</label><input readonly value="<?= (int)$vehicle['year'] ?> <?= htmlspecialchars($vehicle['make'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($vehicle['model'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
          <div class="col-6"><label>Market Value</label><input readonly value="<?= $mv!==null ? ('$'.number_format((float)$mv,2)) : '—' ?>"></div>
          <div class="col-6"><label>Replacement Cost</label><input readonly value="<?= $rc!==null ? ('$'.number_format((float)$rc,2)) : '—' ?>"></div>
          <div class="col-6"><label>Confidence</label><input readonly value="<?= $fmt($confidence) ?>"></div>
          <div class="col-12"><label>Assumptions</label><input readonly value="<?= $fmt($assumptions) ?>"></div>
          <?php if ($sources): ?>
          <div class="col-12"><label>Sources</label><input readonly value="<?= htmlspecialchars(implode(', ', array_map('strval', $sources)), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8') ?>"></div>
          <?php endif; ?>
        </div>
        <?php if ($addedValue !== null): ?>
        <div class="small" style="margin-top:8px">Added Current Value: $<?= number_format((float)$addedValue,2) ?> (source: AI vehicle estimate)</div>
        <?php endif; ?>
        <?php return (string)ob_get_clean();
    }
}
