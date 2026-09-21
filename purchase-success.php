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
 *      source through includes/entitlements.php — which is what keeps it
 *      identical to the webhook's, and is what enforces "never a downgrade"
 *      and "never a resurrection of a cancelled subscription".
 *
 * The Stripe reading (verifying the session, deciding what it proves) lives
 * here because it is specific to a redirect landing page. The writing does not:
 * that is the entitlement module's job, shared with the webhook.
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
require_once __DIR__ . '/includes/entitlements.php';
require_once __DIR__ . '/includes/stripe_api.php';

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

/*
 * A WHOP RETURN.
 *
 * Whop sends the buyer back here with ?whop_plan=… and the entitlement arrives
 * from the webhook a moment afterwards, so a customer who follows the redirect
 * quickly can reach this page before their plan exists. Without this the page
 * would hand them to a dashboard reading "Free" seconds after their card was
 * charged, which is the one thing a checkout return must never do.
 *
 * The query string grants nothing: there is no branch in this file that writes
 * from it, and the celebration flag below is still only ever set by a Stripe
 * session this server verified. All this decides is what the hand-off page says
 * while the payment settles.
 */
$whopPending = null;
if ($sessionId === '') {
    $wanted = strtolower(trim((string)($_GET['whop_plan'] ?? '')));
    if (is_paid_plan($wanted) && !_purchase_account_holds_plan($userId, $wanted)) {
        $whopPending = $wanted;
    }
}

// Only a Stripe-confirmed grant may write to the database. The dev fallback
// below is fine for driving an animation, but it must never hand out
// entitlements from a URL — otherwise a deploy that forgot its Stripe keys
// would let anyone self-upgrade to Entrepreneur.
//
// event_at is the session's own `created`: the moment the purchase actually
// happened. It is older than the webhook event's own timestamp for the same
// purchase, so whichever of the two arrives second is refused by the ordering
// guard in the module — and a day-old session id can never win.
if ($grant !== null && $grant['verified']) {
    entitlement_grant_from_stripe($userId, $grant['plan'], [
        'customer_id'     => $grant['customer'],
        'subscription_id' => $grant['subscription'],
        'event_at'        => $grant['created'],
        'source'          => 'purchase-success.reconcile',
    ]);
}

// The animation stays one-shot per session id, and only fires when the account
// really is — or already was — on the plan this session paid for.
//
// Verifying a session is not the same as being entitled to celebrate it, and
// the reconcile's return value cannot tell the two cases apart: in production
// the webhook normally wins the race, so the reconcile is refused as a no-op
// and still means the customer has their plan. What must NOT celebrate is a
// purchase the account no longer holds — a cancelled customer revisiting a
// saved success URL, whose plan reads 'free'. So ask the account, not the write.
$celebrate = null;
if ($grant !== null && !_purchase_is_replay($sessionId, $_SESSION['purchase_ob_session'] ?? null)) {
    // The dev fallback never writes, so there is nothing to check it against.
    $confirmed = !$grant['verified'] || _purchase_account_holds_plan($userId, $grant['plan']);

    if ($confirmed) {
        $_SESSION['purchase_animation_plan'] = $grant['plan'];
        $_SESSION['purchase_ob_session']     = $sessionId;
        $celebrate                           = $grant['plan'];
    }
}

/**
 * Does the account actually hold this plan (or better)?
 *
 * Read after the reconcile rather than inferred from it. A failed database read
 * returns false, which suppresses the animation rather than showing a customer
 * a plan we cannot prove they have.
 */
function _purchase_account_holds_plan(int $userId, string $plan): bool
{
    $wantRank = entitlement_plan_rank($plan);
    if ($wantRank === null) {
        return false;
    }

    $current  = entitlement_current_plan($userId);
    $haveRank = $current === null ? null : entitlement_plan_rank($current);

    return $haveRank !== null && $haveRank >= $wantRank;
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

    // Shares the one Stripe helper with stripe-checkout.php. A missing cURL
    // extension and a transport failure both come back as a plain failed
    // result, so neither can 500 a page the customer lands on seconds after
    // paying. The 10s timeout is shorter than checkout's 15s: giving up here
    // only costs the animation, since the webhook grants the plan regardless.
    $response = stripe_request('GET', 'v1/checkout/sessions/' . urlencode($sessionId), null, 10);

    if (!$response['ok']) {
        error_log('[purchase-success] session lookup failed: http ' . $response['http']
            . ' ' . $response['error']);
        return null;
    }

    return _purchase_session_grant($response['data'], $userId);
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

    // `created` and `subscription` travel with the grant because the entitlement
    // module orders the write by them: the purchase's own timestamp decides
    // whether it beats whatever the webhook may already have applied.
    return [
        'plan'         => $plan,
        'customer'     => (string)($session['customer'] ?? ''),
        'subscription' => (string)($session['subscription'] ?? ''),
        'created'      => (int)$created,
        'verified'     => true,
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

    return [
        'plan'         => $plan,
        'customer'     => '',
        'subscription' => '',
        'created'      => 0,
        'verified'     => false,
    ];
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
  /* Inline so this page needs no external stylesheet at all. The canvas is
     written literally rather than read from a token for the same reason —
     theme.css is not loaded here — and it matches theme.css's --canvas. */
  html, body { height: 100%; margin: 0; background: #0a0f1e; color: #94a3b8; }
  body {
    display: flex; align-items: center; justify-content: center; text-align: center;
    font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
    font-size: .9rem;
  }
</style>
</head>
<body>
<?php if ($whopPending !== null): ?>
<p>Whop is confirming your <?= htmlspecialchars(ucfirst($whopPending)) ?> plan&hellip;
  <span style="display:block;margin-top:.7rem;font-size:.78rem;color:#64748b">This usually takes a few seconds. Your plan appears on the dashboard by itself &mdash; you can close this tab.</span>
</p>
<?php else: ?>
<p>Setting up your account&hellip;</p>
<?php endif; ?>
<script>
<?php if ($celebrate !== null): ?>
  // Client-side half of the animation signal. Only emitted once the session is
  // confirmed, so a hand-typed URL can't reach the splash.
  try { sessionStorage.setItem('utl_purchase_ob_' + <?= json_encode($celebrate, JSON_UNESCAPED_SLASHES) ?>, '1'); } catch (e) {}
<?php endif; ?>
  // Must always run — a privacy mode that blocks sessionStorage, a failed
  // verification, or a failed reconcile still has to land the user on their
  // dashboard.
  // Whop's webhook lands a moment after the redirect, so a buyer who arrives
  // early waits here instead of being dropped on a dashboard that still says
  // "Free". The entitlement comes from the webhook, never from this page.
  if (<?= $whopPending !== null ? 'true' : 'false' ?>) {
    setTimeout(function () { window.location.replace('/portal/index.php'); }, 4000);
  } else {
    window.location.replace('/portal/index.php');
  }
</script>
<noscript>
  <meta http-equiv="refresh" content="0;url=/portal/index.php">
</noscript>
</body>
</html>
