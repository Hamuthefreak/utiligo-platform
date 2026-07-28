<?php
/**
 * stripe-checkout.php
 * Creates a Stripe Checkout Session and redirects the user.
 * Called via POST form from portal/billing.php (non-test mode).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

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

$user       = current_user();
$successUrl = APP_BASE_URL . '/purchase-success.php?plan=' . urlencode($plan) . '&session_id={CHECKOUT_SESSION_ID}';
$cancelUrl  = APP_BASE_URL . '/portal/billing.php?upgrade=1&plan=' . urlencode($plan) . '&cancelled=1';

// Build Stripe Checkout Session via raw cURL (no Composer required)
$payload = http_build_query([
    'mode'                                    => 'subscription',
    'line_items[0][price]'                    => $priceId,
    'line_items[0][quantity]'                 => '1',
    'customer_email'                          => $user['email'],
    'client_reference_id'                     => (string)$user['id'],
    'metadata[plan]'                          => $plan,
    'metadata[user_id]'                       => (string)$user['id'],
    'success_url'                             => $successUrl,
    'cancel_url'                              => $cancelUrl,
    'subscription_data[metadata][plan]'       => $plan,
    'subscription_data[metadata][user_id]'    => (string)$user['id'],
]);

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$data = json_decode($response, true);

if ($httpCode !== 200 || empty($data['url'])) {
    $errMsg = urlencode($data['error']['message'] ?? 'stripe_error');
    header('Location: /portal/billing.php?upgrade=1&plan=' . urlencode($plan) . '&stripe_error=' . $errMsg);
    exit;
}

// Redirect to Stripe-hosted Checkout
header('Location: ' . $data['url']);
exit;
