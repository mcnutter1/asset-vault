<?php
// JSON API for Asset Vault
// Endpoints:
//   GET  /api.php?entity=assets|people|policies[&id=123]  -> list or detail
//   POST /api.php  with JSON { entity, updates: [ {id, fields:{...}}, ... ] }
// Auth (API Key based):
//   Provide API key via one of:
//     - Authorization: Bearer <api_key>
//     - X-API-Key: <api_key>
//     - ?api_key=<api_key>
//   API keys are validated using helpers in auth.php.

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Util.php';
require_once __DIR__ . '/auth.php'; // reuse HMAC verification helpers + config

header('Content-Type: application/json');

function api_out($data, int $code = 200){ http_response_code($code); echo json_encode($data); exit; }

// Extract API key from headers/query
// Use extract_api_key_c() from auth.php
function api_get_api_key(): ?string { return extract_api_key_c(); }

// Validate API key using helpers from auth.php and enforce roles
function api_authenticate(): array {
    $apiKey = api_get_api_key();
    if (!$apiKey) api_out(['ok'=>false,'error'=>'Missing API key'], 401);
    $payload = validate_api_key_c($apiKey);
    if (!$payload) api_out(['ok'=>false,'error'=>'Invalid API key'], 401);
    $roles = $payload['roles'] ?? [];
    if (!array_intersect($roles, ['vault','admin'])) api_out(['ok'=>false,'error'=>'Not authorized'], 403);
    return $payload;
}

function parse_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function parse_includes(): array {
    $inc = strtolower(trim((string)($_GET['include'] ?? $_GET['expand'] ?? '')));
    if ($inc === '') return [];
    $parts = array_values(array_filter(array_map('trim', explode(',', $inc))));
    $set = [];
    foreach ($parts as $p) { $set[$p] = true; }
    return $set;
}

// Lightweight helpers for schema checks (tolerate limited DB grants)
function table_exists(PDO $pdo, string $table): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        // Fallback: try a harmless query
        try { $pdo->query("SELECT 1 FROM `".$table."` LIMIT 0"); return true; } catch (Throwable $e2) { return false; }
    }
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
        $st->execute([$table, $column]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        // Fallback: attempt selecting column (will fail if missing)
        try { $pdo->query("SELECT `".$column."` FROM `".$table."` LIMIT 0"); return true; } catch (Throwable $e2) { return false; }
    }
}

// Link builders
function api_entity_url(string $entity, int $id): string {
    return Util::baseUrl('api.php?entity='.rawurlencode($entity).'&id='.$id);
}

function html_entity_url(string $entity, int $id): string {
    switch ($entity) {
        case 'assets': return Util::baseUrl('index.php?page=asset_view&id='.$id);
        case 'people': return Util::baseUrl('index.php?page=person_view&id='.$id);
        case 'policies': return Util::baseUrl('index.php?page=policy_edit&id='.$id);
        default: return Util::baseUrl('index.php');
    }
}

function attach_links(array &$row, string $entity): void {
    if (!isset($row['id'])) return;
    $id = (int)$row['id'];
    $row['links'] = [
        'api'  => api_entity_url($entity, $id),
        'html' => html_entity_url($entity, $id),
    ];
}

// Sanitize whitelist of columns for each entity
function allowed_columns_for(string $entity): array {
    switch ($entity) {
        case 'assets':
            return [
                'parent_id','name','category_id','description','location','make','model','serial_number','year',
                'odometer_miles','hours_used','purchase_date','notes','location_id','asset_location_id','public_token'
            ];
        case 'people':
            return ['first_name','last_name','dob','notes','gender'];
        case 'policies':
            return ['policy_group_id','version_number','policy_number','insurer','policy_type','start_date','end_date','premium','status','notes'];
        default:
            return [];
    }
}

function table_for(string $entity): ?string {
    switch ($entity) {
        case 'assets': return 'assets';
        case 'people': return 'people';
        case 'policies': return 'policies';
        default: return null;
    }
}

