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
$platform = get_platform_db();

// --- Profile ---
$profile = [
    'id'         => $user['id'],
    'full_name'  => $user['full_name'] ?? '',
    'email'      => $user['email'] ?? '',
    'plan'       => $user['plan'] ?? 'free',
    'created_at' => $user['created_at'] ?? null,
];

// --- Generated sites (correct table + correct column names) ---
$sites = [];
try {
    $s = $platform->prepare(
        'SELECT public_slug, business_name, business_category, business_city,
                template_name, link_active, view_count, created_at, link_expires_at
         FROM utiligo_generated_sites
         WHERE user_id = ?
         ORDER BY created_at DESC'
    );
    $s->execute([$user['id']]);
    $sites = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $sites = ['note' => 'Could not fetch sites: ' . $e->getMessage()];
}

// --- Lead history (utiligo_leads table) ---
$leads = [];
try {
    $l = $platform->prepare(
        'SELECT business_name, business_category, business_city, business_phone,
                business_email, created_at
         FROM utiligo_leads
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 500'
    );
    $l->execute([$user['id']]);
    $leads = $l->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Fallback: try unlocked_leads table
    try {
        $l2 = $platform->prepare(
            'SELECT lead_data, created_at
             FROM unlocked_leads
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 500'
        );
        $l2->execute([$user['id']]);
        $rows  = $l2->fetchAll(PDO::FETCH_ASSOC);
        $leads = array_map(function ($r) {
            $decoded = json_decode($r['lead_data'] ?? '{}', true) ?: [];
            $decoded['unlocked_at'] = $r['created_at'];
            return $decoded;
        }, $rows);
    } catch (\Throwable $e2) {
        $leads = ['note' => 'Lead history not available.'];
    }
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
