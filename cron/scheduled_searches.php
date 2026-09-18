<?php
/**
 * cron/scheduled_searches.php — Phase 5 scheduled-search notifier.
 *
 * Schedules via cPanel / cron every 30 minutes:
 *   (every 30 min) curl -s "https://utiligo.ca/cron/scheduled_searches.php?secret=YOUR_CRON_SECRET" > /dev/null
 *
 * What it does:
 *   For every saved_searches row where notify_email = 1:
 *     1. Decode the params JSON (city, industry, keywords, sources, …)
 *     2. Query utiligo_leads for rows whose business_city ILIKE params.city
 *        AND business_category LIKE keywords, and updated_at > the saved
 *        search's last_run_at — i.e. leads added to the shared pool
 *        since the last notification.
 *     3. If the delta is non-empty, render a small HTML email + send via
 *        send_email() (Brevo REST API) to the user's registration email.
 *     4. Update saved_searches.last_run_at and last_count.
 *
 * What it does NOT do:
 *   - Re-run the full Google Places search (that's expensive and would
 *     blow our Places API quota every 30min×N subscriptions). We rely
 *     on the shared pool staying fresh from incidental user searches.
 *     Phase 6 could add a "decay-factor 0.5× crawl" that re-queries
 *     Places for stale cities when nobody has searched for them lately.
 *   - Send spammy "0 new leads" emails — the cron is silent on empty
 *     deltas, only updating last_run_at so the next run has a fresh
 *     baseline.
 *
 * Multi-process safety:
 *   No advisory locks. A row added between two overlapping cron
 *   instances can produce a duplicate email in the rare race window —
 *   acceptable for a noisily-rectifiable delta email. We mitigate by
 *   capping per-run duration at 60s and breaking out of the loop on
 *   timeout.
 *
 * Gated by CRON_SECRET (same pattern as cron/build_exports.php).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../userdb.php';   // get_user_db() — owner email + plan
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/lead_activity_log.php';

header('Content-Type: text/plain; charset=utf-8');

$secret = $_GET['secret'] ?? '';
if (!is_string($secret) || !hash_equals(CRON_SECRET, $secret)) {
    http_response_code(403);
    echo "denied\n";
    exit;
}

@set_time_limit(120);
@ini_set('memory_limit', '256M');

$hard_stop = time() + 60;

$pdo = get_platform_db();

// saved_searches lives in the PLATFORM db; accounts live in utiligo_users in
// the USER db.  Those are two different databases (and on some hosts two
// different servers), so this must NOT be a SQL JOIN.  It used to be
// `JOIN users u ON u.id = ss.user_id` — a table that exists nowhere in this
// codebase — so the query always threw, the catch echoed pull_error, and the
// Ent-only scheduled-search notifications never ran for anybody.
//
// Read the jobs here, then resolve each owner's email + plan from the user db
// in one IN(...) round-trip.
try {
    $stmt = $pdo->prepare(
        'SELECT ss.id AS ss_id, ss.user_id, ss.name, ss.params, ss.last_run_at,
                ss.last_count, ss.notify_email
           FROM saved_searches ss
          WHERE ss.notify_email = 1
          ORDER BY ss.id ASC
          LIMIT 100'
    );
    $stmt->execute();
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    log_error('scheduled_searches_pull', $e);
    echo "pull_error\n";
    exit;
}

if (!$jobs) {
    echo "nothing_to_do\n";
    exit;
}

$owners = [];
try {
    $ids = array_values(array_unique(array_map(static fn($j) => (int)$j['user_id'], $jobs)));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $us  = get_user_db()->prepare("SELECT id, email, plan FROM utiligo_users WHERE id IN ($ph)");
    $us->execute($ids);
    foreach ($us->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $owners[(int)$u['id']] = $u;
    }
} catch (\Throwable $e) {
    log_error('scheduled_searches_owners', $e);
    echo "pull_error\n";
    exit;
}

// Keep only jobs whose owner still exists, has an email, and whose plan
// permits scheduled searches — can_schedule_searches() is Ent-only today, so
// an Ent subscription is what unlocks this.  Applies the plan helper (not a
// hardcoded string) so future plan-config changes are picked up here.
$eligible = [];
foreach ($jobs as $j) {
    $uid = (int)$j['user_id'];
    if (!isset($owners[$uid])) continue;                       // owner deleted
    $owner = $owners[$uid];
    if (($owner['email'] ?? '') === '') continue;              // nothing to send to
    if (!can_schedule_searches((string)($owner['plan'] ?? 'free'))) continue;
    $j['email'] = (string)$owner['email'];
    $j['plan']  = (string)($owner['plan'] ?? 'free');
    $eligible[] = $j;
}
$jobs = $eligible;

if (!$jobs) {
    echo "nothing_to_do\n";
    exit;
}

$processed = 0;
$emails_sent = 0;
$deltas_seen = 0;
foreach ($jobs as $job) {
    if (time() >= $hard_stop) break;

    $params = json_decode($job['params'] ?? '{}', true);
    if (!is_array($params)) $params = [];

    $city      = trim($params['city']      ?? '');
    $industry  = trim($params['industry']  ?? '');
    $keywords  = trim($params['keywords']  ?? '');
    if ($city === '' && $industry === '' && $keywords === '') {
        // Nothing to compare against — update last_run_at as a no-op
        // marker so we don't accidentally spam the user on every run if
        // they fix their params later.
        _sched_search_update($pdo, (int)$job['ss_id'], 0);
        $processed++;
        continue;
    }

    // Build a delta query: matches saved params (city + category LIKE keywords)
    // and updated_at > last_run_at. We use LOWER + LIKE so collation does
    // not matter, and ILIKE-equivalent via LOWER.
    try {
        $sql = "SELECT id, business_name, business_category, business_city, business_phone, business_email, website, maps_url, rating
                  FROM utiligo_leads
                 WHERE updated_at > ?";
        $args = [$job['last_run_at'] ?? '1970-01-01 00:00:00'];
        if ($city !== '') {
            $sql .= " AND LOWER(business_city) LIKE ?";
            $args[] = '%' . strtolower($city) . '%';
        }
        if ($industry !== '') {
            $sql .= " AND LOWER(business_category) LIKE ?";
            $args[] = '%' . strtolower($industry) . '%';
        }
        if ($keywords !== '') {
            $sql .= " AND (LOWER(business_name) LIKE ? OR LOWER(business_category) LIKE ?)";
            $args[] = '%' . strtolower($keywords) . '%';
            $args[] = '%' . strtolower($keywords) . '%';
        }
        $sql .= " ORDER BY rating DESC, total_ratings DESC LIMIT 25";
        $ds = $pdo->prepare($sql);
        $ds->execute($args);
        $new_leads = $ds->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        log_error('scheduled_searches_delta', $e, ['ss_id' => (int)$job['ss_id']]);
        $processed++;
        continue;
    }

    if (!$new_leads) {
        _sched_search_update($pdo, (int)$job['ss_id'], 0);
        $processed++;
        continue;
    }
    $deltas_seen++;

    // Render a small email body.
    $base_url = (defined('APP_BASE_URL') && APP_BASE_URL) ? APP_BASE_URL : 'https://utiligo.ca';
    $html = '<div style="font-family: -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif; max-width: 560px; margin: 0 auto; padding: 24px;">';
    $html .= '<h2 style="margin:0 0 4px; color:#0f172a; font-size:18px;">New leads matching your saved search</h2>';
    $html .= '<p style="margin:0 0 16px; font-size:13px; color:#475569;">' . htmlspecialchars($job['name'])
        . ' — "' . htmlspecialchars($city) . ' / ' . htmlspecialchars($industry . ($keywords ? ' / ' . $keywords : ''))
        . '" got ' . count($new_leads) . ' new entries since the last check.</p>';
    $html .= '<table style="width:100%; border-collapse:collapse; font-size:13px;">';
    foreach ($new_leads as $i => $l) {
        $name = htmlspecialchars($l['business_name'] ?: '—');
        $cat  = htmlspecialchars($l['business_category'] ?: '');
        $city_r = htmlspecialchars($l['business_city']?: '');
        $phone = htmlspecialchars($l['business_phone'] ?: '');
        $email_r = $l['business_email'] ? '<a href="mailto:' . htmlspecialchars($l['business_email']) . '" style="color:#2563eb;">' . htmlspecialchars($l['business_email']) . '</a>' : '';
        $web   = $l['website'] ? '<a href="' . htmlspecialchars($l['website']) . '" style="color:#2563eb;">website</a>' : '';
        $link  = $base_url . '/portal/leads.php?lead=' . (int)$l['id'];
        $html .= '<tr style="border-bottom:1px solid #f1f5f9;">';
        $html .= '<td style="padding:10px 4px; vertical-align:top;"><a href="' . htmlspecialchars($link) . '" style="color:#0f172a; font-weight:600; text-decoration:none;">' . $name . '</a>';
        if ($cat)       $html .= '<br><span style="color:#64748b; font-size:11px;">' . $cat . '</span>';
        if ($city_r)    $html .= '<br><span style="color:#94a3b8; font-size:11px;">' . $city_r . '</span>';
        $html .= '</td>';
        $html .= '<td style="padding:10px 4px; vertical-align:top; text-align:right;">';
        if ($phone)    $html .= '<span style="display:block; color:#475569;">' . $phone . '</span>';
        if ($email_r)  $html .= '<span style="display:block;">' . $email_r . '</span>';
        if ($web)      $html .= '<span style="display:block;">' . $web . '</span>';
        $html .= '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    $html .= '<p style="margin:18px 0 0; font-size:11px; color:#94a3b8;">Sent by Utiligo scheduled-search notifier · '
        . '<a href="' . htmlspecialchars($base_url) . '/portal/leads.php" style="color:#2563eb;">Manage saved searches</a></p>';
    $html .= '</div>';

    $text = "New leads matching your saved search: " . $job['name'] . "\n"
          . count($new_leads) . " new entries.\n\n"
          . "Visit " . $base_url . "/portal/leads.php to view them.";

    $ok = false;
    try {
        $ok = send_email(
            $job['email'],
            'New leads for: ' . $job['name'],
            $html,
            $text
        );
    } catch (\Throwable $e) {
        log_error('scheduled_searches_send', $e, ['ss_id' => (int)$job['ss_id'], 'uid' => (int)$job['user_id']]);
    }

    if ($ok) {
        $emails_sent++;
        // Audit the notification send.
        try { log_lead_activity($pdo, (int)$job['user_id'], 'notify_sent', null, [
            'ss_id' => (int)$job['ss_id'],
            'count' => count($new_leads),
        ]); } catch (\Throwable $e) {}
    }
    _sched_search_update($pdo, (int)$job['ss_id'], count($new_leads));
    $processed++;
}

echo "processed={$processed} deltas={$deltas_seen} emails={$emails_sent}\n";

function _sched_search_update(\PDO $pdo, int $ss_id, int $count): void {
    try {
        $pdo->prepare('UPDATE saved_searches SET last_run_at = NOW(), last_count = ? WHERE id = ?')
            ->execute([$count, $ss_id]);
    } catch (\Throwable $e) {
        log_error('scheduled_searches_update', $e, ['ss_id' => $ss_id]);
    }
}