// List endpoints
function list_entities(PDO $pdo, string $entity, array $include = []): array {
    switch ($entity) {
        case 'assets': {
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $params = [];
            $where = ['a.is_deleted=0'];
            if (!empty($_GET['q'])) { $where[] = '(a.name LIKE ? OR a.description LIKE ? OR a.make LIKE ? OR a.model LIKE ?)'; $q = '%'.$_GET['q'].'%'; $params[]=$q; $params[]=$q; $params[]=$q; $params[]=$q; }
            if (!empty($_GET['category_id'])) { $where[] = 'a.category_id = ?'; $params[] = (int)$_GET['category_id']; }
            if (!empty($_GET['ids'])) {
                $ids = array_values(array_filter(array_map('intval', explode(',', (string)$_GET['ids']))));
                if ($ids) { $where[] = 'a.id IN ('.implode(',', array_fill(0, count($ids), '?')).')'; $params = array_merge($params, $ids); }
            }
            $sql = 'SELECT a.*, ac.name AS category_name FROM assets a LEFT JOIN asset_categories ac ON ac.id=a.category_id';
            if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
            $sql .= ' ORDER BY a.id DESC LIMIT '.$limit.' OFFSET '.$offset;
            $st = $pdo->prepare($sql); $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            // Default includes for assets list when none provided (allow include=none to disable)
            $inc = isset($include['none']) ? [] : ($include ?: ['links'=>true,'owners'=>true,'policies'=>true,'values'=>true]);
            foreach ($rows as &$r) {
                if (!empty($inc['links'])) attach_links($r, 'assets');
                enrich_asset_min($pdo, $r, $inc);
            }
            unset($r);
            return ['ok'=>true,'data'=>$rows,'count'=>count($rows)];
        }
        case 'people': {
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $params = [];
            $where = [];
            if (!empty($_GET['q'])) { $where[] = '(first_name LIKE ? OR last_name LIKE ? OR notes LIKE ?)'; $q='%'.$_GET['q'].'%'; $params[]=$q; $params[]=$q; $params[]=$q; }
            if (!empty($_GET['ids'])) {
                $ids = array_values(array_filter(array_map('intval', explode(',', (string)$_GET['ids']))));
                if ($ids) { $where[] = 'id IN ('.implode(',', array_fill(0, count($ids), '?')).')'; $params = array_merge($params, $ids); }
            }
            $sql = 'SELECT * FROM people';
            if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
            $sql .= ' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset;
            $st = $pdo->prepare($sql); $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($include) {
                foreach ($rows as &$r) {
                    if (!empty($include['links'])) attach_links($r, 'people');
                    enrich_person_min($pdo, $r, $include);
                }
                unset($r);
            }
            return ['ok'=>true,'data'=>$rows,'count'=>count($rows)];
        }
        case 'policies': {
            $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
            $offset = max(0, (int)($_GET['offset'] ?? 0));
            $params = [];
            $where = [];
            if (!empty($_GET['status'])) { $where[] = 'status = ?'; $params[] = (string)$_GET['status']; }
            if (!empty($_GET['policy_group_id'])) { $where[] = 'policy_group_id = ?'; $params[] = (int)$_GET['policy_group_id']; }
            if (!empty($_GET['q'])) { $where[] = '(policy_number LIKE ? OR insurer LIKE ? OR notes LIKE ?)'; $q='%'.$_GET['q'].'%'; $params[]=$q; $params[]=$q; $params[]=$q; }
            if (!empty($_GET['ids'])) {
                $ids = array_values(array_filter(array_map('intval', explode(',', (string)$_GET['ids']))));
                if ($ids) { $where[] = 'id IN ('.implode(',', array_fill(0, count($ids), '?')).')'; $params = array_merge($params, $ids); }
            }
            $sql = 'SELECT * FROM policies';
            if ($where) $sql .= ' WHERE '.implode(' AND ', $where);
            $sql .= ' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset;
            $st = $pdo->prepare($sql); $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($include) {
                foreach ($rows as &$r) {
                    if (!empty($include['links'])) attach_links($r, 'policies');
                    enrich_policy_min($pdo, $r, $include);
                }
                unset($r);
            }
            return ['ok'=>true,'data'=>$rows,'count'=>count($rows)];
        }
        default:
            return ['ok'=>false,'error'=>'Unknown entity'];
    }
}

