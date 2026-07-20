<?php
/**
 * api/find-leads.php
 * Lead search — fixed:
 *   - Upserts each lead into utiligo_leads so generate.php can find them by integer id
 *   - Returns pro_lead_count on EVERY response (not just pro branch)
 *   - History dedup: ON DUPLICATE KEY UPDATE so same city+industry+keywords just bumps date
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

api_bootstrap();
header('Content-Type: application/json');

// ── 1. Auth ───────────────────────────────────────────────────────────────────
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You must be logged in.']);
    exit;
}
$user   = current_user();
$plan   = $user['plan'] ?? 'free';
$is_pro = in_array($plan, ['pro', 'entrepreneur'], true);

// ── 2. Per-minute burst rate limit ───────────────────────────────────────────
if (!rate_limit_check('find_leads', RATE_LIMIT_FIND_LEADS)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait a moment.']);
    exit;
}

// ── 3. Parse body ─────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body.']);
    exit;
}

$city      = trim($body['city']      ?? '');
$industry  = trim($body['industry']  ?? '');
$keywords  = trim($body['keywords']  ?? '');
$force     = !empty($body['force_refresh']);
$lead_count_requested = max(1, min(40, (int)($body['lead_count'] ?? 10)));

// ── 4. CSRF ───────────────────────────────────────────────────────────────────
if (!csrf_verify($body['csrf_token'] ?? null)) {
    http_response_code(403);
    log_error('find_leads_csrf', 'CSRF mismatch', ['user_id' => $user['id']]);
    echo json_encode(['success' => false, 'error' => 'Security check failed. Please refresh the page.']);
    exit;
}

// ── 5. Input validation ───────────────────────────────────────────────────────
if ($city === '' || $industry === '') {
    echo json_encode(['success' => false, 'error' => 'City and industry are required.']);
    exit;
}
if (strlen($city) > 100 || strlen($industry) > 100) {
    echo json_encode(['success' => false, 'error' => 'Input too long.']);
    exit;
}
if (!preg_match('/^[\p{L}0-9 \-\',.]+$/u', $city) || !preg_match('/^[\p{L}0-9 \-\',.&]+$/u', $industry)) {
    echo json_encode(['success' => false, 'error' => 'City or industry contains invalid characters.']);
    exit;
}

$debug     = defined('DEBUG_MODE') && DEBUG_MODE === true;
$debug_log = [];

try {
    $pdo = get_platform_db();

    // ── Ensure tables exist ───────────────────────────────────────────────────
    // History table — with unique key for dedup
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `utiligo_lead_search_history` (
           `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
           `user_id`      INT UNSIGNED NOT NULL,
           `city`         VARCHAR(100) NOT NULL,
           `industry`     VARCHAR(100) NOT NULL,
           `keywords`     VARCHAR(255) NOT NULL DEFAULT \'\',
           `result_count` INT UNSIGNED NOT NULL DEFAULT 0,
           `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (`id`),
           UNIQUE KEY `uq_user_search` (`user_id`,`city`,`industry`,`keywords`),
           KEY `idx_user_id` (`user_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // utiligo_leads table — stores each unique place so generate.php can lookup by integer id
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `utiligo_leads` (
           `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
           `place_id`          VARCHAR(255) NOT NULL,
           `business_name`     VARCHAR(255) NOT NULL DEFAULT \'\',
           `business_address`  VARCHAR(500) NOT NULL DEFAULT \'\',
           `business_phone`    VARCHAR(80)  NOT NULL DEFAULT \'\',
           `business_category` VARCHAR(150) NOT NULL DEFAULT \'\',
           `business_city`     VARCHAR(100) NOT NULL DEFAULT \'\',
           `rating`            DECIMAL(3,1) NULL,
           `total_ratings`     INT UNSIGNED NOT NULL DEFAULT 0,
           `maps_url`          VARCHAR(500) NOT NULL DEFAULT \'\',
           `opportunity_score` INT UNSIGNED NOT NULL DEFAULT 0,
           `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
           PRIMARY KEY (`id`),
           UNIQUE KEY `uq_place_id` (`place_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // ── 6. Free-plan daily search limit ───────────────────────────────────────
    $searches_used      = 0;
    $searches_remaining = null;
    if (!$is_pro) {
        $daily_limit = defined('FREE_SEARCH_DAILY_LIMIT') ? (int)FREE_SEARCH_DAILY_LIMIT : 2;
        $ip_hash     = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $fingerprint = 'u' . $user['id'] . '_' . substr($ip_hash, 0, 16);

        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS `lead_search_quota` (
                   `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                   `fingerprint` VARCHAR(80)  NOT NULL,
                   `user_id`     INT UNSIGNED NOT NULL,
                   `count`       INT UNSIGNED NOT NULL DEFAULT 0,
                   `window_start` DATETIME    NOT NULL,
                   PRIMARY KEY (`id`),
                   UNIQUE KEY `uq_fingerprint` (`fingerprint`),
                   KEY `idx_user_id` (`user_id`)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            $cutoff = (new \DateTime())->modify('-24 hours')->format('Y-m-d H:i:s');
            $qStmt  = $pdo->prepare('SELECT id, count, window_start FROM lead_search_quota WHERE fingerprint = ? LIMIT 1');
            $qStmt->execute([$fingerprint]);
            $quota  = $qStmt->fetch(PDO::FETCH_ASSOC);

            if (!$quota) {
                $pdo->prepare('INSERT INTO lead_search_quota (fingerprint, user_id, count, window_start) VALUES (?, ?, 1, NOW())')->execute([$fingerprint, $user['id']]);
                $searches_used = 1; $searches_remaining = $daily_limit - 1;
            } elseif ($quota['window_start'] < $cutoff) {
                $pdo->prepare('UPDATE lead_search_quota SET count = 1, window_start = NOW() WHERE id = ?')->execute([$quota['id']]);
                $searches_used = 1; $searches_remaining = $daily_limit - 1;
            } else {
                $searches_used = (int)$quota['count'];
                if ($searches_used >= $daily_limit) {
                    $reset_ts = strtotime($quota['window_start']) + 86400;
                    http_response_code(429);
                    echo json_encode(['success' => false, 'error' => 'You have used all ' . $daily_limit . ' free searches for today. Upgrade to Pro for unlimited searches, or wait until your quota resets.', 'rate_limited' => true, 'resets_at' => $reset_ts]);
                    exit;
                }
                $pdo->prepare('UPDATE lead_search_quota SET count = count + 1 WHERE id = ?')->execute([$quota['id']]);
                $searches_used = $searches_used + 1;
                $searches_remaining = max(0, $daily_limit - $searches_used);
            }
        } catch (\Throwable $e) {
            log_error('find_leads_quota_check', $e, ['user_id' => $user['id']]);
        }
    }

    // ── 7. Cache lookup ───────────────────────────────────────────────────────
    $cacheKey   = strtolower(preg_replace('/\s+/', ' ', $city . '|' . $industry));
    $cacheHours = defined('LEAD_SEARCH_CACHE_HOURS') ? (int)LEAD_SEARCH_CACHE_HOURS : 24;
    $cached     = null;
    if (!$force) {
        try {
            $stmt = $pdo->prepare('SELECT leads_json, created_at FROM lead_cache WHERE cache_key = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR) ORDER BY created_at DESC LIMIT 1');
            $stmt->execute([$cacheKey, $cacheHours]);
            $cached = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { log_error('find_leads_cache_read', $e); }
    }

    if ($cached) {
        $all_leads  = json_decode($cached['leads_json'], true) ?? [];
        $cached_at  = $cached['created_at'];
        $from_cache = true;
    } else {
        // ── 8. Google Places API ──────────────────────────────────────────────
        $apiKey = defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : '';
        if (empty($apiKey) || $apiKey === 'YOUR_GOOGLE_PLACES_API_KEY') {
            echo json_encode(['success' => false, 'error' => 'Lead search is not configured yet. Please contact support.']);
            exit;
        }

        $query  = urlencode($industry . ' in ' . $city);
        $ts_url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query=' . $query . '&key=' . urlencode($apiKey);
        $ctx    = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true]]);
        $resp   = @file_get_contents($ts_url, false, $ctx);

        if ($resp === false) {
            echo json_encode(['success' => false, 'error' => 'Could not reach Google Places. Please try again.']);
            exit;
        }

        $places = json_decode($resp, true);
        $status = $places['status'] ?? 'UNKNOWN';

        if ($status === 'REQUEST_DENIED') { echo json_encode(['success' => false, 'error' => 'Lead search is temporarily unavailable.']); exit; }
        if ($status === 'OVER_QUERY_LIMIT') { echo json_encode(['success' => false, 'error' => 'Daily search quota reached. Please try again tomorrow.']); exit; }
        if (!in_array($status, ['OK', 'ZERO_RESULTS'], true)) { echo json_encode(['success' => false, 'error' => 'Search failed (' . $status . '). Try a different city or industry.']); exit; }

        $results    = $places['results'] ?? [];
        $all_leads  = [];
        $maxDetails = defined('MAX_PLACES_DETAILS_LOOKUPS') ? (int)MAX_PLACES_DETAILS_LOOKUPS : 20;
        $lc         = 0;

        foreach ($results as $place) {
            if (!empty($place['website'])) continue;
            $place_id = $place['place_id'] ?? '';
            $types    = $place['types']    ?? [];
            $rating   = isset($place['rating']) ? (float)$place['rating'] : null;
            $reviews  = (int)($place['user_ratings_total'] ?? 0);
            $category = !empty($types) ? str_replace('_', ' ', ucwords($types[0], '_')) : $industry;
            $maps_url = $place_id !== '' ? 'https://www.google.com/maps/place/?q=place_id:' . urlencode($place_id) : '';
            $phone    = '';

            if ($place_id !== '' && $lc < $maxDetails) {
                $det  = @file_get_contents('https://maps.googleapis.com/maps/api/place/details/json?place_id=' . urlencode($place_id) . '&fields=formatted_phone_number&key=' . urlencode($apiKey), false, $ctx);
                if ($det !== false) { $d = json_decode($det, true); $phone = $d['result']['formatted_phone_number'] ?? ''; }
                $lc++;
            }

            $all_leads[] = [
                'place_id'          => $place_id,
                'id'                => $place_id, // temporary — replaced below with DB int id
                'business_name'     => $place['name'] ?? 'Unknown',
                'business_address'  => $place['formatted_address'] ?? '',
                'business_city'     => $city,
                'business_phone'    => $phone,
                'business_category' => $category,
                'rating'            => $rating,
                'total_ratings'     => $reviews,
                'maps_url'          => $maps_url,
                'no_website'        => true,
                'opportunity_score' => opportunity_score($rating, $reviews, $category),
            ];
        }

        usort($all_leads, fn($a, $b) => $b['opportunity_score'] <=> $a['opportunity_score']);

        // ── 9. Cache write ────────────────────────────────────────────────────
        try {
            $pdo->prepare('INSERT INTO lead_cache (cache_key, leads_json, created_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE leads_json = VALUES(leads_json), created_at = NOW()')->execute([$cacheKey, json_encode($all_leads)]);
        } catch (\Throwable $e) { log_error('find_leads_cache_write', $e); }

        $cached_at  = date('Y-m-d H:i:s');
        $from_cache = false;
    }

    // ── 10. Upsert each lead into utiligo_leads so generate.php can find them ─
    // Map place_id -> integer DB id for Build Site links
    $place_id_map = []; // place_id => int id
    foreach ($all_leads as $lead) {
        $pid = $lead['place_id'] ?? $lead['id'] ?? '';
        if ($pid === '') continue;
        try {
            $pdo->prepare(
                'INSERT INTO utiligo_leads
                   (place_id, business_name, business_address, business_phone, business_category, business_city, rating, total_ratings, maps_url, opportunity_score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   business_name     = VALUES(business_name),
                   business_address  = VALUES(business_address),
                   business_phone    = VALUES(business_phone),
                   business_category = VALUES(business_category),
                   business_city     = VALUES(business_city),
                   rating            = VALUES(rating),
                   total_ratings     = VALUES(total_ratings),
                   maps_url          = VALUES(maps_url),
                   opportunity_score = VALUES(opportunity_score)'
            )->execute([
                $pid,
                $lead['business_name'],
                $lead['business_address'],
                $lead['business_phone'],
                $lead['business_category'],
                $lead['business_city'] ?? '',
                $lead['rating'],
                $lead['total_ratings'],
                $lead['maps_url'],
                $lead['opportunity_score'],
            ]);
            // Fetch the integer id (works for both INSERT and UPDATE)
            $idStmt = $pdo->prepare('SELECT id FROM utiligo_leads WHERE place_id = ? LIMIT 1');
            $idStmt->execute([$pid]);
            $dbId = $idStmt->fetchColumn();
            if ($dbId) $place_id_map[$pid] = (int)$dbId;
        } catch (\Throwable $e) {
            log_error('find_leads_upsert', $e, ['place_id' => $pid]);
        }
    }

    // Replace placeholder 'id' with integer DB id in each lead
    foreach ($all_leads as &$lead) {
        $pid = $lead['place_id'] ?? '';
        if ($pid !== '' && isset($place_id_map[$pid])) {
            $lead['id'] = $place_id_map[$pid];
        }
    }
    unset($lead);

    // ── 11. Plan gating ───────────────────────────────────────────────────────
    $free_limit = defined('FREE_LEAD_LIMIT') ? (int)FREE_LEAD_LIMIT : 3;

    // Always fetch pro_lead_count so the JS counter can update on every response
    $pro_lead_count = 0;
    $pro_lead_limit = 0;
    if ($plan === 'pro') {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM utiligo_leads WHERE id IN (SELECT lead_id FROM unlocked_leads WHERE user_id = ?)');
            $stmt->execute([$user['id']]);
            $pro_lead_count = (int)$stmt->fetchColumn();
            $pro_lead_limit = defined('PRO_LEAD_LIMIT') ? (int)PRO_LEAD_LIMIT : 120;
        } catch (\Throwable $e) {
            // unlocked_leads may not exist; count from utiligo_leads viewed instead
            $pro_lead_count = 0;
            $pro_lead_limit = defined('PRO_LEAD_LIMIT') ? (int)PRO_LEAD_LIMIT : 120;
        }
    }

    if ($is_pro) {
        $leads_to_show = array_slice($all_leads, 0, $lead_count_requested);
        $payload = [
            'success'        => true,
            'leads'          => $leads_to_show,
            'locked_leads'   => [],
            'is_free_tier'   => false,
            'from_cache'     => $from_cache,
            'cached_at'      => $cached_at,
            'pro_lead_count' => $pro_lead_count,
            'lead_limit'     => $pro_lead_limit,
        ];
    } else {
        $visible  = array_slice($all_leads, 0, $free_limit);
        $locked   = array_slice($all_leads, $free_limit);
        $redacted = array_map(function ($lead) {
            return ['id' => $lead['id'], 'business_name' => '', 'business_address' => '', 'business_phone' => '', 'opportunity_score' => 0, 'no_website' => true, '_locked' => true];
        }, $locked);

        $payload = [
            'success'            => true,
            'leads'              => $visible,
            'locked_leads'       => $redacted,
            'is_free_tier'       => true,
            'searches_used'      => $searches_used,
            'searches_remaining' => $searches_remaining,
            'from_cache'         => $from_cache,
            'cached_at'          => $cached_at,
            'pro_lead_count'     => 0,
            'lead_limit'         => 0,
        ];
    }

    // ── 12. Save/update search history (DEDUP: same city+industry+keywords → update date only) ──
    try {
        $result_count = count($all_leads);
        $pdo->prepare(
            'INSERT INTO utiligo_lead_search_history (user_id, city, industry, keywords, result_count, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE result_count = VALUES(result_count), created_at = NOW()'
        )->execute([$user['id'], $city, $industry, $keywords, $result_count]);
    } catch (\Throwable $e) { /* non-fatal */ }

    if ($debug) $payload['_debug'] = $debug_log;
    echo json_encode($payload);

} catch (\Throwable $e) {
    log_error('find_leads_fatal', $e, ['user_id' => $user['id'] ?? null, 'city' => $city ?? null, 'industry' => $industry ?? null]);
    $err = ['success' => false, 'error' => 'Something went wrong. Please try again.'];
    if ($debug) $err['_debug'] = array_merge($debug_log, ['FATAL: ' . $e->getMessage()]);
    echo json_encode($err);
}
