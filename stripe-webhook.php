<?php
/**
 * stripe-webhook.php
 * Handles Stripe webhook events.
 * Configure in Stripe Dashboard:
 *   Endpoint URL: https://utiligo.ca/stripe-webhook.php
 *   Events: checkout.session.completed, customer.subscription.deleted
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/userdb.php';

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret    = STRIPE_WEBHOOK_SECRET;

// Verify Stripe signature
if (!_stripe_verify_signature($payload, $sigHeader, $secret)) {
    http_response_code(400);
    exit('Signature verification failed.');
}

$event = json_decode($payload, true);
if (!$event) { http_response_code(400); exit('Bad JSON'); }

$type = $event['type'] ?? '';
$obj  = $event['data']['object'] ?? [];

$userdb = get_user_db();

switch ($type) {

    case 'checkout.session.completed':
        // Upgrade user plan on successful payment
        $userId = (int)($obj['client_reference_id'] ?? 0);
        $plan   = $obj['metadata']['plan'] ?? '';
        if ($userId && in_array($plan, ['pro', 'entrepreneur'])) {
            try {
                $userdb->prepare("
                    UPDATE utiligo_users
                    SET plan=?, subscription_status='active',
                        stripe_customer_id=?,
                        subscription_started_at=NOW()
                    WHERE id=?
                ")->execute([$plan, $obj['customer'] ?? null, $userId]);
            } catch (\Throwable $e) {
                // Fallback without stripe_customer_id column if not yet migrated
                try {
                    $userdb->prepare("
                        UPDATE utiligo_users
                        SET plan=?, subscription_status='active',
                            subscription_started_at=NOW()
                        WHERE id=?
                    ")->execute([$plan, $userId]);
                } catch (\Throwable $e2) {}
            }
        }
        break;

    case 'customer.subscription.deleted':
        // Downgrade to free on subscription cancellation/expiry
        $customerId = $obj['customer'] ?? '';
        if ($customerId) {
            try {
                $userdb->prepare("
                    UPDATE utiligo_users
                    SET plan='free', subscription_status='cancelled'
                    WHERE stripe_customer_id=?
                ")->execute([$customerId]);
            } catch (\Throwable $e) {}
        }
        break;

    case 'invoice.payment_failed':
        // Optional: flag the user
        $customerId = $obj['customer'] ?? '';
        if ($customerId) {
            try {
                $userdb->prepare("
                    UPDATE utiligo_users SET subscription_status='past_due'
                    WHERE stripe_customer_id=?
                ")->execute([$customerId]);
            } catch (\Throwable $e) {}
        }
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);

// ---- Signature helper (no Stripe SDK needed) ----
function _stripe_verify_signature(string $payload, string $header, string $secret): bool {
    if (!$header || !$secret || str_starts_with($secret, 'YOUR_')) return true; // skip in dev
    $parts = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $parts[$k][] = $v;
    }
    $timestamp = $parts['t'][0] ?? 0;
    $expected  = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($parts['v1'] ?? [] as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}