// Detail endpoints
function get_entity(PDO $pdo, string $entity, int $id, array $include = []): array {
    switch ($entity) {
        case 'assets': {
            $st = $pdo->prepare('SELECT a.*, ac.name AS category_name FROM assets a LEFT JOIN asset_categories ac ON ac.id=a.category_id WHERE a.id=? AND a.is_deleted=0');
            $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['ok'=>false,'error'=>'Not found', 'code'=>404];
            if (!empty($include['links'])) attach_links($row, 'assets');
            enrich_asset_full($pdo, $row, $include);
            return ['ok'=>true,'data'=>$row];
        }
        case 'people': {
            $st = $pdo->prepare('SELECT * FROM people WHERE id=?');
            $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['ok'=>false,'error'=>'Not found', 'code'=>404];
            if (!empty($include['links'])) attach_links($row, 'people');
            enrich_person_full($pdo, $row, $include);
            return ['ok'=>true,'data'=>$row];
        }
        case 'policies': {
            $st = $pdo->prepare('SELECT * FROM policies WHERE id=?');
            $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['ok'=>false,'error'=>'Not found', 'code'=>404];
            if (!empty($include['links'])) attach_links($row, 'policies');
            enrich_policy_full($pdo, $row, $include);
            return ['ok'=>true,'data'=>$row];
        }
        default:
            return ['ok'=>false,'error'=>'Unknown entity'];
    }
}

// Minimal enrichers for list views when include flags passed
function enrich_asset_min(PDO $pdo, array &$asset, array $include): void {
    if (!empty($include['owners'])) {
        if (table_exists($pdo, 'person_assets')) {
            $st = $pdo->prepare("SELECT p.id, p.first_name, p.last_name, pa.role FROM person_assets pa JOIN people p ON p.id=pa.person_id WHERE pa.asset_id=? ORDER BY p.last_name, p.first_name");
            $st->execute([$asset['id']]); $asset['owners'] = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($asset['owners'])) {
                foreach ($asset['owners'] as &$o) {
                    $o['display_name'] = trim(($o['first_name'] ?? '').' '.($o['last_name'] ?? ''));
                    if (!empty($include['links'])) {
                        $o['api_url'] = api_entity_url('people', (int)$o['id']);
                        $o['html_url'] = html_entity_url('people', (int)$o['id']);
                    }
                }
                unset($o);
            }
        } else { $asset['owners'] = []; }
    }
    if (!empty($include['policies'])) {
        $st = $pdo->prepare("SELECT p.id, p.policy_number, p.insurer, pa.applies_to_children, pa.coverage_definition_id, cd.code AS coverage_code, cd.name AS coverage_name FROM policy_assets pa JOIN policies p ON p.id=pa.policy_id LEFT JOIN coverage_definitions cd ON cd.id=pa.coverage_definition_id WHERE pa.asset_id=? ORDER BY p.policy_number");
        $st->execute([$asset['id']]); $asset['policies'] = $st->fetchAll(PDO::FETCH_ASSOC);
        // Merge inherited policies from ancestors into policies array
        $anc = [];
        $cur = $asset['parent_id'] ?? null;
        while ($cur) {
            $st2 = $pdo->prepare('SELECT id, parent_id FROM assets WHERE id=?'); $st2->execute([$cur]); $row = $st2->fetch(PDO::FETCH_ASSOC);
            if (!$row) break; $anc[] = (int)$row['id']; $cur = $row['parent_id'];
        }
        if ($anc) {
            $in = implode(',', array_fill(0, count($anc), '?'));
            $paHasChildCov = column_exists($pdo, 'policy_assets', 'children_coverage_definition_id');
            $covExpr = $paHasChildCov ? 'COALESCE(pa.children_coverage_definition_id, pa.coverage_definition_id)' : 'pa.coverage_definition_id';
            $sql = "SELECT pa.asset_id AS source_asset_id, p.id, p.policy_number, p.insurer, pa.applies_to_children, $covExpr AS coverage_definition_id, cd.code AS coverage_code, cd.name AS coverage_name
                    FROM policy_assets pa
                    JOIN policies p ON p.id=pa.policy_id
                    LEFT JOIN coverage_definitions cd ON cd.id=$covExpr
                    WHERE pa.asset_id IN ($in) AND pa.applies_to_children=1";
            $st3 = $pdo->prepare($sql); $st3->execute($anc); $inherited = $st3->fetchAll(PDO::FETCH_ASSOC);
            if ($inherited) {
                // Build dedup set of direct (policy_id + coverage_def)
                $seen = [];
                foreach ($asset['policies'] as $dp) {
                    $key = ((int)$dp['id']).':'.((int)($dp['coverage_definition_id'] ?? 0));
                    $seen[$key] = true;
                }
                foreach ($inherited as $ip) {
                    $key = ((int)$ip['id']).':'.((int)($ip['coverage_definition_id'] ?? 0));
                    if (isset($seen[$key])) continue; // skip duplicate
                    $ip['is_inherited'] = 1;
                    $asset['policies'][] = $ip;
                }
            }
        }
        if (!empty($include['links'])) {
            $settingsUrl = Util::baseUrl('index.php?page=settings&tab=coverages');
            foreach ($asset['policies'] as &$p) {
                $p['api_url'] = api_entity_url('policies', (int)$p['id']);
                $p['html_url'] = html_entity_url('policies', (int)$p['id']);
                $p['coverage_settings_url'] = $settingsUrl;
            }
            unset($p);
        }
    }
    if (!empty($include['values'])) {
        $st = $pdo->prepare("SELECT value_type, amount, valuation_date, source FROM asset_values WHERE asset_id=? ORDER BY valuation_date");
        $st->execute([$asset['id']]); $asset['values'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

function enrich_person_min(PDO $pdo, array &$person, array $include): void {
    if (!empty($include['assets'])) {
        if (table_exists($pdo, 'person_assets')) {
            $st = $pdo->prepare("SELECT a.id, a.name, pa.role FROM person_assets pa JOIN assets a ON a.id=pa.asset_id WHERE pa.person_id=? AND a.is_deleted=0 ORDER BY a.name");
            $st->execute([$person['id']]); $person['assets'] = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($include['links'])) {
                foreach ($person['assets'] as &$a) { $a['api_url'] = api_entity_url('assets', (int)$a['id']); $a['html_url'] = html_entity_url('assets', (int)$a['id']); }
                unset($a);
            }
        } else { $person['assets'] = []; }
    }
    if (!empty($include['policies'])) {
        $st = $pdo->prepare("SELECT p.id, p.policy_number, p.insurer, pp.role, pp.coverage_definition_id, cd.name AS coverage_name FROM policy_people pp JOIN policies p ON p.id=pp.policy_id LEFT JOIN coverage_definitions cd ON cd.id=pp.coverage_definition_id WHERE pp.person_id=? ORDER BY p.policy_number");
        $st->execute([$person['id']]); $person['policies'] = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($include['links'])) {
            foreach ($person['policies'] as &$p) { $p['api_url'] = api_entity_url('policies', (int)$p['id']); $p['html_url'] = html_entity_url('policies', (int)$p['id']); }
            unset($p);
        }
    }
}

