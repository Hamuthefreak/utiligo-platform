<?php
/**
 * api/find-leads.php
 * Lead search endpoint — called by assets/js/leads.js
 * Bullet-proof version: full error handling, logging, server-side redaction,
 * phone lookup, caching, rate limiting, CSRF, plan gating.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

api_bootstrap();
header('Content-Type: application/json');

// ── 1. Auth ──────────────────────────────────────────────────────────────────
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You must be logged in.']);
    exit;
}
$user   = current_user();
$is_pro = ($user['plan'] ?? 'free') === 'pro';

// ── 2. Rate limit ────────────────────────────────────────────────────────────
if (!rate_limit_check('find_leads', RATE_LIMIT_FIND_LEADS)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many searches. Please wait a minute.']);
    exit;
}

// ── 3. Parse body ────────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$city     = trim($body['city']     ?? '');
$industry = trim($body['industry'] ?? '');
$force    = !empty($body['force_refresh']);

// ── 4. CSRF ──────────────────────────────────────────────────────────────────
if (!csrf_verify($body['csrf_token'] ?? null)) {
    http_response_code(403);
    log_error('find_leads_csrf', 'CSRF mismatch', ['user_id' => $user['id'], 'city' => $city]);
    echo json_encode(['success' => false, 'error' => 'Security check failed. Please refresh the page.']);
    exit;
}

// ── 5. Input validation ──────────────────────────────────────────────────────
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

$debug      = defined('DEBUG_MODE') && DEBUG_MODE === true;
$debug_log  = [];

try {
    $pdo      = get_platform_db();
    $cacheKey = strtolower(preg_replace('/\s+/', ' ', $city . '|' . $industry));
    $cached   = null;

    // ── 6. Cache lookup ──────────────────────────────────────────────────────
    if (!$force) {
        try {
            $stmt = $pdo->prepare(
                'SELECT leads_json, created_at FROM lead_cache
                 WHERE cache_key = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
                 ORDER BY created_at DESC LIMIT 1'
            );
            $stmt->execute([$cacheKey, LEAD_SEARCH_CACHE_HOURS]);
            $cached = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($debug) $debug_log[] = $cached ? 'Cache HIT for key: ' . $cacheKey : 'Cache MISS for key: ' . $cacheKey;
        } catch (\Throwable $e) {
            log_error('find_leads_cache_read', $e);
            if ($debug) $debug_log[] = 'Cache read error: ' . $e->getMessage();
            $cached = null; // non-fatal — fall through to live search
        }
    } else {
        if ($debug) $debug_log[] = 'Cache bypassed by force_refresh=true';
    }

    if ($cached) {
        $all_leads  = json_decode($cached['leads_json'], true) ?? [];
        $cached_at  = $cached['created_at'];
        $from_cache = true;
        if ($debug) $debug_log[] = 'Loaded ' . count($all_leads) . ' leads from cache (cached at ' . $cached_at . ')';
    } else {
        // ── 7. Google Places API key check ───────────────────────────────────
        $apiKey = GOOGLE_PLACES_API_KEY;
        if (empty($apiKey) || $apiKey === 'YOUR_GOOGLE_PLACES_API_KEY') {
            log_error('find_leads_no_api_key', 'GOOGLE_PLACES_API_KEY is not configured', ['user_id' => $user['id']]);
            echo json_encode(['success' => false, 'error' => 'Lead search is not configured yet. Please contact support.']);
            exit;
        }

        // ── 8. Text Search ───────────────────────────────────────────────────
        $query   = urlencode($industry . ' in ' . $city);
        $ts_url  = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query=' . $query . '&key=' . urlencode($apiKey);
        if ($debug) $debug_log[] = 'Places TextSearch URL: ' . preg_replace('/key=[^&]+/', 'key=REDACTED', $ts_url);

        $ctx  = stream_context_create(['http' => ['timeout' => 12, 'ignore_errors' => true]]);
        $resp = @file_get_contents($ts_url, false, $ctx);

        if ($resp === false) {
            log_error('find_leads_places_http', 'file_get_contents returned false for TextSearch', ['user_id' => $user['id'], 'city' => $city, 'industry' => $industry]);
            echo json_encode(['success' => false, 'error' => 'Could not reach Google Places. Please try again.']);
            exit;
        }

        $places = json_decode($resp, true);
        $status = $places['status'] ?? 'UNKNOWN';
        if ($debug) $debug_log[] = 'Places TextSearch status: ' . $status . ', results: ' . count($places['results'] ?? []);

        if ($status === 'REQUEST_DENIED') {
            log_error('find_leads_api_denied', 'Google Places REQUEST_DENIED — check API key/billing', ['user_id' => $user['id']]);
            echo json_encode(['success' => false, 'error' => 'Lead search is temporarily unavailable. Please contact support.']);
            exit;
        }
        if ($status === 'OVER_QUERY_LIMIT') {
            log_error('find_leads_quota', 'Google Places OVER_QUERY_LIMIT', ['user_id' => $user['id']]);
            echo json_encode(['success' => false, 'error' => 'Search quota reached for today. Please try again tomorrow.']);
            exit;
        }
        if (!in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
            log_error('find_leads_places_status', 'Unexpected Places status: ' . $status, ['user_id' => $user['id'], 'city' => $city, 'industry' => $industry]);
            echo json_encode(['success' => false, 'error' => 'Places search failed (' . $status . '). Try a different city or industry.']);
            exit;
        }

        $results      = $places['results'] ?? [];
        $all_leads    = [];
        $lookup_count = 0;

        foreach ($results as $place) {
            // Core premise: only businesses WITHOUT a website
            if (!empty($place['website'])) continue;

            $place_id = $place['place_id'] ?? '';
            $types    = $place['types']    ?? [];
            $rating   = isset($place['rating']) ? (float)$place['rating'] : null;
            $reviews  = (int)($place['user_ratings_total'] ?? 0);
            $category = !empty($types)
                ? str_replace('_', ' ', ucwords($types[0], '_'))
                : $industry;

            // ── 9. Phone via Details API (capped) ────────────────────────────
            $phone = '';
            if ($place_id !== '' && $lookup_count < MAX_PLACES_DETAILS_LOOKUPS) {
                $det_url  = 'https://maps.googleapis.com/maps/api/place/details/json?place_id='
                          . urlencode($place_id) . '&fields=formatted_phone_number&key=' . urlencode($apiKey);
                $det_resp = @file_get_contents($det_url, false, $ctx);
                if ($det_resp !== false) {
                    $det   = json_decode($det_resp, true);
                    $phone = $det['result']['formatted_phone_number'] ?? '';
                    if ($debug) $debug_log[] = 'Details lookup #' . ($lookup_count+1) . ' for place_id=' . $place_id . ' -> phone=' . ($phone ?: 'none');
                } else {
                    if ($debug) $debug_log[] = 'Details lookup #' . ($lookup_count+1) . ' HTTP failed for place_id=' . $place_id;
                }
                $lookup_count++;
            }

            $all_leads[] = [
                'id'                => $place_id,
                'business_name'     => $place['name'] ?? 'Unknown',
                'business_address'  => $place['formatted_address'] ?? '',
                'business_phone'    => $phone,
                'opportunity_score' => opportunity_score($rating, $reviews, $category),
            ];
        }

        // Sort best opportunities first
        usort($all_leads, fn($a, $b) => $b['opportunity_score'] <=> $a['opportunity_score']);
        if ($debug) $debug_log[] = count($all_leads) . ' leads without website found after filtering ' . count($results) . ' results';

        // ── 10. Save to cache ────────────────────────────────────────────────
        try {
            $pdo->prepare(
                'INSERT INTO lead_cache (cache_key, leads_json, created_at)
                 VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE leads_json = VALUES(leads_json), created_at = NOW()'
            )->execute([$cacheKey, json_encode($all_leads)]);
            if ($debug) $debug_log[] = 'Cache saved for key: ' . $cacheKey;
        } catch (\Throwable $e) {
            log_error('find_leads_cache_write', $e);
            if ($debug) $debug_log[] = 'Cache write error (non-fatal): ' . $e->getMessage();
        }

        $cached_at  = date('Y-m-d H:i:s');
        $from_cache = false;
    }

    // ── 11. Plan gating — split visible vs locked ────────────────────────────
    if ($is_pro) {
        $payload = [
            'success'      => true,
            'leads'        => $all_leads,
            'locked_leads' => [],
            'is_free_tier' => false,
            'from_cache'   => $from_cache,
            'cached_at'    => $cached_at,
        ];
    } else {
        $visible  = array_slice($all_leads, 0, FREE_LEAD_LIMIT);
        $locked   = array_slice($all_leads, FREE_LEAD_LIMIT);

        // Server-side redaction — CSS blur is cosmetic only
        $redacted = array_map(function ($lead) {
            $name = $lead['business_name'];
            $show = max(2, (int)ceil(strlen($name) * 0.25));
            return [
                'id'                => $lead['id'],
                'business_name'     => substr($name, 0, $show) . str_repeat('\u2588', max(5, strlen($name) - $show)),
                'business_address'  => '\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588\u2588',
                'business_phone'    => '',
                'opportunity_score' => 0,
            ];
        }, $locked);

        $payload = [
            'success'      => true,
            'leads'        => $visible,
            'locked_leads' => $redacted,
            'is_free_tier' => true,
            'from_cache'   => $from_cache,
            'cached_at'    => $cached_at,
        ];
    }

    if ($debug) $payload['_debug'] = $debug_log;
    echo json_encode($payload);

} catch (\Throwable $e) {
    log_error('find_leads_fatal', $e, ['user_id' => $user['id'] ?? null, 'city' => $city, 'industry' => $industry]);
    $err = ['success' => false, 'error' => 'Something went wrong. Please try again.'];
    if ($debug) $err['_debug'] = array_merge($debug_log, ['FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()]);
    echo json_encode($err);
}
