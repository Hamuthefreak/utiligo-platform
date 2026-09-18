<?php
/**
 * purchase-success.php
 * Stripe redirects here after a successful checkout.
 *
 * This page does two things, in this order:
 *   1. Reconcile the account's plan from the Stripe Checkout Session. The
 *      stripe-webhook.php handler is the primary way a plan gets granted, but
 *      webhooks can be delayed, retried, or lost entirely, and a customer who
 *      has just paid must not be left sitting on the free plan because of it.
 *      This is the safety net: the same upgrade, applied from the same verified
 *      source, and only ever uphill — never a downgrade, and never a
 *      resurrection of a subscription the customer has already cancelled.
 *   2. Hand the plan to the portal so it can play the purchase animation.
 *
 * Entitlement is only ever written from a session Stripe confirms. The query
 * string is attacker-controlled, so ?plan alone must never change an account.
 *
 * It deliberately renders none of the site chrome — it used to load the full
 * marketing header, Tailwind CDN and onboarding.css only to redirect away
 * before any of it was used.
 *
 * Stripe passes: ?plan=pro|entrepreneur&session_id=cs_...
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
// Only *defines* get_user_db(); the connection itself is lazy, so requiring it
// here cannot 500 the page the way an eager connect would.
require_once __DIR__ . '/userdb.php';

if (!is_logged_in()) {
    header('Location: /login.php');
    exit;
}

$userId    = (int)($_SESSION['user_id'] ?? 0);
$sessionId = (string)($_GET['session_id'] ?? '');

// The signal starts OFF. Only a session Stripe confirms below switches it on,
// and a stale one from an earlier purchase must not leak into this visit.
unset($_SESSION['purchase_animation_plan']);

// Verify on every load rather than only the first: reconciliation is the whole
// point of this page now, and a refresh is exactly when a failed earlier
// attempt or a lost webhook needs retrying. Costs one Stripe read.
$grant = _purchase_verified_session($sessionId, $userId);

// Only a Stripe-confirmed grant may write to the database. The dev fallback
// below is fine for driving an animation, but it must never hand out
// entitlements from a URL — otherwise a deploy that forgot its Stripe keys
// would let anyone self-upgrade to Entrepreneur.
if ($grant !== null && $grant['verified']) {
    _purchase_reconcile_plan($userId, $grant['plan'], $grant['customer']);
}

// The animation itself stays one-shot per session id.
$celebrate = null;
if ($grant !== null && !_purchase_is_replay($sessionId, $_SESSION['purchase_ob_session'] ?? null)) {
    $_SESSION['purchase_animation_plan'] = $grant['plan'];
    $_SESSION['purchase_ob_session']     = $sessionId;
    $celebrate                           = $grant['plan'];
}

/**
 * Plans, cheapest first. This ordering is the single source of truth for the
 * "never downgrade" rule: the reconcile statement builds its FIELD() list from
 * this array, so the PHP check and the SQL guard cannot drift apart.
 */
function _purchase_plan_order(): array
{
    return ['free', 'pro', 'entrepreneur'];
}

/** Position in _purchase_plan_order(), or null for a plan we don't recognise. */
function _purchase_plan_rank(string $plan): ?int
{
    $rank = array_search(strtolower(trim($plan)), _purchase_plan_order(), true);
    return $rank === false ? null : (int)$rank;
}

/**
 * Has this session id already been celebrated? Reloading the success URL
 * re-sends the same id, and without this the splash would loop on every refresh.
 */
function _purchase_is_replay(string $sessionId, ?string $shownSession): bool
{
    return $sessionId !== '' && $shownSession === $sessionId;
}

/**
 * Should this verified purchase overwrite the account's current plan?
 *
 * Only when it moves the account UP the plan order. A stale session id from an
 * earlier, smaller purchase must never be able to walk a customer backwards —
 * a customer who upgraded to Entrepreneur last month can still reach this URL
 * with their old Pro session id, and that must be a no-op.
 *
 * An empty/NULL column means "never set", i.e. the free tier. A value we don't
 * recognise is left alone rather than guessed at.
 */