function enrich_policy_min(PDO $pdo, array &$policy, array $include): void {
    if (!empty($include['coverages'])) {
        $hasAcv = column_exists($pdo, 'policy_coverages', 'is_acv');
        $cols = 'pc.coverage_definition_id, cd.code, cd.name, pc.limit_amount, pc.deductible_amount'.($hasAcv? ', pc.is_acv':'');
        $st = $pdo->prepare("SELECT $cols FROM policy_coverages pc JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id WHERE pc.policy_id=? ORDER BY cd.name");
        $st->execute([$policy['id']]); $policy['coverages'] = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($include['links'])) {
            $settingsUrl = Util::baseUrl('index.php?page=settings&tab=coverages');
            foreach ($policy['coverages'] as &$c) { $c['settings_url'] = $settingsUrl; }
            unset($c);
        }
    }
    if (!empty($include['assets'])) {
        $st = $pdo->prepare("SELECT pa.asset_id, a.name, pa.applies_to_children, pa.coverage_definition_id, cd.name AS coverage_name FROM policy_assets pa JOIN assets a ON a.id=pa.asset_id LEFT JOIN coverage_definitions cd ON cd.id=pa.coverage_definition_id WHERE pa.policy_id=? AND a.is_deleted=0 ORDER BY a.name");
        $st->execute([$policy['id']]); $policy['assets'] = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($include['links'])) {
            foreach ($policy['assets'] as &$a) { $a['api_url'] = api_entity_url('assets', (int)$a['asset_id']); $a['html_url'] = html_entity_url('assets', (int)$a['asset_id']); }
            unset($a);
        }
    }
    if (!empty($include['people'])) {
        $st = $pdo->prepare("SELECT pp.person_id, p.first_name, p.last_name, pp.role, pp.coverage_definition_id, cd.name AS coverage_name FROM policy_people pp JOIN people p ON p.id=pp.person_id LEFT JOIN coverage_definitions cd ON cd.id=pp.coverage_definition_id WHERE pp.policy_id=? ORDER BY p.last_name, p.first_name");
        $st->execute([$policy['id']]); $policy['people'] = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($include['links'])) {
            foreach ($policy['people'] as &$p) { $p['api_url'] = api_entity_url('people', (int)$p['person_id']); $p['html_url'] = html_entity_url('people', (int)$p['person_id']); }
            unset($p);
        }
    }
}

