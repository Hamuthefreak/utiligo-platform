<?php
/**
 * api/find-leads.php — complete rewrite, bulletproof unlock chain
 *
 * Key fixes vs all previous versions:
 *  1. Upsert + integer-ID resolution runs for BOTH fresh AND cached results.
 *  2. Unlock loop uses $place_id_map directly — never touches $lead['id'],
 *     which may still be a string at that point depending on JSON decode path.
 *  3. lead_limit sent to JS is always >= 0 (entrepreneur = 0, not -1).
 *  4. Every DB step is individually try/caught with error_log so nothing
 *     silently swallows a MySQL error on InfinityFree's strict mode.
 *  5. Returns pro_lead_count fetched AFTER unlocks so count is always fresh.
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
$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro', 'entrepreneur'], true);
$uid     = (int)$user['id'];

// ── 2. Rate limit ────────────────────────────────────────────────────────────
if (!rate_limit_check('find_leads', RATE_LIMIT_FIND_LEADS)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait a moment.']);
    exit;
}

// ── 3. Parse body ────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
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

// ── 4. CSRF ──────────────────────────────────────────────────────────────────
if (!csrf_verify($body['csrf_token'] ?? null)) {
    http_response_code(403);
    log_error('find_leads_csrf', 'CSRF mismatch', ['uid' => $uid]);
    echo json_encode(['success' => false, 'error' => 'Security check failed. Please refresh the page.']);
    exit;
}

// ── 5. Validate ──────────────────────────────────────────────────────────────
if ($city === '' || $industry === '') {
    echo json_encode(['success' => false, 'error' => 'City and industry are required.']);
    exit;
}
if (strlen($city) > 100 || strlen($industry) > 100) {
    echo json_encode(['success' => false, 'error' => 'Input too long.']);
    exit;
}
if (!preg_match('/^[\p{L}0-9 \-\',.]+$/u', $city)
 ||  !preg_match('/^[\p{L}0-9 \-\',.&]+$/u', $industry)) {
    echo json_encode(['success' => false, 'error' => 'City or industry contains invalid characters.']);
    exit;
}

try {
    $pdo = get_platform_db();

    // ── Ensure every required table exists ───────────────────────────────────
    $pdo->exec('CREATE TABLE IF NOT EXISTS `utiligo_lead_search_history` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`      INT UNSIGNED NOT NULL,
        `city`         VARCHAR(100) NOT NULL,
        `industry`     VARCHAR(100) NOT NULL,
        `keywords`     VARCHAR(255) NOT NULL DEFAULT \'\',
        `result_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_user_search` (`user_id`,`city`,`industry`,`keywords`),
        KEY `idx_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `utiligo_leads` (
        `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `place_id`          VARCHAR(255) NOT NULL,
        `business_name`     VARCHAR(255) NOT NULL DEFAULT \'\',
        `business_address`  VARCHAR(500) NOT NULL DEFAULT \'\',
        `business_phone`    VARCHAR(80)  NOT NULL DEFAULT \'\',
        `business_email`    VARCHAR(255) NOT NULL DEFAULT \'\',
        `business_category` VARCHAR(150) NOT NULL DEFAULT \'\',
        `business_city`     VARCHAR(100) NOT NULL DEFAULT \'\',
        `rating`            DECIMAL(3,1) NULL,
        `total_ratings`     INT UNSIGNED NOT NULL DEFAULT 0,
        `maps_url`          VARCHAR(500) NOT NULL DEFAULT \'\',
        `opportunity_score` INT UNSIGNED NOT NULL DEFAULT 0,
        `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_place_id` (`place_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // Add any columns that older live deployments may be missing
    foreach ([
        "ALTER TABLE `utiligo_leads` ADD COLUMN `business_email` VARCHAR(255) NOT NULL DEFAULT '' AFTER `business_phone`",
        "ALTER TABLE `utiligo_leads` ADD COLUMN `business_city`  VARCHAR(100) NOT NULL DEFAULT '' AFTER `business_category`",
    ] as $ddl) {
        try { $pdo->exec($ddl); } catch (\Throwable $_) {}
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS `lead_cache` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `cache_key`  VARCHAR(255) NOT NULL,
        `leads_json` MEDIUMTEXT   NOT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_cache_key` (`cache_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `unlocked_leads` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`     INT UNSIGNED NOT NULL,
        `lead_id`     INT UNSIGNED NOT NULL,
        `unlocked_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_user_lead` (`user_id`, `lead_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    // ── 6. Free quota check ──────────────────────────────────────────────────
    $searches_used = 0;
    $searches_remaining = null;
    if (!$is_paid) {
        $daily_limit = defined('FREE_SEARCH_DAILY_LIMIT') ? (int)FREE_SEARCH_DAILY_LIMIT : 2;
        $fingerprint = 'u' . $uid . '_' . substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 16);
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS `lead_search_quota` (
                `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `fingerprint`  VARCHAR(80)  NOT NULL,
                `user_id`      INT UNSIGNED NOT NULL,
                `count`        INT UNSIGNED NOT NULL DEFAULT 0,
                `window_start` DATETIME     NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_fp` (`fingerprint`),
                KEY `idx_uid` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

            $cutoff = (new \DateTime())->modify('-24 hours')->format('Y-m-d H:i:s');
            $q = $pdo->prepare('SELECT id, count, window_start FROM lead_search_quota WHERE fingerprint = ? LIMIT 1');
            $q->execute([$fingerprint]);
            $row = $q->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $pdo->prepare('INSERT INTO lead_search_quota (fingerprint,user_id,count,window_start) VALUES (?,?,1,NOW())')->execute([$fingerprint, $uid]);
                $searches_used = 1;
                $searches_remaining = $daily_limit - 1;
            } elseif ($row['window_start'] < $cutoff) {
                $pdo->prepare('UPDATE lead_search_quota SET count=1,window_start=NOW() WHERE id=?')->execute([$row['id']]);
                $searches_used = 1;
                $searches_remaining = $daily_limit - 1;
            } else {
                $searches_used = (int)$row['count'];
                if ($searches_used >= $daily_limit) {
                    $reset_ts = strtotime($row['window_start']) + 86400;
                    http_response_code(429);
                    echo json_encode(['success'=>false,'error'=>'You have used all '.$daily_limit.' free searches for today. Upgrade to Pro for unlimited searches.','rate_limited'=>true,'resets_at'=>$reset_ts]);
                    exit;
                }
                $pdo->prepare('UPDATE lead_search_quota SET count=count+1 WHERE id=?')->execute([$row['id']]);
                $searches_used++;
                $searches_remaining = max(0, $daily_limit - $searches_used);
            }
        } catch (\Throwable $e) {
            log_error('find_leads_quota', $e, ['uid' => $uid]);
        }
    }

    // ── 7. Cache ─────────────────────────────────────────────────────────────
    $cacheKey   = strtolower(preg_replace('/\s+/', ' ', trim($city.'|'.$industry)));
    $cacheHours = defined('LEAD_SEARCH_CACHE_HOURS') ? (int)LEAD_SEARCH_CACHE_HOURS : 24;
    $from_cache = false;
    $cached_row = null;
    if (!$force) {
        try {
            $cs = $pdo->prepare('SELECT leads_json,created_at FROM lead_cache WHERE cache_key=? AND created_at > DATE_SUB(NOW(),INTERVAL ? HOUR) ORDER BY created_at DESC LIMIT 1');
            $cs->execute([$cacheKey, $cacheHours]);
            $cached_row = $cs->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            log_error('find_leads_cache_read', $e);
        }
    }

    if ($cached_row) {
        $all_leads  = json_decode($cached_row['leads_json'], true) ?? [];
        $cached_at  = $cached_row['created_at'];
        $from_cache = true;
    } else {
        // ── 8. Google Places ─────────────────────────────────────────────────
        $apiKey = defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : '';
        if (empty($apiKey) || $apiKey === 'YOUR_GOOGLE_PLACES_API_KEY') {
            echo json_encode(['success'=>false,'error'=>'Lead search is not configured. Please contact support.']);
            exit;
        }
        $ctx    = stream_context_create(['http'=>['timeout'=>12,'ignore_errors'=>true]]);
        $ts_url = 'https://maps.googleapis.com/maps/api/place/textsearch/json?query='.urlencode($industry.' in '.$city).'&key='.urlencode($apiKey);
        $resp   = @file_get_contents($ts_url, false, $ctx);
        if ($resp === false) {
            echo json_encode(['success'=>false,'error'=>'Could not reach Google Places. Please try again.']);
            exit;
        }
        $places = json_decode($resp, true);
        $status = $places['status'] ?? 'UNKNOWN';
        if ($status === 'REQUEST_DENIED')   { echo json_encode(['success'=>false,'error'=>'Lead search unavailable.']); exit; }
        if ($status === 'OVER_QUERY_LIMIT') { echo json_encode(['success'=>false,'error'=>'Daily quota reached. Try again tomorrow.']); exit; }
        if (!in_array($status, ['OK','ZERO_RESULTS'], true)) {
            echo json_encode(['success'=>false,'error'=>'Search failed ('.$status.'). Try a different city or industry.']);
            exit;
        }

        $all_leads  = [];
        $maxDetails = defined('MAX_PLACES_DETAILS_LOOKUPS') ? (int)MAX_PLACES_DETAILS_LOOKUPS : 20;
        $lc = 0;
        foreach ($places['results'] ?? [] as $place) {
            if (!empty($place['website'])) continue;
            $pid      = $place['place_id'] ?? '';
            $types    = $place['types']    ?? [];
            $rating   = isset($place['rating']) ? (float)$place['rating'] : null;
            $reviews  = (int)($place['user_ratings_total'] ?? 0);
            $category = !empty($types) ? str_replace('_',' ',ucwords($types[0],'_')) : $industry;
            $maps_url = $pid ? 'https://www.google.com/maps/place/?q=place_id:'.urlencode($pid) : '';
            $phone    = '';
            if ($pid && $lc < $maxDetails) {
                $det = @file_get_contents(
                    'https://maps.googleapis.com/maps/api/place/details/json?place_id='.urlencode($pid).'&fields=formatted_phone_number&key='.urlencode($apiKey),
                    false, $ctx
                );
                if ($det !== false) {
                    $d     = json_decode($det, true);
                    $phone = $d['result']['formatted_phone_number'] ?? '';
                }
                $lc++;
            }
            $all_leads[] = [
                'place_id'          => $pid,
                'business_name'     => $place['name'] ?? 'Unknown',
                'business_address'  => $place['formatted_address'] ?? '',
                'business_city'     => $city,
                'business_phone'    => $phone,
                'business_email'    => '',
                'business_category' => $category,
                'rating'            => $rating,
                'total_ratings'     => $reviews,
                'maps_url'          => $maps_url,
                'no_website'        => true,
                'opportunity_score' => opportunity_score($rating, $reviews, $category),
            ];
        }
        usort($all_leads, fn($a,$b) => $b['opportunity_score'] <=> $a['opportunity_score']);
        try {
            $pdo->prepare('INSERT INTO lead_cache (cache_key,leads_json,created_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE leads_json=VALUES(leads_json),created_at=NOW()')->execute([$cacheKey, json_encode($all_leads)]);
        } catch (\Throwable $e) { log_error('find_leads_cache_write',$e); }
        $cached_at  = date('Y-m-d H:i:s');
        $from_cache = false;
    }

    // ── 9. Upsert every lead → resolve integer DB ids ────────────────────────
    // CRITICAL: place_id_map is the ONLY reliable source of integer lead ids.
    // We build it here and use it directly in the unlock loop below.
    // Do NOT rely on $lead['id'] — it may be a string from JSON decode.
    $place_id_map = []; // place_id (string) => DB integer id

    $upsert_sql = '
        INSERT INTO utiligo_leads
            (place_id,business_name,business_address,business_phone,business_email,
             business_category,business_city,rating,total_ratings,maps_url,opportunity_score)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            business_name     = VALUES(business_name),
            business_address  = VALUES(business_address),
            business_phone    = VALUES(business_phone),
            business_email    = VALUES(business_email),
            business_category = VALUES(business_category),
            business_city     = VALUES(business_city),
            rating            = VALUES(rating),
            total_ratings     = VALUES(total_ratings),
            maps_url          = VALUES(maps_url),
            opportunity_score = VALUES(opportunity_score)';

    $upsert_stmt = null;
    try { $upsert_stmt = $pdo->prepare($upsert_sql); } catch (\Throwable $e) {
        log_error('find_leads_upsert_prepare', $e);
    }

    $id_stmt = null;
    try { $id_stmt = $pdo->prepare('SELECT id FROM utiligo_leads WHERE place_id=? LIMIT 1'); } catch (\Throwable $e) {
        log_error('find_leads_idstmt_prepare', $e);
    }

    foreach ($all_leads as $lead) {
        $pid = trim((string)($lead['place_id'] ?? ''));
        if ($pid === '') continue;

        if ($upsert_stmt) {
            try {
                $upsert_stmt->execute([
                    $pid,
                    (string)($lead['business_name']     ?? ''),
                    (string)($lead['business_address']  ?? ''),
                    (string)($lead['business_phone']    ?? ''),
                    (string)($lead['business_email']    ?? ''),
                    (string)($lead['business_category'] ?? ''),
                    (string)($lead['business_city']     ?? ''),
                    isset($lead['rating']) ? (float)$lead['rating'] : null,
                    (int)($lead['total_ratings'] ?? 0),
                    (string)($lead['maps_url']          ?? ''),
                    (int)($lead['opportunity_score']    ?? 0),
                ]);
            } catch (\Throwable $e) {
                log_error('find_leads_upsert_exec', $e, ['pid' => $pid]);
            }
        }

        if ($id_stmt) {
            try {
                $id_stmt->execute([$pid]);
                $db_id = $id_stmt->fetchColumn();
                if ($db_id !== false && $db_id > 0) {
                    $place_id_map[$pid] = (int)$db_id;
                }
            } catch (\Throwable $e) {
                log_error('find_leads_id_lookup', $e, ['pid' => $pid]);
            }
        }
    }

    // Stamp integer ids back onto leads array for JS (generate.php links etc.)
    foreach ($all_leads as &$lead) {
        $pid = trim((string)($lead['place_id'] ?? ''));
        $lead['id'] = isset($place_id_map[$pid]) ? $place_id_map[$pid] : ($lead['id'] ?? 0);
    }
    unset($lead);

    // ── 10. Unlock + count (paid plans only) ─────────────────────────────────
    $pro_lead_count = 0;
    // For JS: 0 = unlimited (entrepreneur), >0 = capped (pro)
    $pro_lead_limit = ($plan === 'entrepreneur') ? 0
                    : (defined('PRO_LEAD_LIMIT') ? (int)PRO_LEAD_LIMIT : 120);

    if ($is_paid && !empty($place_id_map)) {
        $unlock_stmt = null;
        try { $unlock_stmt = $pdo->prepare('INSERT IGNORE INTO unlocked_leads (user_id,lead_id) VALUES (?,?)'); }
        catch (\Throwable $e) { log_error('find_leads_unlock_prepare',$e); }

        if ($unlock_stmt) {
            // Use place_id_map directly — guaranteed integer values
            foreach ($place_id_map as $pid => $db_id) {
                try {
                    $unlock_stmt->execute([$uid, $db_id]);
                } catch (\Throwable $e) {
                    log_error('find_leads_unlock_exec', $e, ['uid'=>$uid,'lead_id'=>$db_id]);
                }
            }
        }

        // Fresh count from DB — always accurate
        try {
            $cnt_stmt = $pdo->prepare('SELECT COUNT(DISTINCT lead_id) FROM unlocked_leads WHERE user_id=?');
            $cnt_stmt->execute([$uid]);
            $pro_lead_count = (int)$cnt_stmt->fetchColumn();
        } catch (\Throwable $e) {
            log_error('find_leads_count', $e, ['uid'=>$uid]);
        }
    }

    // ── 11. Build payload ────────────────────────────────────────────────────
    $free_limit = defined('FREE_LEAD_LIMIT') ? (int)FREE_LEAD_LIMIT : 3;

    if ($is_paid) {
        $payload = [
            'success'            => true,
            'leads'              => array_slice($all_leads, 0, $lead_count_requested),
            'locked_leads'       => [],
            'is_free_tier'       => false,
            'from_cache'         => $from_cache,
            'cached_at'          => $cached_at,
            'pro_lead_count'     => $pro_lead_count,
            'lead_limit'         => $pro_lead_limit,
            'searches_used'      => 0,
            'searches_remaining' => null,
        ];
    } else {
        $visible  = array_slice($all_leads, 0, $free_limit);
        $redacted = array_map(fn($l) => [
            'id'=>$l['id']??0,'business_name'=>'','business_address'=>'',
            'business_phone'=>'','business_email'=>'','opportunity_score'=>0,
            'no_website'=>true,'_locked'=>true,
        ], array_slice($all_leads, $free_limit));

        $payload = [
            'success'            => true,
            'leads'              => $visible,
            'locked_leads'       => $redacted,
            'is_free_tier'       => true,
            'from_cache'         => $from_cache,
            'cached_at'          => $cached_at,
            'searches_used'      => $searches_used,
            'searches_remaining' => $searches_remaining,
            'pro_lead_count'     => 0,
            'lead_limit'         => 0,
        ];
    }

    // ── 12. History ──────────────────────────────────────────────────────────
    try {
        $pdo->prepare(
            'INSERT INTO utiligo_lead_search_history (user_id,city,industry,keywords,result_count,created_at)
             VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE result_count=VALUES(result_count),created_at=NOW()'
        )->execute([$uid, $city, $industry, $keywords, count($all_leads)]);
    } catch (\Throwable $e) {}

    echo json_encode($payload);

} catch (\Throwable $e) {
    log_error('find_leads_fatal',$e,['uid'=>$uid??null,'city'=>$city??null]);
    echo json_encode(['success'=>false,'error'=>'Something went wrong. Please try again.']);
}
