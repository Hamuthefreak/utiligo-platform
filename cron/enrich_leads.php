<?php
/**
 * cron/enrich_leads.php — Phase 2 enrichment worker.
 *
 * Schedules via cPanel / cron every 5 minutes:
 *   (every 5 min) curl -s "https://utiligo.ca/cron/enrich_leads.php?secret=YOUR_CRON_SECRET" > /dev/null
 *
 * Finds leads whose enriched_at IS NULL and that the calling user's plan
 * has at least one enrich_provider for. Self-terminates after 60s with
 * hard loop cap so it can never hang a cron slot.
 *
 * Throttling:
 *   - 1 outbound HTTP req per second to any non-billed upstream (enforced
 *     by _enrich_http_wait/_enrich_http_mark in lead_enrichment.php).
 *   - max 50 leads per run (so a tight cron never blows the budget) —
 *     override via ENRICH_BATCH_SIZE in admin/config.php if needed.
 *
 * Multi-process safety:
 *   No row locks. The provider functions are pure (no shared mutable
 *   state except GLOBALS['__osm_last_call_at']) and we serialize by
 *   processing one batch-and-die. If two cron instances overlap, the
 *   second sees a smaller set (because the first has marked rows
 *   enriched_at). Worst case: same lead gets enriched twice in a race
 *   — the ON DUPLICATE KEY UPDATE in enrich_lead() makes this safe.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/lead_enrichment.php';

header('Content-Type: text/plain; charset=utf-8');

$secret = $_GET['secret'] ?? '';
if (!is_string($secret) || !hash_equals(CRON_SECRET, $secret)) {
    http_response_code(403);
    echo "denied\n";
    exit;
}

@set_time_limit(120);
@ini_set('memory_limit', '256M');

$batch_size = defined('ENRICH_BATCH_SIZE') ? max(1, (int)ENRICH_BATCH_SIZE) : 50;
$hard_stop  = time() + 60;          // die at 60s no matter what

$pdo = get_platform_db();

// Which providers should the GLOBAL cron run?
// website_finder + email_pattern are free-of-cost operations and are the
// only providers Pro users get anyway. Ent-only providers (email_verifier
// does SMTP probes, social_profiles is TBD) are deferred to a future
// per-user-guided worker to avoid burning budget on users who don't need
// them. The Phase 2 ship goal is "exports don't ship email-empty" — these
// two providers satisfy that for every plan tier.
$enrich_providers = ['website_finder', 'email_pattern'];

// Pull up to $batch_size leads whose enriched_at IS NULL — newest first
// (so freshly-searched leads enrich before old backlog).
//
// utiligo_leads is a GLOBAL pool — it does not have a user_id column.
// Ownership is recorded in unlocked_leads.join(user_id, lead_id). The
// enrichment work is shared across all users that ever unlock a lead.
try {
    $stmt = $pdo->prepare(
        'SELECT *
           FROM utiligo_leads
          WHERE enriched_at IS NULL
          ORDER BY created_at DESC
          LIMIT ?'
    );
    $stmt->bindValue(1, $batch_size, PDO::PARAM_INT);
    $stmt->execute();
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    log_error('enrich_leads_pull', $e);
    echo "pull_error\n";
    exit;
}

if (!$leads) {
    echo "nothing_to_do\n";
    exit;
}

$processed   = 0;
$enriched    = 0;
$provider_hit_counts = [];

foreach ($leads as $lead) {
    if (time() >= $hard_stop) break;

    $n = 0;
    try {
        $n = enrich_lead($lead, $enrich_providers, $pdo);
    } catch (\Throwable $e) {
        log_error('enrich_lead_call', $e, ['lead_id' => (int)$lead['id']]);
    }
    $enriched += $n;
    $processed++;

    if ($n > 0) {
        foreach ($enrich_providers as $p) $provider_hit_counts[$p] = ($provider_hit_counts[$p] ?? 0) + 1;
    }
}

$hits_csv = '';
foreach ($provider_hit_counts as $p => $c) $hits_csv .= "{$p}={$c} ";
$hits_csv = trim($hits_csv);

echo "processed={$processed} enriched={$enriched} providers={$hits_csv}\n";