// Full enrichers for detail endpoints
function enrich_asset_full(PDO $pdo, array &$asset, array $include = []): void {
    // Values timeline
    $st = $pdo->prepare("SELECT value_type, amount, valuation_date, source FROM asset_values WHERE asset_id=? ORDER BY valuation_date");
    $st->execute([$asset['id']]); $asset['values'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // Addresses
    $st = $pdo->prepare("SELECT id, address_type, line1, line2, city, state, postal_code, country, latitude, longitude, updated_at FROM asset_addresses WHERE asset_id=? ORDER BY updated_at DESC");
    $st->execute([$asset['id']]); $asset['addresses'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // Property values with definitions (if dynamic tables present)
    $cid = $asset['category_id'] ?? null; $cid = $cid ? (int)$cid : null;
    if (table_exists($pdo, 'asset_property_defs')) {
        $hasCore = column_exists($pdo, 'asset_property_defs', 'is_core');
        $coreSel = $hasCore ? 'd.is_core' : '0 AS is_core';
        $sql = 'SELECT d.id AS property_def_id, d.name_key, d.display_name, d.input_type, d.show_on_view, '.$coreSel.', v.value_text
                FROM asset_property_defs d
                LEFT JOIN asset_property_values v ON v.property_def_id=d.id AND v.asset_id=?
                WHERE d.is_active=1';
        $params = [$asset['id']];
        if ($cid) { $sql .= ' AND (d.category_id IS NULL OR d.category_id=?)'; $params[] = $cid; }
        $sql .= ' ORDER BY d.sort_order, d.display_name';
        $st = $pdo->prepare($sql); $st->execute($params); $asset['properties'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $asset['properties'] = [];
    }

    // Files metadata (no content)
    $st = $pdo->prepare("SELECT id, filename, mime_type, size, caption, uploaded_at FROM files WHERE entity_type='asset' AND entity_id=? ORDER BY uploaded_at DESC");
    $st->execute([$asset['id']]); $asset['files'] = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($include['links']) && $asset['files']) {
        foreach ($asset['files'] as &$f) { $f['url'] = Util::baseUrl('file.php?id='.(int)$f['id']); }
        unset($f);
    }

    // Locations
    $st = $pdo->prepare("SELECT id, parent_id, name, description FROM asset_locations WHERE asset_id=? ORDER BY name");
    $st->execute([$asset['id']]); $asset['locations'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // Children assets (minimal)
    $st = $pdo->prepare("SELECT id, name FROM assets WHERE parent_id=? AND is_deleted=0 ORDER BY name");
    $st->execute([$asset['id']]); $asset['children'] = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($include['links']) && $asset['children']) {
        foreach ($asset['children'] as &$c) { $c['api_url'] = api_entity_url('assets', (int)$c['id']); $c['html_url'] = html_entity_url('assets', (int)$c['id']); }
        unset($c);
    }

    // Direct policies with coverage mapping (tolerate optional columns)
    $pcHasAcv = column_exists($pdo, 'policy_coverages', 'is_acv');
    $paHasChildCov = column_exists($pdo, 'policy_assets', 'children_coverage_definition_id');
    $pcCols = 'pc.limit_amount, pc.deductible_amount'.($pcHasAcv? ', pc.is_acv' : '');
    $childSel = $paHasChildCov ? 'pa.children_coverage_definition_id,' : '';
    $st = $pdo->prepare("SELECT p.id, p.policy_number, p.insurer, p.status, p.policy_type,
                                pa.applies_to_children, pa.coverage_definition_id, $childSel
                                cd.code AS coverage_code, cd.name AS coverage_name, $pcCols
                         FROM policy_assets pa
                         JOIN policies p ON p.id=pa.policy_id
                         LEFT JOIN coverage_definitions cd ON cd.id=pa.coverage_definition_id
                         LEFT JOIN policy_coverages pc ON pc.policy_id=p.id AND pc.coverage_definition_id=pa.coverage_definition_id
                         WHERE pa.asset_id=? ORDER BY p.policy_number ASC");
    $st->execute([$asset['id']]); $asset['policies'] = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($include['links']) && $asset['policies']) {
        foreach ($asset['policies'] as &$p) { $p['api_url'] = api_entity_url('policies', (int)$p['id']); $p['html_url'] = html_entity_url('policies', (int)$p['id']); }
        unset($p);
    }

    // Inherited policies from ancestors
    $ancestors = [];
    $cur = $asset['parent_id'] ?? null;
    while ($cur) {
        $st2 = $pdo->prepare('SELECT id, parent_id FROM assets WHERE id=?'); $st2->execute([$cur]); $row = $st2->fetch(PDO::FETCH_ASSOC);
        if (!$row) break; $ancestors[] = (int)$row['id']; $cur = $row['parent_id'];
    }
    if ($ancestors) {
        $in = implode(',', array_fill(0, count($ancestors), '?'));
        $covExpr = $paHasChildCov ? 'COALESCE(pa.children_coverage_definition_id, pa.coverage_definition_id)' : 'pa.coverage_definition_id';
        $sql = "SELECT pa.asset_id AS source_asset_id, p.id, p.policy_number, p.insurer, p.status, p.policy_type,
                       pa.applies_to_children,
                       $covExpr AS coverage_definition_id,
                       cd.code AS coverage_code, cd.name AS coverage_name,
                       $pcCols
                FROM policy_assets pa
                JOIN policies p ON p.id=pa.policy_id
                LEFT JOIN coverage_definitions cd ON cd.id=$covExpr
                LEFT JOIN policy_coverages pc ON pc.policy_id=p.id AND pc.coverage_definition_id=$covExpr
                WHERE pa.asset_id IN ($in) AND pa.applies_to_children=1
                ORDER BY p.end_date DESC";
        $st = $pdo->prepare($sql); $st->execute($ancestors); $asset['policies_inherited'] = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($include['links']) && $asset['policies_inherited']) {
            foreach ($asset['policies_inherited'] as &$ip) { $ip['api_url'] = api_entity_url('policies', (int)$ip['id']); $ip['html_url'] = html_entity_url('policies', (int)$ip['id']); }
            unset($ip);
        }
    } else {
        $asset['policies_inherited'] = [];
    }

    // Merge inherited policies into primary policies array with is_inherited flag and de-dup
    if (!isset($asset['policies']) || !is_array($asset['policies'])) { $asset['policies'] = []; }
    if (!empty($asset['policies_inherited'])) {
        $seen = [];
        foreach ($asset['policies'] as $dp) {
            $key = ((int)$dp['id']).':'.((int)($dp['coverage_definition_id'] ?? 0));
            $seen[$key] = true;
        }
        foreach ($asset['policies_inherited'] as $ip) {
            $key = ((int)$ip['id']).':'.((int)($ip['coverage_definition_id'] ?? 0));
            if (isset($seen[$key])) continue;
            $ip['is_inherited'] = 1;
            $asset['policies'][] = $ip;
        }
    }

    // Owners / users linked to asset
    if (table_exists($pdo, 'person_assets')) {
        $st = $pdo->prepare("SELECT p.id, p.first_name, p.last_name, pa.role FROM person_assets pa JOIN people p ON p.id=pa.person_id WHERE pa.asset_id=? ORDER BY p.last_name, p.first_name");
        $st->execute([$asset['id']]); $asset['owners'] = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($asset['owners'])) {
            foreach ($asset['owners'] as &$o) {
                $o['display_name'] = trim(($o['first_name'] ?? '').' '.($o['last_name'] ?? ''));
                if (!empty($include['links'])) {
                    $o['api_url'] = api_entity_url('people', (int)$o['id']);
                    $o['html_url'] = html_entity_url('people', (int)$o['id']);
                }
            }
            unset($o);
        }
    } else { $asset['owners'] = []; }
}

