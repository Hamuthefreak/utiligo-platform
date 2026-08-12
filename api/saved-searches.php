<?php
/**
 * api/saved-searches.php — Phase 5 server-side save of user searches.
 *
 * POST request with JSON body:
 *   {
 *     "csrf_token": "...",
 *     "op":          "create" | "update" | "delete" | "list",
 *     "id":          int   (for update / delete),
 *     "name":        string (create / update),
 *     "params":      { city, industry, keywords, sources[] }  (create / update),
 *     "notify_email": bool   (update; default false)
 *   }
 *
 * Why a single endpoint with an "op" field rather than four REST routes:
 * we already use this pattern (api/manage-site.php?op=…) elsewhere and
 * it keeps the InfinityFree / no-rewrite hosting story clean.
 *
 * Authorization: require_login() — caller may only see/edit rows whose
 * user_id matches their own. IDOR-guarded via the user_id match in every
 * UPDATE / DELETE.
 *
 * Returns JSON. On error: { success:false, error:"..." } with a 4xx / 5xx
 * status. On success: { success:true, id:expr:int, ... }.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/lead_activity_log.php';

header('Content-Type: application/json; charset=utf-8');

require_login();

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$csrf = (string)($in['csrf_token'] ?? '');
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
    exit;
}

$op   = (string)($in['op']   ?? '');
$uid  = (int)($_SESSION['user_id'] ?? 0);
if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'not_logged_in']);
    exit;
}

$pdo = get_platform_db();

// Bounds to keep saved-search spam from filling rows forever.
define('SAVED_SEARCHES_PER_USER_CAP', 50);

switch ($op) {
case 'list':
    try {
        $stmt = $pdo->prepare('SELECT id, name, params, last_run_at, last_count, notify_email, created_at FROM saved_searches WHERE user_id = ? ORDER BY created_at DESC LIMIT 200');
        $stmt->execute([$uid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        log_error('saved_searches_list', $e);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
        exit;
    }
    foreach ($rows as &$r) {
        if (!empty($r['params']) && is_string($r['params'])) {
            try { $r['params'] = json_decode($r['params'], true); } catch (\Throwable $e) {}
        }
    }
    unset($r);
    echo json_encode(['success' => true, 'saved_searches' => $rows]);
    exit;

case 'create':
    $name   = trim((string)($in['name']   ?? ''));
    $params = (array)($in['params'] ?? []);
    if ($name === '' || strlen($name) > 120) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_name']);
        exit;
    }
    // Cap params to the keys we know — never trust client shape.
    $clean_params = [
        'city'      => substr((string)($params['city']      ?? ''), 0, 100),
        'industry'  => substr((string)($params['industry']  ?? ''), 0, 100),
        'keywords'  => substr((string)($params['keywords']  ?? ''), 0, 200),
    ];
    if (!empty($params['sources']) && is_array($params['sources'])) {
        $clean_params['sources'] = array_values(array_slice($params['sources'], 0, 8));
    }
    try {
        $j = json_encode($clean_params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Enforce per-user cap.
        $cap = $pdo->prepare('SELECT COUNT(*) FROM saved_searches WHERE user_id = ?');
        $cap->execute([$uid]); $count = (int)$cap->fetchColumn();
        if ($count >= SAVED_SEARCHES_PER_USER_CAP) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'cap_reached']);
            exit;
        }
        $ins = $pdo->prepare('INSERT INTO saved_searches (user_id, name, params, created_at) VALUES (?, ?, ?, NOW())');
        $ins->execute([$uid, $name, $j]);
        $new_id = (int)$pdo->lastInsertId();
    } catch (\Throwable $e) {
        log_error('saved_searches_create', $e);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
        exit;
    }
    // Audit log entry.
    try { log_lead_activity($pdo, $uid, LEAD_ACT_SAVED_SEARCH, $new_id, [
        'name'   => $name,
        'params' => $clean_params,
    ]); } catch (\Throwable $e) {}
    echo json_encode(['success' => true, 'id' => $new_id]);
    exit;

case 'update':
    $id   = (int)($in['id']   ?? 0);
    $name = trim((string)($in['name'] ?? ''));
    $notify = !empty($in['notify_email']) ? 1 : 0;
    $params = (array)($in['params'] ?? []);
    if ($id <= 0 || $name === '' || strlen($name) > 120) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_input']);
        exit;
    }
    // Build params if provided; otherwise re-read existing row's.
    if (!empty($params)) {
        $clean_params = [
            'city'      => substr((string)($params['city']      ?? ''), 0, 100),
            'industry'  => substr((string)($params['industry']  ?? ''), 0, 100),
            'keywords'  => substr((string)($params['keywords']  ?? ''), 0, 200),
        ];
        if (!empty($params['sources']) && is_array($params['sources'])) {
            $clean_params['sources'] = array_values(array_slice($params['sources'], 0, 8));
        }
        $j = json_encode($clean_params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        try {
            $ro = $pdo->prepare('SELECT params FROM saved_searches WHERE id = ? AND user_id = ? LIMIT 1');
            $ro->execute([$id, $uid]);
            $j = $ro->fetchColumn() ?: '{}';
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'db_error']);
            exit;
        }
    }
    try {
        $stmt = $pdo->prepare('UPDATE saved_searches SET name = ?, params = ?, notify_email = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$name, $j, $notify, $id, $uid]);
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }
    } catch (\Throwable $e) {
        log_error('saved_searches_update', $e);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
        exit;
    }
    try { log_lead_activity($pdo, $uid, LEAD_ACT_NOTIFY_TOGGLE, $id, [
        'name'         => $name,
        'notify_email' => $notify ? 1 : 0,
    ]); } catch (\Throwable $e) {}
    echo json_encode(['success' => true, 'id' => $id]);
    exit;

case 'delete':
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_id']);
        exit;
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM saved_searches WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $uid]);
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'not_found']);
            exit;
        }
    } catch (\Throwable $e) {
        log_error('saved_searches_delete', $e);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'db_error']);
        exit;
    }
    echo json_encode(['success' => true, 'id' => $id]);
    exit;

default:
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'unknown_op']);
    exit;
}
