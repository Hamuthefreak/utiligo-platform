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

// --- Lead history: unlocked leads joined to their Google Places data ---
$leads = [];
try {
    $l = $platform->prepare(
        'SELECT l.business_name, l.business_category, l.business_city, l.business_phone,
                l.business_email, l.rating, l.maps_url, ul.unlocked_at
         FROM unlocked_leads ul
         INNER JOIN utiligo_leads l ON l.id = ul.lead_id
         WHERE ul.user_id = ?
         ORDER BY ul.unlocked_at DESC
         LIMIT 500'
    );
    $l->execute([$user['id']]);
    $leads = $l->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Degrade gracefully to search history on hosts missing the join tables.
    try {
        $h = $platform->prepare(
            'SELECT city, industry, keywords, result_count, created_at
             FROM utiligo_lead_search_history
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 500'
        );
        $h->execute([$user['id']]);
        $leads = $h->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e2) {
        $leads = [];
    }
}

// --- CRM data (best effort; tables may not exist on all hosts) ---
$crm = ['clients' => [], 'deals' => [], 'tasks' => [], 'notes' => [], 'activities' => []];
$crmQueries = [
    'clients'    => 'SELECT id, name, business, email, phone, city, industry, stage, deal_value, probability, source, created_at, updated_at FROM crm_clients WHERE user_id = ? ORDER BY id DESC',
    'deals'      => 'SELECT d.id, d.client_id, c.name AS client_name, d.title, d.value, d.stage, d.closed_at, d.created_at FROM crm_deals d LEFT JOIN crm_clients c ON c.id = d.client_id WHERE d.user_id = ? ORDER BY d.id DESC',
    'tasks'      => 'SELECT id, client_id, title, due_date, priority, done, remind_email, done_at, created_at FROM crm_tasks WHERE user_id = ? ORDER BY id DESC',
    'notes'      => 'SELECT id, client_id, body, pinned, created_at FROM crm_notes WHERE user_id = ? ORDER BY id DESC',
    'activities' => 'SELECT id, client_id, activity_type, title, meta, created_at FROM crm_activities WHERE user_id = ? ORDER BY id DESC',
];
foreach ($crmQueries as $key => $sql) {
    try {
        $q = $platform->prepare($sql);
        $q->execute([$user['id']]);
        $crm[$key] = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { /* table missing — leave empty */ }
}

// --- White-label brand (best effort) ---
$whitelabel = null;
try {
    $wb = $platform->prepare('SELECT brand_name, primary_color, secondary_color FROM utiligo_whitelabel WHERE user_id = ? LIMIT 1');
    $wb->execute([$user['id']]);
    $whitelabel = $wb->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) { $whitelabel = null; }

$export = [
    'exported_at'  => date('c'),
    'profile'      => $profile,
    'sites'        => $sites,
    'lead_history' => $leads,
    'crm'          => $crm,
    'whitelabel'   => $whitelabel,
];

$filename = 'utiligo-data-' . date('Y-m-d') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
