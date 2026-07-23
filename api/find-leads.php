<?php
/**
 * api/find-leads.php  v4.1 — debug patch
 * Identical to v4 except _debug now captures every error from
 * table-create, INSERT IGNORE prepare/exec and SELECT prepare/exec
 * so map_size=0 can be diagnosed directly from the browser console.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

api_bootstrap();
header('Content-Type: application/json');

// ── 1. Auth ───────────────────────────────────────────────────────────────
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'Not logged in.']);
    exit;
}
$user    = current_user();
$plan    = $user['plan'] ?? 'free';
$is_paid = in_array($plan, ['pro','entrepreneur'], true);
$uid     = (int)$user['id'];

// ── 2. Rate limit ─────────────────────────────────────────────────────────
if (!rate_limit_check('find_leads', (int)RATE_LIMIT_FIND_LEADS)) {
    http_response_code(429);
    echo json_encode(['success'=>false,'error'=>'Too many requests. Please wait a moment.']);
    exit;
}

// ── 3. Parse + validate ───────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid request.']); exit; }

$city      = trim((string)($body['city']      ?? ''));
$industry  = trim((string)($body['industry']  ?? ''));
$keywords  = trim((string)($body['keywords']  ?? ''));
$force     = !empty($body['force_refresh']);
$req_count = max(1, min(40, (int)($body['lead_count'] ?? 10)));

if (!csrf_verify($body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Security check failed. Please refresh the page.']);
    exit;
}
if ($city === '' || $industry === '') { echo json_encode(['success'=>false,'error'=>'City and industry are required.']); exit; }
if (strlen($city) > 100 || strlen($industry) > 100) { echo json_encode(['success'=>false,'error'=>'Input too long.']); exit; }
if (!preg_match('/^[\p{L}0-9 \-\',.]+$/u', $city) || !preg_match('/^[\p{L}0-9 \-\',.&]+$/u', $industry)) {
    echo json_encode(['success'=>false,'error'=>'Invalid characters in city or industry.']); exit;
}

// ── 4. DB connect ─────────────────────────────────────────────────────────
try {
    $pdo = get_platform_db();
} catch (\Throwable $e) {
    log_error('find_leads_db_connect', $e);
    echo json_encode(['success'=>false,'error'=>'Database unavailable. Please try again shortly.']);
    exit;
}

// ── 5. Table bootstrap ────────────────────────────────────────────────────
$_tbl_errors = [];
foreach ([
    'utiligo_leads' => '
        CREATE TABLE IF NOT EXISTS `utiligo_leads` (
            `id`                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `place_id`          VARCHAR(255)  NOT NULL,
            `business_name`     VARCHAR(255)  NOT NULL DEFAULT \'\',
            `business_address`  VARCHAR(500)  NOT NULL DEFAULT \'\',
            `business_phone`    VARCHAR(80)   NOT NULL DEFAULT \'\',
            `business_email`    VARCHAR(255)  NOT NULL DEFAULT \'\',
            `business_category` VARCHAR(150)  NOT NULL DEFAULT \'\',
            `business_city`     VARCHAR(100)  NOT NULL DEFAULT \'\',
            `rating`            DECIMAL(3,1)  NULL,
            `total_ratings`     INT UNSIGNED  NOT NULL DEFAULT 0,
            `maps_url`          VARCHAR(500)  NOT NULL DEFAULT \'\',
            `opportunity_score` INT UNSIGNED  NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_place_id` (`place_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'lead_cache' => '
        CREATE TABLE IF NOT EXISTS `lead_cache` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `cache_key`  VARCHAR(255) NOT NULL,
            `leads_json` MEDIUMTEXT   NOT NULL,
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_cache_key` (`cache_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'unlocked_leads' => '
        CREATE TABLE IF NOT EXISTS `unlocked_leads` (
            `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`     INT UNSIGNED NOT NULL,
            `lead_id`     INT UNSIGNED NOT NULL,
            `unlocked_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_lead` (`user_id`,`lead_id`),
            KEY `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'utiligo_lead_search_history' => '
        CREATE TABLE IF NOT EXISTS `utiligo_lead_search_history` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
] as $_tn => $_ts) {
    try { $pdo->exec($_ts); }
    catch (\Throwable $e) {
        log_error('find_leads_create_'.$_tn, $e);
        $_tbl_errors[$_tn] = substr($e->getMessage(), 0, 200);
    }
}
foreach ([
    "ALTER TABLE `utiligo_leads` ADD COLUMN `business_email` VARCHAR(255) NOT NULL DEFAULT '' AFTER `business_phone`",
    "ALTER TABLE `utiligo_leads` ADD COLUMN `business_city`  VARCHAR(100) NOT NULL DEFAULT '' AFTER `business_category`",
] as $_ddl) {
    try { $pdo->exec($_ddl); } catch (\Throwable $_) {}
}

// ── 6. Free quota ─────────────────────────────────────────────────────────
$searches_used = 0; $searches_remaining = null;
if (!$is_paid) {
    $daily_limit = (int)FREE_SEARCH_DAILY_LIMIT;
    $fingerprint = 'u'.$uid.'_'.substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 16);
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
                echo json_encode(['success'=>false,'error'=>'All '.$daily_limit.' free searches used today. Upgrade for unlimited.','rate_limited'=>true,'resets_at'=>strtotime($qrow['window_start'])+86400]);
                exit;
            }
            $pdo->prepare('UPDATE lead_search_quota SET count=count+1 WHERE id=?')->execute([$qrow['id']]);
            $searches_used++; $searches_remaining=max(0,$daily_limit-$searches_used);
        }
    } catch (\Throwable $e) { log_error('find_leads_quota',$e,['uid'=>$uid]); }
}

// ── 7. Cache ──────────────────────────────────────────────────────────────
$cache_key = strtolower(preg_replace('/\s+/',' ',$city.'|'.$industry));
$cache_hrs = (int)LEAD_SEARCH_CACHE_HOURS;
$from_cache = false; $cached_at = date('Y-m-d H:i:s'); $all_leads = [];
if (!$force) {
    try {
        $cs = $pdo->prepare('SELECT leads_json,created_at FROM lead_cache WHERE cache_key=? AND created_at>DATE_SUB(NOW(),INTERVAL ? HOUR) ORDER BY created_at DESC LIMIT 1');
        $cs->execute([$cache_key,$cache_hrs]);
        $crow = $cs->fetch(PDO::FETCH_ASSOC);
        if ($crow) {
            $dec = json_decode($crow['leads_json'],true);
            if (is_array($dec) && count($dec)>0) { $all_leads=$dec; $cached_at=$crow['created_at']; $from_cache=true; }
        }
    } catch (\Throwable $e) { log_error('find_leads_cache_read',$e); }
}

// ── 8. Google Places ──────────────────────────────────────────────────────
if (!$from_cache) {
    $api_key = defined('GOOGLE_PLACES_API_KEY') ? GOOGLE_PLACES_API_KEY : '';
    if (empty($api_key)||$api_key==='YOUR_GOOGLE_PLACES_API_KEY') { echo json_encode(['success'=>false,'error'=>'Lead search not configured.']); exit; }
    $ctx = stream_context_create(['http'=>['timeout'=>12,'ignore_errors'=>true]]);
    $resp = @file_get_contents('https://maps.googleapis.com/maps/api/place/textsearch/json?query='.urlencode($industry.' in '.$city).'&key='.urlencode($api_key),false,$ctx);
    if ($resp===false) { echo json_encode(['success'=>false,'error'=>'Could not reach Google Places. Try again.']); exit; }
    $places = json_decode($resp,true);
    $status = $places['status'] ?? 'UNKNOWN';
    if ($status==='REQUEST_DENIED') { echo json_encode(['success'=>false,'error'=>'Google API key issue.']); exit; }
    if ($status==='OVER_QUERY_LIMIT') { echo json_encode(['success'=>false,'error'=>'Google daily quota reached.']); exit; }
    if (!in_array($status,['OK','ZERO_RESULTS'],true)) { echo json_encode(['success'=>false,'error'=>'Search failed ('.$status.').']); exit; }
    $max_det=$det_cnt=0; $max_det=(int)MAX_PLACES_DETAILS_LOOKUPS;
    foreach ($places['results']??[] as $place) {
        if (!empty($place['website'])) continue;
        $pid=(string)($place['place_id']??''); $types=$place['types']??[];
        $rating=isset($place['rating'])?(float)$place['rating']:null;
        $reviews=(int)($place['user_ratings_total']??0);
        $category=$types?str_replace('_',' ',ucwords($types[0],'_')):$industry;
        $maps_url=$pid?'https://www.google.com/maps/place/?q=place_id:'.urlencode($pid):'';
        $phone='';
        if ($pid&&$det_cnt<$max_det) {
            $det=@file_get_contents('https://maps.googleapis.com/maps/api/place/details/json?place_id='.urlencode($pid).'&fields=formatted_phone_number&key='.urlencode($api_key),false,$ctx);
            if ($det!==false){$dj=json_decode($det,true);$phone=(string)($dj['result']['formatted_phone_number']??'');}
            $det_cnt++;
        }
        $all_leads[]=['place_id'=>$pid,'business_name'=>(string)($place['name']??'Unknown'),'business_address'=>(string)($place['formatted_address']??''),'business_city'=>$city,'business_phone'=>$phone,'business_email'=>'','business_category'=>$category,'rating'=>$rating,'total_ratings'=>$reviews,'maps_url'=>$maps_url,'no_website'=>true,'opportunity_score'=>opportunity_score($rating,$reviews,$category)];
    }
    usort($all_leads,fn($a,$b)=>$b['opportunity_score']<=>$a['opportunity_score']);
    try { $pdo->prepare('INSERT INTO lead_cache (cache_key,leads_json,created_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE leads_json=VALUES(leads_json),created_at=NOW()')->execute([$cache_key,json_encode($all_leads)]); } catch(\Throwable $e){log_error('cache_write',$e);}
    $cached_at=date('Y-m-d H:i:s');
}

// ── 9. INSERT IGNORE + SELECT id ──────────────────────────────────────────
// Every error is captured into $_debug so it appears in console._leadsDebug
$place_id_map    = [];
$_ins_prep_err   = null;
$_sel_prep_err   = null;
$_ins_exec_errs  = [];
$_sel_exec_errs  = [];

$_ins_sql = 'INSERT IGNORE INTO `utiligo_leads`
    (place_id,business_name,business_address,business_phone,business_email,
     business_category,business_city,rating,total_ratings,maps_url,opportunity_score)
    VALUES (?,?,?,?,?,?,?,?,?,?,?)';
$_sel_sql = 'SELECT id FROM `utiligo_leads` WHERE place_id=? LIMIT 1';

$_ins=null; $_sel=null;
try { $_ins=$pdo->prepare($_ins_sql); }
catch(\Throwable $e){ log_error('ins_prepare',$e); $_ins_prep_err=substr($e->getMessage(),0,250); }
try { $_sel=$pdo->prepare($_sel_sql); }
catch(\Throwable $e){ log_error('sel_prepare',$e); $_sel_prep_err=substr($e->getMessage(),0,250); }

foreach ($all_leads as $lead) {
    $pid=trim((string)($lead['place_id']??''));
    if ($pid==='') continue;
    if ($_ins) {
        try {
            $_ins->execute([
                $pid,(string)($lead['business_name']??''),(string)($lead['business_address']??''),
                (string)($lead['business_phone']??''),(string)($lead['business_email']??''),
                (string)($lead['business_category']??''),(string)($lead['business_city']??''),
                isset($lead['rating'])?(float)$lead['rating']:null,
                (int)($lead['total_ratings']??0),(string)($lead['maps_url']??''),(int)($lead['opportunity_score']??0),
            ]);
        } catch(\Throwable $e) {
            log_error('ins_exec',$e,['pid'=>$pid]);
            if (count($_ins_exec_errs)<5) $_ins_exec_errs[]=substr($e->getMessage(),0,200);
        }
    }
    if ($_sel) {
        try {
            $_sel->execute([$pid]);
            $db_id=$_sel->fetchColumn();
            if ($db_id!==false&&(int)$db_id>0) $place_id_map[$pid]=(int)$db_id;
        } catch(\Throwable $e) {
            log_error('sel_exec',$e,['pid'=>$pid]);
            if (count($_sel_exec_errs)<5) $_sel_exec_errs[]=substr($e->getMessage(),0,200);
        }
    }
}
foreach ($all_leads as &$_l) {
    $pid=trim((string)($_l['place_id']??''));
    $_l['id']=$place_id_map[$pid]??0;
}
unset($_l);

// ── 10. Unlock + count ────────────────────────────────────────────────────
$pro_lead_count=0;
$pro_lead_limit=($plan==='entrepreneur')?0:(int)PRO_LEAD_LIMIT;
$_unlock_attempted=0;
$_unlock_errors=[];

if ($is_paid) {
    if (!empty($place_id_map)) {
        $_ul=null;
        try { $_ul=$pdo->prepare('INSERT IGNORE INTO unlocked_leads (user_id,lead_id) VALUES(?,?)'); }
        catch(\Throwable $e){ log_error('unlock_prepare',$e); $_unlock_errors[]='prepare:'.substr($e->getMessage(),0,120); }
        if ($_ul) {
            foreach ($place_id_map as $_pid=>$db_id) {
                $_unlock_attempted++;
                try { $_ul->execute([$uid,$db_id]); }
                catch(\Throwable $e){ log_error('unlock_exec',$e,['uid'=>$uid,'lead_id'=>$db_id]); $_unlock_errors[]='lead_id='.$db_id.':'.substr($e->getMessage(),0,80); }
            }
        }
    }
    try {
        $_cnt=$pdo->prepare('SELECT COUNT(DISTINCT lead_id) FROM unlocked_leads WHERE user_id=?');
        $_cnt->execute([$uid]);
        $pro_lead_count=(int)$_cnt->fetchColumn();
    } catch(\Throwable $e){ log_error('count',$e,['uid'=>$uid]); $_unlock_errors[]='count:'.substr($e->getMessage(),0,80); }
}

// ── 11. History ───────────────────────────────────────────────────────────
try {
    $pdo->prepare('INSERT INTO utiligo_lead_search_history (user_id,city,industry,keywords,result_count,created_at) VALUES(?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE result_count=VALUES(result_count),created_at=NOW()')
        ->execute([$uid,$city,$industry,$keywords,count($all_leads)]);
} catch(\Throwable $e){}

// ── 12. Payload ───────────────────────────────────────────────────────────
// Capture first 3 place_ids so we can verify they are non-empty
$_sample_pids = array_slice(array_column($all_leads,'place_id'),0,3);

$_debug_block = [
    'plan'              => $plan,
    'is_paid'           => $is_paid,
    'uid'               => $uid,
    'from_cache'        => $from_cache,
    'total_leads_raw'   => count($all_leads),
    'sample_place_ids'  => $_sample_pids,          // should be non-empty strings
    'table_errors'      => $_tbl_errors,            // CREATE TABLE errors
    'ins_prepare_error' => $_ins_prep_err,          // INSERT IGNORE prepare error
    'sel_prepare_error' => $_sel_prep_err,          // SELECT id prepare error
    'ins_exec_errors'   => $_ins_exec_errs,         // per-row INSERT errors
    'sel_exec_errors'   => $_sel_exec_errs,         // per-row SELECT errors
    'map_size'          => count($place_id_map),    // 0 = ids not resolved
    'unlock_attempted'  => $_unlock_attempted,
    'unlock_errors'     => $_unlock_errors,
    'pro_lead_count'    => $pro_lead_count,
];

$free_limit=(int)FREE_LEAD_LIMIT;
if ($is_paid) {
    echo json_encode([
        'success'            => true,
        'leads'              => array_values(array_slice($all_leads,0,$req_count)),
        'locked_leads'       => [],
        'is_free_tier'       => false,
        'from_cache'         => $from_cache,
        'cached_at'          => $cached_at,
        'pro_lead_count'     => $pro_lead_count,
        'lead_limit'         => $pro_lead_limit,
        'searches_used'      => 0,
        'searches_remaining' => null,
        '_debug'             => $_debug_block,
    ]);
} else {
    echo json_encode([
        'success'            => true,
        'leads'              => array_values(array_slice($all_leads,0,$free_limit)),
        'locked_leads'       => array_values(array_map(fn($l)=>['id'=>$l['id']??0,'business_name'=>'','business_address'=>'','business_phone'=>'','business_email'=>'','opportunity_score'=>0,'no_website'=>true,'_locked'=>true],array_slice($all_leads,$free_limit))),
        'is_free_tier'       => true,
        'from_cache'         => $from_cache,
        'cached_at'          => $cached_at,
        'searches_used'      => $searches_used,
        'searches_remaining' => $searches_remaining,
        'pro_lead_count'     => 0,
        'lead_limit'         => 0,
        '_debug'             => $_debug_block,
    ]);
}
