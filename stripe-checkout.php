<?php
/**
 * stripe-checkout.php
 * Creates a Stripe Checkout Session and redirects the user.
 * Called via POST form from portal/billing.php (non-test mode).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/stripe_api.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /portal/billing.php');
    exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    die('Invalid session.');
}

$allowed = ['pro', 'entrepreneur'];
$plan    = in_array($_POST['plan'] ?? '', $allowed) ? $_POST['plan'] : null;
if (!$plan) {
    header('Location: /portal/billing.php');
    exit;
}

$priceId = ($plan === 'entrepreneur') ? STRIPE_ENT_PRICE_ID : STRIPE_PRO_PRICE_ID;

if (!$priceId || str_starts_with($priceId, 'YOUR_')) {
    // Price ID not configured yet — fall back gracefully
    header('Location: /portal/billing.php?stripe_error=not_configured');
    exit;
}

$user = current_user();

// Build the Checkout Session through includes/stripe_api.php (raw cURL, no
// Composer, no Stripe SDK). The session is stamped server-side with the payer
// (client_reference_id) and the plan actually bought (metadata.plan) — that
// server-side stamp is what stripe-webhook.php and purchase-success.php later
// trust, and why neither of them may believe a `?plan` parameter.
$response = stripe_request('POST', 'v1/checkout/sessions',
    stripe_checkout_session_params($user, $plan, $priceId, APP_BASE_URL));

if (!$response['ok'] || empty($response['data']['url'])) {
    $errMsg = urlencode($response['data']['error']['message'] ?? ($response['error'] ?: 'stripe_error'));
    header('Location: /portal/billing.php?upgrade=1&plan=' . urlencode($plan) . '&stripe_error=' . $errMsg);
    exit;
}

// Redirect to Stripe-hosted Checkout
header('Location: ' . $response['data']['url']);
exit;
