<?php
/**
 * api/export_data.php
 * Downloads the authenticated user's data as a JSON file.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();
$user = current_user();

$userdb   = get_user_db();
$platform = get_platform_db();  // fixed: was get_db()

// --- Profile ---
$profile = [
    'id'         => $user['id'],
    'full_name'  => $user['full_name'] ?? '',
    'email'      => $user['email'] ?? '',
    'plan'       => $user['plan'] ?? 'free',
    'created_at' => $user['created_at'] ?? null,
];

// --- Generated sites ---
$sites = [];
try {
    $s = $platform->prepare(
        'SELECT slug, business_name, business_type, city, state, created_at, active
         FROM generated_sites WHERE user_id = ? ORDER BY created_at DESC'
    );
    $s->execute([$user['id']]);
    $sites = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $sites = ['note' => 'Could not fetch sites: ' . $e->getMessage()];
}

// --- Lead searches ---
$leads = [];
try {
    $l = $platform->prepare(
        'SELECT query, city, state, results_count, created_at
         FROM lead_searches WHERE user_id = ? ORDER BY created_at DESC LIMIT 500'
    );
    $l->execute([$user['id']]);
    $leads = $l->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $leads = ['note' => 'Lead history not available.'];
}

$export = [
    'exported_at'  => date('c'),
    'profile'      => $profile,
    'sites'        => $sites,
    'lead_history' => $leads,
];

$filename = 'utiligo-data-' . date('Y-m-d') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