function enrich_person_full(PDO $pdo, array &$person, array $include = []): void {
    // Assets linked
    if (table_exists($pdo, 'person_assets')) {
        $st = $pdo->prepare("SELECT a.id, a.name, pa.role FROM person_assets pa JOIN assets a ON a.id=pa.asset_id WHERE pa.person_id=? AND a.is_deleted=0 ORDER BY a.name");
        $st->execute([$person['id']]); $person['assets'] = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($include['links']) && $person['assets']) {
            foreach ($person['assets'] as &$a) { $a['api_url'] = api_entity_url('assets', (int)$a['id']); $a['html_url'] = html_entity_url('assets', (int)$a['id']); }
            unset($a);
        }
    } else { $person['assets'] = []; }
    // Policies linked
    $st = $pdo->prepare("SELECT p.id, p.policy_number, p.insurer, p.status, p.policy_type, pp.role, pp.coverage_definition_id, cd.name AS coverage_name
                         FROM policy_people pp JOIN policies p ON p.id=pp.policy_id
                         LEFT JOIN coverage_definitions cd ON cd.id=pp.coverage_definition_id
                         WHERE pp.person_id=? ORDER BY p.policy_number");
    $st->execute([$person['id']]); $person['policies'] = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($include['links']) && $person['policies']) {
        foreach ($person['policies'] as &$p) { $p['api_url'] = api_entity_url('policies', (int)$p['id']); $p['html_url'] = html_entity_url('policies', (int)$p['id']); }
        unset($p);
    }
    // Files
    $st = $pdo->prepare("SELECT id, filename, mime_type, size, caption, uploaded_at FROM files WHERE entity_type='person' AND entity_id=? ORDER BY uploaded_at DESC");
    $st->execute([$person['id']]); $person['files'] = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($include['links']) && $person['files']) {
        foreach ($person['files'] as &$f) { $f['url'] = Util::baseUrl('file.php?id='.(int)$f['id']); }
        unset($f);
    }
}

