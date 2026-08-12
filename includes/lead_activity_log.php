<?php
/**
 * includes/lead_activity_log.php — Phase 5 audit-trail helper.
 *
 * Every meaningful user→lead event is written to lead_activity_log.
 * The shape is intentionally narrow:
 *   user_id   who did it (always the authenticated user)
 *   action    VARCHAR(40) — one of a small white-list (see ACTIONS below)
 *   target_id lead_id or NULL for user-scope actions like 'export_run'
 *   meta      JSON — flexible bag, kept small (max 6KB) so logs don't bloat.
 *             Never put secrets / PII here beyond what's already on the lead.
 *
 * Writes are best-effort: callers should *not* depend on them succeeding.
 * A missing lead_activity_log table / column degrades to silent skip —
 * admins get a log_error() entry instead of a user-facing failure.
 *
 * Reader: read_lead_activity($pdo, $user_id, $since, $limit, $action_filter)
 * for the admin tools + the future per-lead timeline view in the slide-over.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/error_logger.php';

/**
 * Canonical action names. Add to this list when introducing a new event.
 * Using a constant prevents typos from spawning orphan action values.
 */
const LEAD_ACT_SEARCH_RUN    = 'search_run';      // user ran a find-leads query
const LEAD_ACT_LEAD_UNLOCK   = 'lead_unlock';     // user paid to unlock a lead
const LEAD_ACT_LEAD_VIEW     = 'lead_view';       // user opened slide-over
const LEAD_ACT_EXPORT_RUN    = 'export_run';      // user kicked off an export
const LEAD_ACT_LEAD_ADD_CRM  = 'lead_add_crm';    // user added a lead to CRM
const LEAD_ACT_SAVED_SEARCH  = 'saved_search';    // user saved a search
const LEAD_ACT_NOTIFY_TOGGLE = 'notify_toggle';   // user toggled notify_email on a saved search
const LEAD_ACT_NOTIFY_SENT   = 'notify_sent';     // scheduled-search cron emailed the user

/**
 * @param PDO    $pdo
 * @param int    $user_id
 * @param string $action     one of the LEAD_ACT_* constants
 * @param int|null $target_id    lead_id, or NULL for user-scope events
 * @param array $meta        dict, kept small, must json_encode cleanly
 * @return bool              true if a row was written
 */
function log_lead_activity(\PDO $pdo, int $user_id, string $action, ?int $target_id = null, array $meta = []): bool {
    static $allowed = [
        LEAD_ACT_SEARCH_RUN, LEAD_ACT_LEAD_UNLOCK, LEAD_ACT_LEAD_VIEW,
        LEAD_ACT_EXPORT_RUN, LEAD_ACT_LEAD_ADD_CRM,
        LEAD_ACT_SAVED_SEARCH, LEAD_ACT_NOTIFY_TOGGLE, LEAD_ACT_NOTIFY_SENT,
    ];
    if (!in_array($action, $allowed, true)) return false;
    if ($user_id <= 0) return false;

    // Cap $meta to avoid accidental growth — never store more than ~6KB.
    try {
        $j = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($j) > 6144) $j = json_encode(['_truncated' => true, 'size' => strlen($j)]);
    } catch (\Throwable $e) {
        $j = null;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO lead_activity_log (user_id, action, target_id, meta)
             VALUES (?, ?, ?, ?)'
        );
        return $stmt->execute([$user_id, $action, $target_id, $j]);
    } catch (\Throwable $e) {
        log_error('lead_activity_log_write', $e, ['action' => $action, 'target' => $target_id]);
        return false;
    }
}

/**
 * Reader. Returns rows newest-first.
 *
 * @param int    $user_id
 * @param string $since       ISO date or "0" for no lower bound
 * @param int    $limit       capped to 200
 * @param string $action      optional single-action filter
 */
function read_lead_activity(\PDO $pdo, int $user_id, string $since = '0', int $limit = 50, string $action = ''): array {
    if ($user_id <= 0) return [];
    $limit = max(1, min(200, (int)$limit));
    $sql = 'SELECT id, action, target_id, meta, at FROM lead_activity_log WHERE user_id = ?';
    $args = [$user_id];
    if ($action !== '') {
        $sql .= ' AND action = ?';
        $args[] = $action;
    }
    if ($since !== '0' && $since !== '') {
        $sql .= ' AND at >= ?';
        $args[] = $since;
    }
    $sql .= ' ORDER BY at DESC LIMIT ' . $limit;
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        log_error('lead_activity_log_read', $e);
        return [];
    }
    // Decode meta JSON for caller convenience.
    foreach ($rows as &$r) {
        if (!empty($r['meta']) && is_string($r['meta'])) {
            try { $r['meta'] = json_decode($r['meta'], true); } catch (\Throwable $e) {}
        }
    }
    unset($r);
    return $rows;
}