function _purchase_should_reconcile(string $currentPlan, string $verifiedPlan): bool
{
    $current = _purchase_plan_rank($currentPlan);
    if ($current === null && trim($currentPlan) === '') {
        $current = _purchase_plan_rank('free');
    }

    $verified = _purchase_plan_rank($verifiedPlan);
    if ($current === null || $verified === null) {
        return false;
    }

    return $verified > $current;
}

/**
 * Apply the verified upgrade to the account. Mirrors the UPDATE in
 * stripe-webhook.php so a plan granted here and one granted by the webhook are
 * indistinguishable.
 *
 * Never throws: this page must always reach its redirect. Failing to reconcile
 * is survivable (the webhook may still arrive); a 500 after payment is not.
 */
function _purchase_reconcile_plan(int $userId, string $verifiedPlan, string $customerId): bool
{
    if ($userId <= 0 || _purchase_plan_rank($verifiedPlan) === null) {
        return false;
    }

    try {
        $pdo = get_user_db();

        // subscription_status is read alongside plan because a cancelled account
        // reads as 'free' again, which from here looks like an ordinary upgrade.
        // The column is assumed present, as admin/users.php and the webhook do.
        $stmt = $pdo->prepare('SELECT plan, subscription_status FROM utiligo_users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        // A cancelled subscriber reads as 'free' again, so "free -> paid" looks
        // like an ordinary upgrade. Refuse it: replaying a saved success URL must
        // not hand entitlement back to someone who has cancelled. Only recovery
        // is a fresh checkout, which starts its own session.
        if (trim(strtolower((string)($row['subscription_status'] ?? ''))) === 'cancelled') {
            error_log('[purchase-success] refusing to reconcile user ' . $userId
                . ': subscription is cancelled');
            return false;
        }

        if (!_purchase_should_reconcile((string)($row['plan'] ?? ''), $verifiedPlan)) {
            return false;
        }

        [$sql, $params] = _purchase_reconcile_statement($verifiedPlan, $customerId, $userId, true);

        try {
            $done = $pdo->prepare($sql);
            $done->execute($params);
        } catch (\Throwable $e) {
            // stripe_customer_id may predate its migration on some installs —
            // same fallback the webhook carries.
            error_log('[purchase-success] reconcile falling back without stripe_customer_id: ' . $e->getMessage());
            [$sql, $params] = _purchase_reconcile_statement($verifiedPlan, $customerId, $userId, false);
            $done = $pdo->prepare($sql);
            $done->execute($params);
        }

        $affected = $done->rowCount();
        if ($affected > 0) {
            error_log('[purchase-success] reconciled user ' . $userId . ' to ' . $verifiedPlan
                . ' (webhook had not applied it)');
        }

        return $affected > 0;
    } catch (\Throwable $e) {
        error_log('[purchase-success] reconcile failed for user ' . $userId . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * The UPDATE that applies a verified upgrade, plus its bound params.
 *
 * Pure and connection-free so the guard clause can be asserted directly — it is
 * the only part of this flow that otherwise needs a live MySQL to exercise, and
 * it is the part that hands out entitlement.
 *
 * The WHERE clause restates _purchase_reconcile_plan()'s guards in SQL so the
 * decision is atomic: a subscription.deleted webhook landing between that read
 * and this write cannot be overtaken by a stale upgrade. That matters because
 * the deleted handler leaves plan='free' and status='cancelled' — plan alone
 * would read as a legitimate upgrade, so the status is re-checked here too.
 * `$rank > 0` leaves an unrecognised current plan alone; `$rank < FIELD(?)` is
 * the never-downgrade rule. Both sides take their ordering from
 * _purchase_plan_order(), so they cannot drift apart.
 *
 * $withCustomer is false only for the retry path on an install where the
 * stripe_customer_id column predates its migration.
 */
function _purchase_reconcile_statement(string $verifiedPlan, string $customerId, int $userId, bool $withCustomer): array
{
    // Only ever built from _purchase_plan_order() — a literal list of lowercase
    // plan names in this file — so nothing user-supplied reaches the SQL text.
    $list = "'" . implode("', '", _purchase_plan_order()) . "'";
    // LOWER(TRIM(...)) mirrors _purchase_plan_rank(), which lowercases and trims
    // too. Doing it here rather than leaning on FIELD's case-insensitivity keeps
    // the two sides in agreement whatever the column's collation is: under a
    // case-sensitive collation a stored 'Pro' would rank 0 and the write would be
    // refused, while PHP would call it an upgrade. Both sides now normalise.
    $rank = "FIELD(COALESCE(NULLIF(LOWER(TRIM(plan)), ''), 'free'), $list)";

    $set    = "plan = ?, subscription_status = 'active', subscription_started_at = NOW()";
    $params = [$verifiedPlan, $userId, $verifiedPlan];
    if ($withCustomer) {
        // COALESCE/NULLIF so an empty customer id never clobbers a stored one.
        $set    = "plan = ?, subscription_status = 'active',"
                . " stripe_customer_id = COALESCE(NULLIF(?, ''), stripe_customer_id),"
                . " subscription_started_at = NOW()";
        $params = [$verifiedPlan, $customerId, $userId, $verifiedPlan];
    }

    // COALESCE so a NULL status (an account that never subscribed) is not
    // mistaken for 'cancelled'.
    $notCancelled = "COALESCE(LOWER(TRIM(subscription_status)), '') <> 'cancelled'";

    $sql = "UPDATE utiligo_users SET $set WHERE id = ? AND $notCancelled"
         . " AND $rank > 0 AND $rank < FIELD(?, $list)";

    return [$sql, $params];
}

/**
 * Decide whether $sessionId proves a genuine, paid upgrade by $userId.
 * Returns ['plan'=>…, 'customer'=>…, 'verified'=>…] or null.
 *
 * Why this exists: ?plan in the URL is attacker-controlled — anyone could open
 * /purchase-success.php?plan=entrepreneur and watch the upsell splash. The
 * Checkout Session is the trustworthy source, because stripe-checkout.php
 * stamps it server-side with client_reference_id (the payer) and
 * metadata[plan] (the price actually bought).
 */
function _purchase_verified_session(string $sessionId, int $userId): ?array
{
    if ($sessionId === '' || $userId === 0) {
        return null;
    }

    // Cheap shape check, so obviously bogus input never causes an outbound call
    // and cannot smuggle anything into the request URL.
    if (!preg_match('/^cs_[A-Za-z0-9_]{8,200}$/', $sessionId)) {
        error_log('[purchase-success] rejected malformed session_id');
        return null;
    }

    // No real key configured: mirror stripe-webhook.php, which likewise skips
    // verification while the secret is still the YOUR_ placeholder. Checkout
    // cannot even be started in this state (stripe-checkout.php bails with
    // stripe_error=not_configured), so this only affects hand-typed dev URLs.
    // Marked unverified so it can drive the animation but never the database.
    if (STRIPE_SECRET_KEY === '' || str_starts_with(STRIPE_SECRET_KEY, 'YOUR_')) {
        return _purchase_unverified_session();
    }

    // A host without cURL can never reach this page in a real flow either —
    // stripe-checkout.php needs cURL to create the session in the first place —
    // so there is no fallback transport worth writing. Bail out quietly rather
    // than letting an undefined function take down a page the customer lands on
    // straight after paying.
    if (!function_exists('curl_init')) {
        error_log('[purchase-success] cURL unavailable; skipping session verification');
        return null;
    }

    // Raw cURL, matching stripe-checkout.php — no Composer, no Stripe SDK.
    try {
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
            // Shorter than stripe-checkout's 15s: the user is staring at a
            // placeholder page while this runs, and giving up just means no
            // animation while the webhook grants the plan regardless.
            CURLOPT_TIMEOUT        => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        // Nothing here is worth a 500 on a post-payment landing page.
        error_log('[purchase-success] session lookup threw: ' . $e->getMessage());
        return null;
    }

    if (!is_string($response) || $response === '' || $httpCode !== 200) {
        error_log('[purchase-success] session lookup failed: http ' . $httpCode . ' ' . $curlErr);
        return null;
    }

    $session = json_decode($response, true);
    if (!is_array($session)) {
        error_log('[purchase-success] session lookup returned unparseable JSON');
        return null;
    }

    return _purchase_session_grant($session, $userId);
}

/**
 * The security-critical decision, deliberately kept pure: given a Stripe
 * Checkout Session payload, return the grant it proves for $userId, or null.
 * No I/O, so the whole decision table is directly exercisable.
 */
function _purchase_session_grant(array $session, int $userId): ?array
{
    // A session can never belong to "no user". Without this, userId 0 would
    // satisfy the owner comparison below whenever a session also carries no
    // owner info (0 === 0). _purchase_verified_session already rejects userId 0,
    // but this predicate must be safe on its own terms.
    if ($userId <= 0) {
        return null;
    }

    // 1. The session must actually be finished and settled. Both checks matter:
    //    a session can be 'complete' while payment_status is still 'unpaid' for
    //    delayed payment methods, and that must not celebrate or upgrade.
    if (($session['status'] ?? '') !== 'complete') {
        error_log('[purchase-success] session not complete: ' . (string)($session['status'] ?? '?'));
        return null;
    }
    if (!in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) {
        error_log('[purchase-success] session unpaid: ' . (string)($session['payment_status'] ?? '?'));
        return null;
    }

    // 2. It must belong to the account that is signed in — otherwise one user
    //    could replay another user's session id and take their upgrade.
    $owner = (int)($session['client_reference_id'] ?? 0);
    if ($owner === 0) {
        $owner = (int)($session['metadata']['user_id'] ?? 0);
    }
    if ($owner !== $userId) {
        error_log('[purchase-success] session owner mismatch for user ' . $userId);
        return null;
    }

    // 3. The plan comes from Stripe's metadata — never from the query string.
    $plan = (string)($session['metadata']['plan'] ?? '');
    if (!in_array($plan, ['pro', 'entrepreneur'], true)) {
        error_log('[purchase-success] unexpected metadata[plan]: ' . $plan);
        return null;
    }

    // 4. The session must be recent. The page is a hand-off from a checkout that
    //    finished seconds ago, so a day-old session id is a replayed URL, not a
    //    live purchase. This matters now that the page can write entitlement: a
    //    subscriber who cancelled reads as 'free' again, and without this their
    //    old success URL would walk them straight back onto the paid plan. The
    //    window is generous enough to survive clock skew and a customer who
    //    leaves the success tab open; a session Stripe gives no usable timestamp
    //    for is rejected, because the webhook still covers the honest case.
    $created = $session['created'] ?? null;
    if (!(is_int($created) || (is_string($created) && ctype_digit($created)))) {
        error_log('[purchase-success] session has no usable created timestamp');
        return null;
    }
    if ((int)$created < time() - 60 * 60 * 24) {
        error_log('[purchase-success] stale checkout session (created ' . $created . ')');
        return null;
    }

    return [
        'plan'     => $plan,
        'customer' => (string)($session['customer'] ?? ''),
        'verified' => true,
    ];
}

/**
 * Dev-only fallback, reachable solely when Stripe has no secret key configured.
 * Reproduces the old ?plan behaviour so the splash stays testable locally.
 * Always 'verified' => false, so it can never write to the database.
 */
function _purchase_unverified_session(): ?array
{
    $plan = (string)($_GET['plan'] ?? '');
    if (!in_array($plan, ['free', 'pro', 'entrepreneur'], true)) {
        return null;
    }

    return ['plan' => $plan, 'customer' => '', 'verified' => false];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php /* A hand-off page should never be indexed or canonicalised. */ ?>
<meta name="robots" content="noindex, nofollow">
<title>Setting up your account — Utiligo</title>
<style>
  /* Inline so this page needs no external stylesheet at all. */
  html, body { height: 100%; margin: 0; background: #020817; color: #94a3b8; }
  body {
    display: flex; align-items: center; justify-content: center; text-align: center;
    font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
    font-size: .9rem;
  }
</style>
</head>
<body>
<p>Setting up your account&hellip;</p>
<script>
<?php if ($celebrate !== null): ?>
  // Client-side half of the animation signal. Only emitted once the session is
  // confirmed, so a hand-typed URL can't reach the splash.
  try { sessionStorage.setItem('utl_purchase_ob_' + <?= json_encode($celebrate, JSON_UNESCAPED_SLASHES) ?>, '1'); } catch (e) {}
<?php endif; ?>
  // Must always run — a privacy mode that blocks sessionStorage, a failed
  // verification, or a failed reconcile still has to land the user on their
  // dashboard.
  window.location.replace('/portal/index.php');
</script>
<noscript>
  <meta http-equiv="refresh" content="0;url=/portal/index.php">
</noscript>
</body>
</html>