function enrich_policy_full(PDO $pdo, array &$policy, array $include = []): void {
    // Coverages
    $hasAcv = column_exists($pdo, 'policy_coverages', 'is_acv');
    $cols = 'pc.coverage_definition_id, cd.code, cd.name, pc.limit_amount, pc.deductible_amount'.($hasAcv? ', pc.is_acv':'');
    $st = $pdo->prepare("SELECT $cols FROM policy_coverages pc JOIN coverage_definitions cd ON cd.id=pc.coverage_definition_id WHERE pc.policy_id=? ORDER BY cd.name");
    $st->execute([$policy['id']]); $policy['coverages'] = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($include['links']) && $policy['coverages']) {
        $settingsUrl = Util::baseUrl('index.php?page=settings&tab=coverages');
        foreach ($policy['coverages'] as &$c) { $c['settings_url'] = $settingsUrl; }
        unset($c);
    }
    // Assets linked
    $paHasChildCov2 = column_exists($pdo, 'policy_assets', 'children_coverage_definition_id');
    $childSel2 = $paHasChildCov2 ? ', pa.children_coverage_definition_id' : '';
    $st = $pdo->prepare("SELECT pa.asset_id, a.name, pa.applies_to_children, pa.coverage_definition_id$childSel2, cd.name AS coverage_name
                         FROM policy_assets pa JOIN assets a ON a.id=pa.asset_id
                         LEFT JOIN coverage_definitions cd ON cd.id=pa.coverage_definition_id
                         WHERE pa.policy_id=? AND a.is_deleted=0 ORDER BY a.name");
    $st->execute([$policy['id']]); $policy['assets'] = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($include['links']) && $policy['assets']) {
        foreach ($policy['assets'] as &$a) { $a['api_url'] = api_entity_url('assets', (int)$a['asset_id']); $a['html_url'] = html_entity_url('assets', (int)$a['asset_id']); }
        unset($a);
    }
    // People linked
    $st = $pdo->prepare("SELECT pp.person_id, p.first_name, p.last_name, pp.role, pp.coverage_definition_id, cd.name AS coverage_name
                         FROM policy_people pp JOIN people p ON p.id=pp.person_id
                         LEFT JOIN coverage_definitions cd ON cd.id=pp.coverage_definition_id
                         WHERE pp.policy_id=? ORDER BY p.last_name, p.first_name");
    $st->execute([$policy['id']]); $policy['people'] = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($include['links']) && $policy['people']) {
        foreach ($policy['people'] as &$p) { $p['api_url'] = api_entity_url('people', (int)$p['person_id']); $p['html_url'] = html_entity_url('people', (int)$p['person_id']); }
        unset($p);
    }
    // Files
    $st = $pdo->prepare("SELECT id, filename, mime_type, size, caption, uploaded_at FROM files WHERE entity_type='policy' AND entity_id=? ORDER BY uploaded_at DESC");
    $st->execute([$policy['id']]); $policy['files'] = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($include['links']) && $policy['files']) {
        foreach ($policy['files'] as &$f) { $f['url'] = Util::baseUrl('file.php?id='.(int)$f['id']); }
        unset($f);
    }
    // Group versions
    if (!empty($policy['policy_group_id'])) {
        $st = $pdo->prepare('SELECT id, version_number, start_date, end_date, status FROM policies WHERE policy_group_id=? ORDER BY version_number DESC');
        $st->execute([$policy['policy_group_id']]); $policy['group_versions'] = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $policy['group_versions'] = [];
    }
}

