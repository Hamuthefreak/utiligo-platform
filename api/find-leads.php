<?php
/**
 * api/find-leads.php  v5.4
 *
 * CHANGES FROM v5.3
 * =================
 * Reliability & observability hardening:
 *
 * 1. Dedicated leads logger — every stage calls leads_log_*() from
 *    includes/leads_logger.php. All lead activity isolated in
 *    storage/leads_log.txt, separate from error_log.txt.
 *
 * 2. DB connection retry — up to 3 attempts with 250 ms back-off.
 *
 * 3. Google Places timeout raised to 15 s; all status codes classified
 *    (INVALID_REQUEST, OVER_QUERY_LIMIT, REQUEST_DENIED, etc.).
 *
 * 4. Phone-detail lookup is individually try/caught — one slow call
 *    cannot hang the whole request.
 *
 * 5. Cache read/write failures logged as warn (not silently swallowed).
 *
 * 6. ID-resolution failures counted + logged; zero-id leads are skipped
 *    in the unlock loop rather than silently ignored.
 *
 * 7. Per-stage timing (google_ms, db_ms, unlock_ms, total_ms) in _debug.
 *
 * 8. Audit info entry always written on success — leads_log.txt doubles
 *    as a usage trail.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/leads_logger.php';

api_bootstrap();
header('Content-Type: application/json');
$_t_start = microtime(true);

// ── helpers ───────────────────────────────────────────────────────────────
function _leads_ms(float $since): int { return (int)round((microtime(true) - $since) * 1000); }
function _leads_json_fail(string $msg, int $code = 200, array $extra = []): never {
    if ($code !== 200) http_response_code($code);
    echo json_encode(array_merge(['success'=>false,'error'=>$msg], $extra));
    exit;
}

// ── 1. Auth ───────────────────────────────────────────────────────────────
if (!is_logged_in()) {
    leads_log_warn('auth_fail', ['reason'=>'not_logged_in']);
    _leads_json_fail('Not logged in.', 401);
}
$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro','entrepreneur'], true);
$is_ent  = $plan === 'entrepreneur';
$uid     = (int)$user['id'];

// ── 2. Rate limit ─────────────────────────────────────────────────────────
if (!rate_limit_check('find_leads', (int)RATE_LIMIT_FIND_LEADS)) {
    leads_log_warn('rate_limited', ['uid'=>$uid, 'plan'=>$plan]);
    _leads_json_fail('Too many requests. Please wait a moment.', 429);
}

// ── 3. Parse + validate ───────────────────────────────────────────────────
$raw_input = file_get_contents('php://input');
$body = json_decode($raw_input, true);
if (!is_array($body)) {
    leads_log_warn('bad_request', ['raw'=>substr($raw_input, 0, 120)]);
    _leads_json_fail('Invalid request.', 400);
}
$city      = trim((string)($body['city']      ?? ''));
$industry  = trim((string)($body['industry']  ?? ''));
$keywords  = trim((string)($body['keywords']  ?? ''));
$force     = !empty($body['force_refresh']);
$req_count = max(1, min(40, (int)($body['lead_count'] ?? 10)));

if (!csrf_verify($body['csrf_token'] ?? null)) {
    leads_log_warn('csrf_fail', ['uid'=>$uid]);
    _leads_json_fail('Security check failed. Please refresh the page.', 403);
}
if ($city === '' || $industry === '')                                        _leads_json_fail('City and industry are required.');
if (strlen($city) > 100 || strlen($industry) > 100)                        _leads_json_fail('Input too long.');
if (!preg_match('/^[\p{L}0-9 \-\',.]+$/u',   $city) ||
    !preg_match('/^[\p{L}0-9 \-\',.&]+$/u', $industry))                   _leads_json_fail('Invalid characters in city or industry.');

// ── 4. DB connect (3 attempts, 250 ms back-off) ───────────────────────────
$pdo = null;
$_t_db = microtime(true);
for ($attempt = 1; $attempt <= 3; $attempt++) {
    try { $pdo = get_platform_db(); break; }
    catch (\Throwable $e) {
        leads_log_warn('db_connect_attempt', ['attempt'=>$attempt, 'error'=>$e->getMessage()]);
        if ($attempt < 3) usleep(250_000);
    }
}
if (!$pdo) {
    leads_log_error('db_connect_failed', ['uid'=>$uid]);
    log_error('find_leads_db_connect_failed', 'All 3 attempts failed', ['uid'=>$uid]);
    _leads_json_fail('Database unavailable. Please try again shortly.');
}
$_db_connect_ms = _leads_ms($_t_db);

// ── 5. Table bootstrap ────────────────────────────────────────────────────
$_tbl_errors = [];
foreach ([
    'utiligo_leads' => "
        CREATE TABLE IF NOT EXISTS `utiligo_leads` (
            `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `place_id`          VARCHAR(255)  NOT NULL,
            `business_name`     VARCHAR(255)  NOT NULL DEFAULT '',
            `business_address`  VARCHAR(500)  NOT NULL DEFAULT '',
            `business_phone`    VARCHAR(80)   NOT NULL DEFAULT '',
            `business_email`    VARCHAR(255)  NOT NULL DEFAULT '',
            `business_category` VARCHAR(150)  NOT NULL DEFAULT '',
            `business_city`     VARCHAR(100)  NOT NULL DEFAULT '',
            `rating`            DECIMAL(3,1)  NULL,
            `total_ratings`     INT UNSIGNED  NOT NULL DEFAULT 0,
            `maps_url`          VARCHAR(500)  NOT NULL DEFAULT '',
            `opportunity_score` INT UNSIGNED  NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_place_id` (`place_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'lead_cache' => "
        CREATE TABLE IF NOT EXISTS `lead_cache` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cache_key`  VARCHAR(255) NOT NULL,
            `leads_json` MEDIUMTEXT   NOT NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_cache_key` (`cache_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'unlocked_leads' => "
        CREATE TABLE IF NOT EXISTS `unlocked_leads` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`     INT UNSIGNED NOT NULL,
            `lead_id`     INT UNSIGNED NOT NULL,
            `unlocked_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_lead` (`user_id`,`lead_id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'utiligo_lead_search_history' => "
        CREATE TABLE IF NOT EXISTS `utiligo_lead_search_history` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`      INT UNSIGNED NOT NULL,
            `city`         VARCHAR(100) NOT NULL,
            `industry`     VARCHAR(100) NOT NULL,
            `keywords`     VARCHAR(255) NOT NULL DEFAULT '',
            `result_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_search` (`user_id`,`city`,`industry`,`keywords`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
] as $_tn => $_ts) {
    try { $pdo->exec($_ts); }
    catch (\Throwable $e) {
        leads_log_warn('table_bootstrap', $e, ['table'=>$_tn]);
        log_error('find_leads_create_'.$_tn, $e);
        $_tbl_errors[$_tn] = substr($e->getMessage(), 0, 200);
    }
}

function get_columns(PDO $pdo, string $table): array {
    try { $r = $pdo->query("DESCRIBE `{$table}`"); return array_column($r->fetchAll(PDO::FETCH_ASSOC), 'Field'); }
    catch (\Throwable $e) { return []; }
}
$_existing_cols = get_columns($pdo, 'utiligo_leads');
$_required_cols = [
    'business_email'    => "ALTER TABLE `utiligo_leads` ADD COLUMN `business_email`    VARCHAR(255) NOT NULL DEFAULT ''",
    'business_city'     => "ALTER TABLE `utiligo_leads` ADD COLUMN `business_city`     VARCHAR(100) NOT NULL DEFAULT ''",
    'business_category' => "ALTER TABLE `utiligo_leads` ADD COLUMN `business_category` VARCHAR(150) NOT NULL DEFAULT ''",
    'business_phone'    => "ALTER TABLE `utiligo_leads` ADD COLUMN `business_phone`    VARCHAR(80)  NOT NULL DEFAULT ''",
    'maps_url'          => "ALTER TABLE `utiligo_leads` ADD COLUMN `maps_url`          VARCHAR(500) NOT NULL DEFAULT ''",
    'opportunity_score' => "ALTER TABLE `utiligo_leads` ADD COLUMN `opportunity_score` INT UNSIGNED NOT NULL DEFAULT 0",
    'total_ratings'     => "ALTER TABLE `utiligo_leads` ADD COLUMN `total_ratings`     INT UNSIGNED NOT NULL DEFAULT 0",
    'rating'            => "ALTER TABLE `utiligo_leads` ADD COLUMN `rating`            DECIMAL(3,1) NULL",
];
$_alter_errors = [];
foreach ($_required_cols as $_col => $_ddl) {
    if (!in_array($_col, $_existing_cols, true)) {
        try { $pdo->exec($_ddl); }
        catch (\Throwable $e) {
            if (strpos($e->getMessage(), '1060') === false) {
                leads_log_warn('column_add', $e, ['col'=>$_col]);
                $_alter_errors[$_col] = substr($e->getMessage(), 0, 200);
            }
        }
    }
}
$_existing_cols = get_columns($pdo, 'utiligo_leads');

// ── 6. Pro lead-limit check ───────────────────────────────────────────────
$pro_lead_limit = plan_lead_limit($plan);
if ($is_paid && !$is_ent && $pro_lead_limit > 0) {
    try {
        $lc_check = $pdo->prepare('SELECT COUNT(DISTINCT lead_id) FROM unlocked_leads WHERE user_id=?');
        $lc_check->execute([$uid]);
        $current_lead_count = (int)$lc_check->fetchColumn();
        if ($current_lead_count >= $pro_lead_limit) {
            leads_log_info('limit_blocked', ['uid'=>$uid,'count'=>$current_lead_count,'limit'=>$pro_lead_limit]);
            echo json_encode(['success'=>false,'error'=>'You have reached your '.$pro_lead_limit.' lead limit on the Pro plan. Upgrade to Entrepreneur for unlimited leads.','limit_reached'=>true,'lead_count'=>$current_lead_count,'lead_limit'=>$pro_lead_limit]);
            exit;
        }
        $remaining = $pro_lead_limit - $current_lead_count;
        if ($req_count > $remaining) {
            leads_log_info('req_count_capped', ['uid'=>$uid,'from'=>$req_count,'to'=>$remaining]);
            $req_count = $remaining;
        }
    } catch (\Throwable $e) {
        leads_log_error('limit_check', $e, ['uid'=>$uid]);
        log_error('find_leads_limit_check', $e);
    }
}

// ── 7. Free quota ─────────────────────────────────────────────────────────
$searches_used = 0; $searches_remaining = null;
if (!$is_paid) {
    $daily_limit = (int)FREE_SEARCH_DAILY_LIMIT;
    $fingerprint = 'uid_' . $uid;
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS `lead_search_quota` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`fingerprint` VARCHAR(80) NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,`count` INT UNSIGNED NOT NULL DEFAULT 0,
            `window_start` DATETIME NOT NULL,PRIMARY KEY(`id`),
            UNIQUE KEY`uq_fp`(`fingerprint`),KEY`idx_uid`(`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $q = $pdo->prepare('SELECT id,count,window_start FROM lead_search_quota WHERE fingerprint=? LIMIT 1');
        $q->execute([$fingerprint]);
        $qrow = $q->fetch(PDO::FETCH_ASSOC);
        if (!$qrow) {
            $pdo->prepare('INSERT INTO lead_search_quota (fingerprint,user_id,count,window_start) VALUES(?,?,1,NOW())')->execute([$fingerprint,$uid]);
            $searches_used=1; $searches_remaining=$daily_limit-1;
        } elseif ($qrow['window_start'] < $cutoff) {
            $pdo->prepare('UPDATE lead_search_quota SET count=1,window_start=NOW() WHERE id=?')->execute([$qrow['id']]);
            $searches_used=1; $searches_remaining=$daily_limit-1;
        } else {
            $searches_used=(int)$qrow['count'];
            if ($searches_used>=$daily_limit) {
                leads_log_info('quota_exhausted', ['uid'=>$uid]);
                echo json_encode(['success'=>false,'error'=>'All '.$daily_limit.' free searches used today. Upgrade for unlimited.','rate_limited'=>true,'resets_at'=>strtotime($qrow['window_start'])+86400]);
                exit;
            }
            $pdo->prepare('UPDATE lead_search_quota SET count=count+1 WHERE id=?')->execute([$qrow['id']]);
            $searches_used++; $searches_remaining=max(0,$daily_limit-$searches_used);
        }
    } catch (\Throwable $e) {
        leads_log_error('quota_check', $e, ['uid'=>$uid]);
        log_error('find_leads_quota', $e, ['uid'=>$uid]);
    }
}

// ── 8. Cache ──────────────────────────────────────────────────────────────
$cache_key  = strtolower(preg_replace('/\s+/',' ',$city.'|'.$industry.'|'.$req_count));
$cache_hrs  = (int)LEAD_SEARCH_CACHE_HOURS;
$from_cache = false; $cached_at = date('Y-m-d H:i:s'); $all_leads = [];
if (!$force) {
    try {
        $cs = $pdo->prepare('SELECT leads_json,created_at FROM lead_cache WHERE cache_key=? AND created_at>DATE_SUB(NOW(),INTERVAL ? HOUR) ORDER BY created_at DESC LIMIT 1');
        $cs->execute([$cache_key,$cache_hrs]);
        $crow = $cs->fetch(PDO::FETCH_ASSOC);
        if ($crow) {
            $dec = json_decode($crow['leads_json'],true);
            if (is_array($dec) && count($dec)>0) {
                foreach ($dec as &$_cl) { unset($_cl['id']); } unset($_cl);
                $all_leads=$dec; $cached_at=$crow['created_at']; $from_cache=true;
                leads_log_info('cache_hit', ['key'=>$cache_key,'leads'=>count($dec)]);
            }
        } else {
            leads_log_debug('cache_miss', ['key'=>$cache_key]);
        }
    } catch (\Throwable $e) {
        leads_log_warn('cache_read', $e, ['key'=>$cache_key]);
        log_error('find_leads_cache_read', $e);
    }
}

// ── 9. Google Places — paginate up to 3 pages ────────────────────────────
$_t_google = microtime(true); $_google_ms = 0;
if (!$from_cache) {
    $api_key = defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : '';
    if (empty($api_key)||$api_key==='YOUR_GOOGLE_PLACES_API_KEY') {
        leads_log_error('google_api_key_missing', ['uid'=>$uid]);
        _leads_json_fail('Lead search not configured.');
    }
    $ctx = stream_context_create(['http'=>['timeout'=>15,'ignore_errors'=>true]]);
    $max_det=$det_cnt=0; $max_det=(int)MAX_PLACES_DETAILS_LOOKUPS;
    $next_token=null; $pages_fetched=0; $max_pages=3;

    do {
        if ($next_token) {
            sleep(2);
            $url='https://maps.googleapis.com/maps/api/place/textsearch/json?pagetoken='.urlencode($next_token).'&key='.urlencode($api_key);
        } else {
            $url='https://maps.googleapis.com/maps/api/place/textsearch/json?query='.urlencode($industry.' in '.$city).'&key='.urlencode($api_key);
        }
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp===false) {
            leads_log_error('google_http_fail', ['page'=>$pages_fetched+1,'uid'=>$uid]);
            _leads_json_fail('Could not reach Google Places. Try again.');
        }
        $places = json_decode($resp, true);
        $status = $places['status'] ?? 'UNKNOWN';
        leads_log_debug('google_page', ['page'=>$pages_fetched+1,'status'=>$status,'results'=>count($places['results']??[])]);
        if ($status==='REQUEST_DENIED')  { leads_log_error('google_denied',           ['uid'=>$uid]); _leads_json_fail('Google API key issue.'); }
        if ($status==='OVER_QUERY_LIMIT'){ leads_log_error('google_over_quota',       ['uid'=>$uid]); _leads_json_fail('Google daily quota reached.'); }
        if ($status==='INVALID_REQUEST') { leads_log_warn('google_invalid_request',   ['city'=>$city,'industry'=>$industry]); _leads_json_fail('Search query was invalid. Try different terms.'); }
        if (!in_array($status,['OK','ZERO_RESULTS'],true)) { leads_log_warn('google_unexpected_status',['status'=>$status]); _leads_json_fail('Search failed ('.$status.').'); }

        foreach ($places['results']??[] as $place) {
            if (!empty($place['website'])) continue;
            $pid      = (string)($place['place_id']??'');
            $types    = $place['types']??[];
            $rating   = isset($place['rating']) ? (float)$place['rating'] : null;
            $reviews  = (int)($place['user_ratings_total']??0);
            $category = $types ? str_replace('_',' ',ucwords($types[0],'_')) : $industry;
            $maps_url = $pid ? 'https://www.google.com/maps/place/?q=place_id:'.urlencode($pid) : '';
            $phone    = '';
            if ($pid && $det_cnt < $max_det) {
                try {
                    $det = @file_get_contents('https://maps.googleapis.com/maps/api/place/details/json?place_id='.urlencode($pid).'&fields=formatted_phone_number&key='.urlencode($api_key), false, $ctx);
                    if ($det!==false) { $dj=json_decode($det,true); $phone=(string)($dj['result']['formatted_phone_number']??''); }
                } catch (\Throwable $e) { leads_log_warn('phone_lookup',$e,['pid'=>$pid]); }
                $det_cnt++;
            }
            $all_leads[] = [
                'place_id'=>$pid,'business_name'=>(string)($place['name']??'Unknown'),
                'business_address'=>(string)($place['formatted_address']??''),'business_city'=>$city,
                'business_phone'=>$phone,'business_email'=>'','business_category'=>$category,
                'rating'=>$rating,'total_ratings'=>$reviews,'maps_url'=>$maps_url,'no_website'=>true,
                'opportunity_score'=>opportunity_score($rating,$reviews,$category),
            ];
        }
        $next_token=$places['next_page_token']??null;
        $pages_fetched++;
    } while ($next_token && $pages_fetched<$max_pages && count($all_leads)<$req_count);

    $_google_ms = _leads_ms($_t_google);
    leads_log_info('google_fetch',['city'=>$city,'industry'=>$industry,'pages'=>$pages_fetched,'raw_leads'=>count($all_leads),'ms'=>$_google_ms]);

    usort($all_leads, fn($a,$b)=>$b['opportunity_score']<=>$a['opportunity_score']);
    $cache_payload = array_map(function($l){$c=$l;unset($c['id']);return $c;},$all_leads);
    try {
        $pdo->prepare('INSERT INTO lead_cache (cache_key,leads_json,created_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE leads_json=VALUES(leads_json),created_at=NOW()')
            ->execute([$cache_key,json_encode($cache_payload)]);
    } catch(\Throwable $e){ leads_log_warn('cache_write',$e,['key'=>$cache_key]); log_error('cache_write',$e); }
    $cached_at=date('Y-m-d H:i:s');
}

// ── 10. INSERT IGNORE + SELECT id ─────────────────────────────────────────
$_t_db2=microtime(true); $place_id_map=[]; $_ins_exec_errs=[]; $_sel_exec_errs=[]; $_id_resolve_fails=0;
$_col_getters=[
    'place_id'          =>fn($l)=>trim((string)($l['place_id']??'')),
    'business_name'     =>fn($l)=>(string)($l['business_name']??''),
    'business_address'  =>fn($l)=>(string)($l['business_address']??''),
    'business_phone'    =>fn($l)=>(string)($l['business_phone']??''),
    'business_email'    =>fn($l)=>(string)($l['business_email']??''),
    'business_category' =>fn($l)=>(string)($l['business_category']??''),
    'business_city'     =>fn($l)=>(string)($l['business_city']??''),
    'rating'            =>fn($l)=>isset($l['rating'])?(float)$l['rating']:null,
    'total_ratings'     =>fn($l)=>(int)($l['total_ratings']??0),
    'maps_url'          =>fn($l)=>(string)($l['maps_url']??''),
    'opportunity_score' =>fn($l)=>(int)($l['opportunity_score']??0),
];
$_use_cols=array_values(array_filter(array_keys($_col_getters),fn($c)=>in_array($c,$_existing_cols,true)));
$_ins=null;
if (count($_use_cols)>=2) {
    $_col_list=implode(',',array_map(fn($c)=>"`{$c}`",$_use_cols));
    $_placeholders=implode(',',array_fill(0,count($_use_cols),'?'));
    try { $_ins=$pdo->prepare("INSERT IGNORE INTO `utiligo_leads` ({$_col_list}) VALUES ({$_placeholders})"); }
    catch(\Throwable $e){ leads_log_error('ins_prepare',$e); log_error('ins_prepare',$e); }
}
$_sel=null;
try { $_sel=$pdo->prepare('SELECT id FROM `utiligo_leads` WHERE place_id=? LIMIT 1'); }
catch(\Throwable $e){ leads_log_error('sel_prepare',$e); log_error('sel_prepare',$e); }

foreach ($all_leads as $lead) {
    $pid=trim((string)($lead['place_id']??'')); if($pid==='') continue;
    if ($_ins) {
        try { $_ins->execute(array_map(fn($c)=>$_col_getters[$c]($lead),$_use_cols)); }
        catch(\Throwable $e){ leads_log_warn('ins_exec',$e,['pid'=>$pid]); log_error('ins_exec',$e,['pid'=>$pid]); if(count($_ins_exec_errs)<5)$_ins_exec_errs[]=substr($e->getMessage(),0,200); }
    }
    if ($_sel) {
        try {
            $_sel->execute([$pid]); $db_id=$_sel->fetchColumn();
            if ($db_id!==false&&(int)$db_id>0) { $place_id_map[$pid]=(int)$db_id; }
            else { $_id_resolve_fails++; leads_log_warn('id_resolve_fail',['pid'=>$pid]); }
        } catch(\Throwable $e){ leads_log_warn('sel_exec',$e,['pid'=>$pid]); log_error('sel_exec',$e,['pid'=>$pid]); if(count($_sel_exec_errs)<5)$_sel_exec_errs[]=substr($e->getMessage(),0,200); }
    }
}
foreach ($all_leads as &$_l) { $_l['id']=$place_id_map[trim((string)($_l['place_id']??''))]??0; } unset($_l);
$_db2_ms=_leads_ms($_t_db2);

// ── 11. Slice FIRST, unlock ONLY what the user receives ───────────────────
$leads_to_return=array_values(array_slice($all_leads,0,$req_count));
$pro_lead_count=0; $_unlock_attempted=0; $_unlock_errors=[]; $_t_unlock=microtime(true);
if ($is_paid) {
    $_ul=null;
    try { $_ul=$pdo->prepare('INSERT IGNORE INTO unlocked_leads (user_id,lead_id) VALUES(?,?)'); }
    catch(\Throwable $e){ leads_log_error('unlock_prepare',$e,['uid'=>$uid]); log_error('unlock_prepare',$e); $_unlock_errors[]='prepare:'.substr($e->getMessage(),0,120); }
    if ($_ul) {
        foreach ($leads_to_return as $ret_lead) {
            $db_id=(int)($ret_lead['id']??0); if($db_id<=0) continue;
            $_unlock_attempted++;
            try { $_ul->execute([$uid,$db_id]); }
            catch(\Throwable $e){ leads_log_warn('unlock_exec',$e,['uid'=>$uid,'lead_id'=>$db_id]); log_error('unlock_exec',$e,['uid'=>$uid,'lead_id'=>$db_id]); $_unlock_errors[]='lead_id='.$db_id.':'.substr($e->getMessage(),0,80); }
        }
    }
    try {
        $_cnt=$pdo->prepare('SELECT COUNT(DISTINCT lead_id) FROM unlocked_leads WHERE user_id=?');
        $_cnt->execute([$uid]); $pro_lead_count=(int)$_cnt->fetchColumn();
    } catch(\Throwable $e){ leads_log_error('unlock_count',$e,['uid'=>$uid]); log_error('count',$e,['uid'=>$uid]); $_unlock_errors[]='count:'.substr($e->getMessage(),0,80); }
}
$_unlock_ms=_leads_ms($_t_unlock);

// ── 12. History ───────────────────────────────────────────────────────────
try {
    $pdo->prepare('INSERT INTO utiligo_lead_search_history (user_id,city,industry,keywords,result_count,created_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE result_count=VALUES(result_count),created_at=NOW()')
        ->execute([$uid,$city,$industry,$keywords,count($leads_to_return)]);
} catch(\Throwable $e){ leads_log_warn('history_write',$e,['uid'=>$uid]); }

// ── Audit log — always written on success ────────────────────────────────
$_total_ms=_leads_ms($_t_start);
leads_log_info('search_complete',[
    'uid'=>$uid,'plan'=>$plan,'city'=>$city,'industry'=>$industry,
    'req_count'=>$req_count,'returned'=>count($leads_to_return),
    'from_cache'=>$from_cache,'unlocked'=>$_unlock_attempted,
    'total_ms'=>$_total_ms,'google_ms'=>$_google_ms,'db_ms'=>$_db2_ms,'unlock_ms'=>$_unlock_ms,
    'id_fail_count'=>$_id_resolve_fails,
]);

// ── 13. Payload ───────────────────────────────────────────────────────────
$_debug_block=[
    'v'=>'5.4','plan'=>$plan,'is_paid'=>$is_paid,'from_cache'=>$from_cache,
    'total_raw'=>count($all_leads),'req_count'=>$req_count,'returned'=>count($leads_to_return),
    'unlock_tried'=>$_unlock_attempted,'unlock_errors'=>$_unlock_errors,'pro_lead_count'=>$pro_lead_count,
    'id_fail_count'=>$_id_resolve_fails,'total_ms'=>$_total_ms,'google_ms'=>$_google_ms,
    'db_ms'=>$_db2_ms,'unlock_ms'=>$_unlock_ms,'ins_errors'=>$_ins_exec_errs,
    'sel_errors'=>$_sel_exec_errs,'alter_errors'=>$_alter_errors,
];
$free_limit=(int)FREE_LEAD_LIMIT;
if ($is_paid) {
    echo json_encode(['success'=>true,'leads'=>$leads_to_return,'locked_leads'=>[],'is_free_tier'=>false,
        'from_cache'=>$from_cache,'cached_at'=>$cached_at,'pro_lead_count'=>$pro_lead_count,
        'lead_limit'=>$pro_lead_limit,'searches_used'=>0,'searches_remaining'=>null,'_debug'=>$_debug_block]);
} else {
    $free_leads=array_values(array_slice($all_leads,0,$free_limit));
    $locked_leads=array_values(array_map(
        fn($l)=>['id'=>$l['id']??0,'business_name'=>'','business_address'=>'','business_phone'=>'','business_email'=>'','opportunity_score'=>0,'no_website'=>true,'_locked'=>true],
        array_slice($all_leads,$free_limit)
    ));
    echo json_encode(['success'=>true,'leads'=>$free_leads,'locked_leads'=>$locked_leads,'is_free_tier'=>true,
        'from_cache'=>$from_cache,'cached_at'=>$cached_at,'searches_used'=>$searches_used,
        'searches_remaining'=>$searches_remaining,'pro_lead_count'=>0,'lead_limit'=>0,'_debug'=>$_debug_block]);
}
