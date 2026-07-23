<?php
/**
 * api/find-leads.php  v3
 *
 * FIXES vs v2:
 *  1. pro_lead_count fetched UNCONDITIONALLY for paid users — was previously
 *     gated on !empty($place_id_map) so if upsert failed the bar showed 0.
 *  2. Upsert no longer touches `updated_at` column — avoids strict-mode
 *     issues on InfinityFree MySQL where ON UPDATE CURRENT_TIMESTAMP clashes.
 *  3. Unlock INSERT runs per lead individually inside its own try/catch so
 *     one failure doesn’t abort the rest.
 *  4. place_id_map resolution now uses INSERT ... ON DUPLICATE KEY UPDATE id=id
 *     (no-op update) so LAST_INSERT_ID() is reliable via PDO lastInsertId().
 *  5. _unlock_debug key returned in payload (non-sensitive) for diagnostics.
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

// ── 2. Rate limit ──────────────────────────────────────────────────────────
if (!rate_limit_check('find_leads', RATE_LIMIT_FIND_LEADS)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please wait a moment.']);
    exit;
}

// ── 3. Parse body ──────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request body.']);
    exit;
}
$city                 = trim($body['city']      ?? '');
$industry             = trim($body['industry']  ?? '');
$keywords             = trim($body['keywords']  ?? '');
$force                = !empty($body['force_refresh']);
$lead_count_requested = max(1, min(40, (int)($body['lead_count'] ?? 10)));

// ── 4. CSRF ──────────────────────────────────────────────────────────────────
if (!csrf_verify($body['csrf_token'] ?? null)) {
    http_response_code(403);
    log_error('find_leads_csrf', 'CSRF mismatch', ['uid' => $uid]);
    echo json_encode(['success' => false, 'error' => 'Security check failed. Please refresh the page.']);
    exit;
}

// ── 5. Validate inputs ───────────────────────────────────────────────────────
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

// ═══════════════════════════════════════════════════════════════════════════
try {
    $pdo = get_platform_db();

    // ── 6. Ensure tables exist ─────────────────────────────────────────────────
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `utiligo_leads` (
            `id`                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `place_id`          VARCHAR(255)     NOT NULL,
            `business_name`     VARCHAR(255)     NOT NULL DEFAULT \'\',
            `business_address`  VARCHAR(500)     NOT NULL DEFAULT \'\',
            `business_phone`    VARCHAR(80)      NOT NULL DEFAULT \'\',
            `business_email`    VARCHAR(255)     NOT NULL DEFAULT \'\',
            `business_category` VARCHAR(150)     NOT NULL DEFAULT \'\',
            `business_city`     VARCHAR(100)     NOT NULL DEFAULT \'\',
            `rating`            DECIMAL(3,1)     NULL,
            `total_ratings`     INT UNSIGNED     NOT NULL DEFAULT 0,
            `maps_url`          VARCHAR(500)     NOT NULL DEFAULT \'\',
            `opportunity_score` INT UNSIGNED     NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_place_id` (`place_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // Safely add columns that may be missing on older deployments
    foreach ([
        "ALTER TABLE `utiligo_leads` ADD COLUMN `business_email` VARCHAR(255) NOT NULL DEFAULT '' AFTER `business_phone`",
        "ALTER TABLE `utiligo_leads` ADD COLUMN `business_city`  VARCHAR(100) NOT NULL DEFAULT '' AFTER `business_category`",
    ] as $_ddl) {
        try { $pdo->exec($_ddl); } catch (\Throwable $_) {}
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `lead_cache` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cache_key`  VARCHAR(255) NOT NULL,
            `leads_json` MEDIUMTEXT   NOT NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_cache_key` (`cache_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `unlocked_leads` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`     INT UNSIGNED NOT NULL,
            `lead_id`     INT UNSIGNED NOT NULL,
            `unlocked_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_lead` (`user_id`,`lead_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `utiligo_lead_search_history` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    // ── 7. Free-tier daily quota ───────────────────────────────────────────────
    $searches_used      = 0;
    $searches_remaining = null;
    if (!$is_paid) {
        $daily_limit = (int)FREE_SEARCH_DAILY_LIMIT;
        $fingerprint = 'u' . $uid . '_' . substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 16);
        try {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS `lead_search_quota` (
                    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `fingerprint`  VARCHAR(80)  NOT NULL,
                    `user_id`      INT UNSIGNED NOT NULL,
                    `count`        INT UNSIGNED NOT NULL DEFAULT 0,
                    `window_start` DATETIME     NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_fp` (`fingerprint`),
                    KEY `idx_uid` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
            $q      = $pdo->prepare('SELECT id,count,window_start FROM lead_search_quota WHERE fingerprint=? LIMIT 1');
            $q->execute([$fingerprint]);
            $qrow = $q->fetch(PDO::FETCH_ASSOC);
            if (!$qrow) {
                $pdo->prepare('INSERT INTO lead_search_quota (fingerprint,user_id,count,window_start) VALUES (?,?,1,NOW())')->execute([$fingerprint,$uid]);
                $searches_used = 1; $searches_remaining = $daily_limit - 1;
            } elseif ($qrow['window_start'] < $cutoff) {
                $pdo->prepare('UPDATE lead_search_quota SET count=1,window_start=NOW() WHERE id=?')->execute([$qrow['id']]);
                $searches_used = 1; $searches_remaining = $daily_limit - 1;
            } else {
                $searches_used = (int)$qrow['count'];
                if ($searches_used >= $daily_limit) {
                    $reset_ts = strtotime($qrow['window_start']) + 86400;
                    http_response_code(429);
                    echo json_encode(['success'=>false,'error'=>'You have used all '.$daily_limit.' free searches for today. Upgrade to Pro for unlimited searches.','rate_limited'=>true,'resets_at'=>$reset_ts]);
                    exit;
                }
                $pdo->prepare('UPDATE lead_search_quota SET count=count+1 WHERE id=?')->execute([$qrow['id']]);
                $searches_used++;
                $searches_remaining = max(0, $daily_limit - $searches_used);
            }
        } catch (\Throwable $e) { log_error('find_leads_quota', $e, ['uid'=>$uid]); }
    }

    // ── 8. Cache lookup ─────────────────────────────────────────────────────────
    $cache_key  = strtolower(preg_replace('/\s+/', ' ', $city . '|' . $industry));
    $cache_hrs  = (int)LEAD_SEARCH_CACHE_HOURS;
    $from_cache = false;
    $cached_at  = date('Y-m-d H:i:s');
    $all_leads  = [];

    if (!$force) {
        try {
            $cs = $pdo->prepare(
                'SELECT leads_json,created_at FROM lead_cache
                 WHERE cache_key=? AND created_at > DATE_SUB(NOW(),INTERVAL ? HOUR)
                 ORDER BY created_at DESC LIMIT 1'
            );
            $cs->execute([$cache_key, $cache_hrs]);
            $crow = $cs->fetch(PDO::FETCH_ASSOC);
            if ($crow) {
                $all_leads  = json_decode($crow['leads_json'], true) ?? [];
                $cached_at  = $crow['created_at'];
                $from_cache = true;
            }
        } catch (\Throwable $e) { log_error('find_leads_cache_read', $e); }
    }

    // ── 9. Google Places (only when not cached) ──────────────────────────────
    if (!$from_cache) {
        $api_key = GOOGLE_PLACES_API_KEY;
        if (empty($api_key) || $api_key === 'YOUR_GOOGLE_PLACES_API_KEY') {
            echo json_encode(['success'=>false,'error'=>'Lead search is not configured.']);
            exit;
        }
        $ctx    = stream_context_create(['http'=>['timeout'=>12,'ignore_errors'=>true]]);
        $ts_url = 'https://maps.googleapis.com/maps/api/place/textsearch/json'
                . '?query=' . urlencode($industry . ' in ' . $city)
                . '&key='   . urlencode($api_key);
        $resp = @file_get_contents($ts_url, false, $ctx);
        if ($resp === false) {
            echo json_encode(['success'=>false,'error'=>'Could not reach Google Places. Please try again.']);
            exit;
        }
        $places = json_decode($resp, true);
        $status = $places['status'] ?? 'UNKNOWN';
        if ($status === 'REQUEST_DENIED')   { echo json_encode(['success'=>false,'error'=>'Lead search unavailable.']); exit; }
        if ($status === 'OVER_QUERY_LIMIT') { echo json_encode(['success'=>false,'error'=>'Daily quota reached. Try again tomorrow.']); exit; }
        if (!in_array($status, ['OK','ZERO_RESULTS'], true)) {
            echo json_encode(['success'=>false,'error'=>'Search failed ('.$status.'). Try a different city or industry.']); exit;
        }

        $max_det = (int)MAX_PLACES_DETAILS_LOOKUPS;
        $det_cnt = 0;
        foreach ($places['results'] ?? [] as $place) {
            if (!empty($place['website'])) continue;
            $pid      = $place['place_id'] ?? '';
            $types    = $place['types']    ?? [];
            $rating   = isset($place['rating']) ? (float)$place['rating'] : null;
            $reviews  = (int)($place['user_ratings_total'] ?? 0);
            $category = $types ? str_replace('_', ' ', ucwords($types[0], '_')) : $industry;
            $maps_url = $pid ? 'https://www.google.com/maps/place/?q=place_id:' . urlencode($pid) : '';
            $phone    = '';
            if ($pid && $det_cnt < $max_det) {
                $det = @file_get_contents(
                    'https://maps.googleapis.com/maps/api/place/details/json?place_id='
                    . urlencode($pid) . '&fields=formatted_phone_number&key=' . urlencode($api_key),
                    false, $ctx
                );
                if ($det !== false) {
                    $dj    = json_decode($det, true);
                    $phone = $dj['result']['formatted_phone_number'] ?? '';
                }
                $det_cnt++;
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
        usort($all_leads, fn($a, $b) => $b['opportunity_score'] <=> $a['opportunity_score']);
        try {
            $pdo->prepare(
                'INSERT INTO lead_cache (cache_key,leads_json,created_at) VALUES (?,?,NOW())
                 ON DUPLICATE KEY UPDATE leads_json=VALUES(leads_json),created_at=NOW()'
            )->execute([$cache_key, json_encode($all_leads)]);
        } catch (\Throwable $e) { log_error('find_leads_cache_write', $e); }
        $cached_at = date('Y-m-d H:i:s');
    }

    // ── 10. Upsert leads into utiligo_leads + build place_id → db_id map ─────────
    //
    // Strategy: INSERT IGNORE (no conflicting update) then SELECT id.
    // INSERT IGNORE is safest on InfinityFree strict mode — it never
    // triggers the ON UPDATE CURRENT_TIMESTAMP issue and never fails on
    // duplicate place_id. We then SELECT to get the id regardless.
    // ───────────────────────────────────────────────────────────────────────────
    $place_id_map  = [];  // place_id => integer db id
    $upsert_errors = [];
    $id_errors     = [];

    // Prepare INSERT IGNORE (never fails on dup, never touches updated_at)
    $ins = null;
    try {
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO `utiligo_leads`
                (place_id,business_name,business_address,business_phone,business_email,
                 business_category,business_city,rating,total_ratings,maps_url,opportunity_score)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );
    } catch (\Throwable $e) {
        log_error('find_leads_ins_prepare', $e);
        $upsert_errors[] = $e->getMessage();
    }

    // Prepare SELECT id
    $sel = null;
    try {
        $sel = $pdo->prepare('SELECT id FROM `utiligo_leads` WHERE place_id=? LIMIT 1');
    } catch (\Throwable $e) {
        log_error('find_leads_sel_prepare', $e);
        $id_errors[] = $e->getMessage();
    }

    foreach ($all_leads as $lead) {
        $pid = trim((string)($lead['place_id'] ?? ''));
        if ($pid === '') continue;

        // INSERT IGNORE — safe on dups, safe on strict mode
        if ($ins) {
            try {
                $ins->execute([
                    $pid,
                    (string)($lead['business_name']     ?? ''),
                    (string)($lead['business_address']  ?? ''),
                    (string)($lead['business_phone']    ?? ''),
                    (string)($lead['business_email']    ?? ''),
                    (string)($lead['business_category'] ?? ''),
                    (string)($lead['business_city']     ?? ''),
                    isset($lead['rating']) ? (float)$lead['rating'] : null,
                    (int)($lead['total_ratings']        ?? 0),
                    (string)($lead['maps_url']          ?? ''),
                    (int)($lead['opportunity_score']    ?? 0),
                ]);
            } catch (\Throwable $e) {
                log_error('find_leads_ins_exec', $e, ['pid'=>$pid]);
                $upsert_errors[] = substr($e->getMessage(), 0, 80);
            }
        }

        // Always SELECT to get id (works whether INSERT fired or was ignored)
        if ($sel) {
            try {
                $sel->execute([$pid]);
                $db_id = $sel->fetchColumn();
                if ($db_id !== false && (int)$db_id > 0) {
                    $place_id_map[$pid] = (int)$db_id;
                }
            } catch (\Throwable $e) {
                log_error('find_leads_sel_exec', $e, ['pid'=>$pid]);
                $id_errors[] = substr($e->getMessage(), 0, 80);
            }
        }
    }

    // Stamp integer ids back onto $all_leads for JS
    foreach ($all_leads as &$_lead) {
        $pid = trim((string)($_lead['place_id'] ?? ''));
        $_lead['id'] = $place_id_map[$pid] ?? 0;
    }
    unset($_lead);

    // ── 11. Unlock leads + count ─────────────────────────────────────────────────
    //
    // CRITICAL: pro_lead_count is fetched UNCONDITIONALLY for paid users.
    // Even if place_id_map is empty (upsert failed entirely), we still
    // return the current count so the bar stays accurate.
    // ───────────────────────────────────────────────────────────────────────────
    $pro_lead_count  = 0;
    $pro_lead_limit  = ($plan === 'entrepreneur') ? 0 : (int)PRO_LEAD_LIMIT;
    $unlock_errors   = [];
    $unlocks_attempted = 0;
    $unlocks_inserted  = 0;

    if ($is_paid) {
        // Step A: insert unlocks for every resolved lead id
        if (!empty($place_id_map)) {
            $ul = null;
            try {
                $ul = $pdo->prepare('INSERT IGNORE INTO unlocked_leads (user_id,lead_id) VALUES (?,?)');
            } catch (\Throwable $e) {
                log_error('find_leads_unlock_prepare', $e);
                $unlock_errors[] = $e->getMessage();
            }

            if ($ul) {
                foreach ($place_id_map as $_pid => $db_id) {
                    $unlocks_attempted++;
                    try {
                        $ul->execute([$uid, $db_id]);
                        $unlocks_inserted += (int)$pdo->lastInsertId() > 0 ? 1 : 0;
                    } catch (\Throwable $e) {
                        log_error('find_leads_unlock_exec', $e, ['uid'=>$uid,'lead_id'=>$db_id]);
                        $unlock_errors[] = substr($e->getMessage(), 0, 80);
                    }
                }
            }
        }

        // Step B: count ALWAYS runs, no matter what happened above
        try {
            $cnt = $pdo->prepare('SELECT COUNT(DISTINCT lead_id) FROM unlocked_leads WHERE user_id=?');
            $cnt->execute([$uid]);
            $pro_lead_count = (int)$cnt->fetchColumn();
        } catch (\Throwable $e) {
            log_error('find_leads_count', $e, ['uid'=>$uid]);
            $unlock_errors[] = 'count_failed: ' . substr($e->getMessage(), 0, 80);
        }
    }

    // ── 12. Search history ────────────────────────────────────────────────────────
    try {
        $pdo->prepare(
            'INSERT INTO utiligo_lead_search_history
                (user_id,city,industry,keywords,result_count,created_at)
             VALUES (?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE result_count=VALUES(result_count),created_at=NOW()'
        )->execute([$uid, $city, $industry, $keywords, count($all_leads)]);
    } catch (\Throwable $e) { /* non-fatal */ }

    // ── 13. Build + return payload ───────────────────────────────────────────────
    $free_limit = (int)FREE_LEAD_LIMIT;

    if ($is_paid) {
        $payload = [
            'success'          => true,
            'leads'            => array_slice($all_leads, 0, $lead_count_requested),
            'locked_leads'     => [],
            'is_free_tier'     => false,
            'from_cache'       => $from_cache,
            'cached_at'        => $cached_at,
            'pro_lead_count'   => $pro_lead_count,
            'lead_limit'       => $pro_lead_limit,
            'searches_used'    => 0,
            'searches_remaining' => null,
            // Diagnostic fields — helpful for debugging, harmless in prod
            '_unlock_debug' => [
                'map_size'          => count($place_id_map),
                'unlocks_attempted' => $unlocks_attempted,
                'unlocks_inserted'  => $unlocks_inserted,
                'unlock_errors'     => $unlock_errors,
                'upsert_errors'     => $upsert_errors,
                'id_errors'         => $id_errors,
                'total_leads_raw'   => count($all_leads),
            ],
        ];
    } else {
        $visible  = array_slice($all_leads, 0, $free_limit);
        $redacted = array_map(fn($l) => [
            'id'               => $l['id'] ?? 0,
            'business_name'    => '',
            'business_address' => '',
            'business_phone'   => '',
            'business_email'   => '',
            'opportunity_score'=> 0,
            'no_website'       => true,
            '_locked'          => true,
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

    echo json_encode($payload);

} catch (\Throwable $e) {
    log_error('find_leads_fatal', $e, ['uid' => $uid ?? null, 'city' => $city ?? null]);
    echo json_encode(['success' => false, 'error' => 'Something went wrong. Please try again.']);
}