// Bulk update endpoint: { entity, updates: [ { id, fields:{...} } ] }
function bulk_update(PDO $pdo, string $entity, array $updates): array {
    if (!$updates) return ['ok'=>false,'error'=>'No updates provided'];
    $table = table_for($entity); if (!$table) return ['ok'=>false,'error'=>'Unknown entity'];
    $allowed = allowed_columns_for($entity);
    if (!$allowed) return ['ok'=>false,'error'=>'No updatable columns'];

    $pdo->beginTransaction();
    $results = [];
    try {
        foreach ($updates as $u) {
            $id = (int)($u['id'] ?? 0);
            $fields = is_array($u['fields'] ?? null) ? $u['fields'] : [];
            if ($id <= 0) { $results[] = ['id'=>null,'ok'=>false,'error'=>'Missing id']; continue; }
            // Filter allowed columns only; ignore unknowns
            $cols = [];$vals=[];
            foreach ($fields as $k=>$v){
                if (!in_array($k, $allowed, true)) continue;
                $cols[] = "$k = ?";
                $vals[] = $v;
            }
            if (!$cols) { $results[] = ['id'=>$id,'ok'=>false,'error'=>'No valid fields']; continue; }
            $sql = 'UPDATE '.$table.' SET '.implode(', ', $cols).' WHERE id = ?';
            $vals[] = $id;
            $st = $pdo->prepare($sql);
            $st->execute($vals);
            $results[] = ['id'=>$id,'ok'=>true,'affected'=>$st->rowCount()];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok'=>false,'error'=>$e->getMessage()];
    }
    return ['ok'=>true,'results'=>$results];
}

try {
    // Authenticate request
    $auth = api_authenticate();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $entity = strtolower((string)($_GET['entity'] ?? $_POST['entity'] ?? ''));
    if (!in_array($entity, ['assets','people','policies'], true)) api_out(['ok'=>false,'error'=>'Invalid entity'], 400);

    $pdo = Database::get();

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            $include = parse_includes();
            $res = get_entity($pdo, $entity, $id, $include);
            $code = $res['code'] ?? 200; unset($res['code']);
            api_out($res, $code);
        } else {
            $include = parse_includes();
            api_out(list_entities($pdo, $entity, $include));
        }
    }

    if ($method === 'POST') {
        $body = parse_json_body();
        $updates = $body['updates'] ?? ($_POST['updates'] ?? []);
        if (is_string($updates)) { $updates = json_decode($updates, true) ?: []; }
        if (!is_array($updates)) $updates = [];
        api_out(bulk_update($pdo, $entity, $updates));
    }

    if ($method === 'OPTIONS') {
        // Simple CORS preflight support (no wildcards by default). Adjust as needed.
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, X-API-Key, Content-Type');
        api_out(['ok'=>true]);
    }

    api_out(['ok'=>false,'error'=>'Method not allowed'], 405);
} catch (Throwable $e) {
    api_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}
