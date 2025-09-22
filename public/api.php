<?php
// JSON API for Asset Vault
// Endpoints:
//   GET  /api.php?entity=assets|people|policies[&id=123]  -> list or detail
//   POST /api.php  with JSON { entity, updates: [ {id, fields:{...}}, ... ] }
// Auth:
//   Provide API token via one of:
//     - Authorization: Bearer <token>
//     - X-API-Key: <token>
//     - ?token=<token>
//   Token is validated against SSO validate endpoint from public/config.php.

require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Util.php';
require_once __DIR__ . '/auth.php'; // reuse HMAC verification helpers + config

header('Content-Type: application/json');

function api_out($data, int $code = 200){ http_response_code($code); echo json_encode($data); exit; }

// Extract API token from headers/query
function api_get_token(): ?string {
    $hdrs = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    $auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
    if (stripos($auth, 'Bearer ') === 0) return trim(substr($auth, 7));
    $x = $hdrs['X-API-Key'] ?? $hdrs['x-api-key'] ?? null;
    if ($x) return trim($x);
    if (!empty($_GET['token'])) return (string)$_GET['token'];
    return null;
}

// Validate token using existing revalidate() from auth.php and return payload from cookie
function api_authenticate(): array {
    global $config; // from public/auth.php
    $token = api_get_token();
    if (!$token) api_out(['ok'=>false,'error'=>'Missing API token'], 401);
    if (!revalidate($token)) api_out(['ok'=>false,'error'=>'Invalid token'], 401);
    $cookieRaw = $_COOKIE[$config['cookie_name']] ?? '';
    $payload = $cookieRaw ? (json_decode($cookieRaw, true) ?: null) : null;
    if (!$payload) api_out(['ok'=>false,'error'=>'Auth payload unavailable'], 401);
    $roles = $payload['roles'] ?? [];
    if (!array_intersect($roles, ['vault','admin'])) api_out(['ok'=>false,'error'=>'Not authorized'], 403);
    return $payload;
}

function parse_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
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
function list_entities(PDO $pdo, string $entity): array {
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
            return ['ok'=>true,'data'=>$rows,'count'=>count($rows)];
        }
        default:
            return ['ok'=>false,'error'=>'Unknown entity'];
    }
}

// Detail endpoints
function get_entity(PDO $pdo, string $entity, int $id): array {
    switch ($entity) {
        case 'assets': {
            $st = $pdo->prepare('SELECT a.*, ac.name AS category_name FROM assets a LEFT JOIN asset_categories ac ON ac.id=a.category_id WHERE a.id=? AND a.is_deleted=0');
            $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['ok'=>false,'error'=>'Not found', 'code'=>404];
            return ['ok'=>true,'data'=>$row];
        }
        case 'people': {
            $st = $pdo->prepare('SELECT * FROM people WHERE id=?');
            $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['ok'=>false,'error'=>'Not found', 'code'=>404];
            return ['ok'=>true,'data'=>$row];
        }
        case 'policies': {
            $st = $pdo->prepare('SELECT * FROM policies WHERE id=?');
            $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return ['ok'=>false,'error'=>'Not found', 'code'=>404];
            return ['ok'=>true,'data'=>$row];
        }
        default:
            return ['ok'=>false,'error'=>'Unknown entity'];
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
            $res = get_entity($pdo, $entity, $id);
            $code = $res['code'] ?? 200; unset($res['code']);
            api_out($res, $code);
        } else {
            api_out(list_entities($pdo, $entity));
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
