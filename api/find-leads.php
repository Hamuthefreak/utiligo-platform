<?php
/**
 * api/find-leads.php
 * Handles lead search via Google Places API.
 * Called by assets/js/leads.js on form submit.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

api_bootstrap();

header('Content-Type: application/json');

// 1. Auth guard
require_login();
$user = current_user();
$is_pro = ($user['plan'] ?? 'free') === 'pro';

// 2. Rate limit
if (!rate_limit_check('find_leads', RATE_LIMIT_FIND_LEADS)) {
    echo json_encode(['success' => false, 'error' => 'Too many searches. Please wait a moment.']);
    exit;
}

// 3. Parse JSON body
$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$city     = trim($body['city']     ?? '');
$industry = trim($body['industry'] ?? '');
$force    = !empty($body['force_refresh']);

// 4. CSRF check
if (!csrf_verify($body['csrf_token'] ?? null)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request. Please refresh the page.']);
    exit;
}

// 5. Validate inputs
if (!$city || !$industry) {
    echo json_encode(['success' => false, 'error' => 'City and industry are required.']);
    exit;
}

$pdo      = get_db();
$cacheKey = strtolower(preg_replace('/\s+/', ' ', $city . '|' . $industry));
$cached   = null;

// 6. Check cache
if (!$force) {
    try {
        $stmt = $pdo->prepare(
            "SELECT leads_json, created_at FROM lead_cache
             WHERE cache_key = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$cacheKey, LEAD_SEARCH_CACHE_HOURS]);
        $cached = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        // Table may not exist yet — continue to live search
        $cached = null;
    }
}

if ($cached) {
    $all_leads = json_decode($cached['leads_json'], true) ?? [];
    $cached_at = $cached['created_at'];
    $from_cache = true;
} else {
    // 7. Google Places Text Search
    $apiKey  = GOOGLE_PLACES_API_KEY;
    $query   = urlencode($industry . ' in ' . $city);
    $url     = "https://maps.googleapis.com/maps/api/place/textsearch/json?query={$query}&key={$apiKey}";

    $ctx  = stream_context_create(['http' => ['timeout' => 10]]);
    $resp = @file_get_contents($url, false, $ctx);

    if ($resp === false) {
        echo json_encode(['success' => false, 'error' => 'Could not reach Google Places. Please try again.']);
        exit;
    }

    $places = json_decode($resp, true);
    $status = $places['status'] ?? 'UNKNOWN';

    if ($status === 'REQUEST_DENIED') {
        echo json_encode(['success' => false, 'error' => 'Google Places API key is not configured. Contact support.']);
        exit;
    }

    if (!in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
        echo json_encode(['success' => false, 'error' => 'Places API error: ' . $status]);
        exit;
    }

    $results   = $places['results'] ?? [];
    $all_leads = [];
    $lookup_count = 0;

    foreach ($results as $place) {
        // Only businesses without a website — the whole point of Utiligo
        if (!empty($place['website'])) continue;

        $place_id = $place['place_id'] ?? '';
        $types    = $place['types']    ?? [];
        $rating   = isset($place['rating']) ? (float)$place['rating'] : null;
        $reviews  = (int)($place['user_ratings_total'] ?? 0);
        $category = !empty($types) ? str_replace('_', ' ', ucwords($types[0], '_')) : $industry;

        // Phone lookup via Details API (capped by MAX_PLACES_DETAILS_LOOKUPS)
        $phone = '';
        if ($place_id && $lookup_count < MAX_PLACES_DETAILS_LOOKUPS) {
            $det_url  = "https://maps.googleapis.com/maps/api/place/details/json?place_id={$place_id}&fields=formatted_phone_number&key={$apiKey}";
            $det_resp = @file_get_contents($det_url, false, $ctx);
            if ($det_resp !== false) {
                $det = json_decode($det_resp, true);
                $phone = $det['result']['formatted_phone_number'] ?? '';
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

    // 8. Sort by opportunity score descending
    usort($all_leads, fn($a, $b) => $b['opportunity_score'] <=> $a['opportunity_score']);

    // 9. Save to cache
    $cached_at = date('Y-m-d H:i:s');
    try {
        $pdo->prepare(
            "INSERT INTO lead_cache (cache_key, leads_json, created_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE leads_json = VALUES(leads_json), created_at = NOW()"
        )->execute([$cacheKey, json_encode($all_leads)]);
    } catch (\Throwable $e) {
        // Cache write failure is non-fatal — results still returned live
    }

    $from_cache = false;
}

// 10. Split visible vs locked based on plan
if ($is_pro) {
    echo json_encode([
        'success'      => true,
        'leads'        => $all_leads,
        'locked_leads' => [],
        'is_free_tier' => false,
        'from_cache'   => $from_cache,
        'cached_at'    => $cached_at,
    ]);
} else {
    $visible = array_slice($all_leads, 0, FREE_LEAD_LIMIT);
    $locked  = array_slice($all_leads, FREE_LEAD_LIMIT);

    // Redact locked leads SERVER-SIDE (CSS blur alone is not safe)
    $redacted = array_map(function ($lead) {
        $name = $lead['business_name'];
        $visible_chars = max(2, (int)ceil(strlen($name) * 0.3));
        return [
            'id'                => $lead['id'],
            'business_name'     => substr($name, 0, $visible_chars) . str_repeat('\u2588', max(4, strlen($name) - $visible_chars)),
            'business_address'  => '████████████',
            'business_phone'    => '',
            'opportunity_score' => 0,
        ];
    }, $locked);

    echo json_encode([
        'success'      => true,
        'leads'        => $visible,
        'locked_leads' => $redacted,
        'is_free_tier' => true,
        'from_cache'   => $from_cache,
        'cached_at'    => $cached_at,
    ]);
}
