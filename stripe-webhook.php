<?php
/**
 * stripe-webhook.php
 * Handles Stripe webhook events.
 * Configure in Stripe Dashboard:
 *   Endpoint URL: https://utiligo.ca/stripe-webhook.php
 *   Events: checkout.session.completed, customer.subscription.deleted,
 *           invoice.payment_failed
 *
 * All plan changes go through includes/entitlements.php, which orders every
 * write by the event's own timestamp. That matters here more than anywhere
 * else, because Stripe explicitly does not guarantee delivery order and will
 * re-deliver on any non-2xx (or on a timeout at its end):
 *
 *   • A retried checkout.session.completed used to re-grant a plan to a
 *     customer who had since cancelled.
 *   • A late customer.subscription.deleted used to revoke a plan the customer
 *     had already re-bought.
 *
 * This handler no longer decides any of that. It extracts what the event says
 * happened and when, and hands it to the module.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/userdb.php';
require_once __DIR__ . '/includes/entitlements.php';
require_once __DIR__ . '/includes/stripe_api.php';

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$secret    = STRIPE_WEBHOOK_SECRET;

// Verify the signature AND that the request is recent. Live in
// includes/stripe_api.php so it is unit-testable without executing this
// endpoint: with a real secret configured, an unsigned request is rejected.
if (!stripe_webhook_verify_signature($payload, $sigHeader, $secret)) {
    http_response_code(400);
    exit('Signature verification failed.');
}

$event = json_decode($payload, true);
if (!$event || !is_array($event)) { http_response_code(400); exit('Bad JSON'); }

$type = (string)($event['type'] ?? '');
$obj  = $event['data']['object'] ?? [];
if (!is_array($obj)) { $obj = []; }

// The event's own identity and time. `created` is the moment the thing
// happened, which is what the entitlement guards order on — never the arrival
// time of this request.
$eventId = (string)($event['id'] ?? '');
$eventAt = (int)($event['created'] ?? 0);

$customerId = (string)($obj['customer'] ?? '');
// client_reference_id is stamped server-side by stripe-checkout.php. The
// metadata copy is the same value (also set server-side, and carried onto the
// subscription via subscription_data), and covers subscription-lifecycle events
// whose object has no client_reference_id.
$userId = (int)($obj['client_reference_id'] ?? 0);
if ($userId <= 0) {
    $userId = (int)($obj['metadata']['user_id'] ?? 0);
}

/**
 * For subscription lifecycle events the object IS the subscription, so its `id`
 * is the id we care about. For a checkout session the session and subscription
 * ids differ, and the session's `subscription` field is the right one.
 */
$subscriptionId = $type === 'checkout.session.completed'
    ? (string)($obj['subscription'] ?? '')
    : (string)($obj['id'] ?? '');

/**
 * Match subscription events on the Stripe customer, not on metadata: the
 * customer id is what Stripe itself guarantees, whereas metadata is editable
 * from the dashboard. Only fall back to the metadata user id when the event
 * carries no customer id at all.
 */
$matchByUser     = $customerId === '' ? $userId : 0;
$matchByCustomer = $customerId;

switch ($type) {

    case 'checkout.session.completed':
        // Upgrade user plan on successful payment.
        $plan = entitlement_normalize_plan((string)($obj['metadata']['plan'] ?? ''));

        if ($userId <= 0 || $plan === null) {
            // Nothing to act on — but still a 200, or Stripe retries forever.
            entitlement_log('webhook.checkout', 'ignored event ' . $eventId
                . ': user=' . ($userId ?: 'none') . ' plan=' . (string)($obj['metadata']['plan'] ?? ''));
            break;
        }

        entitlement_grant_from_stripe($userId, $plan, [
            'customer_id'     => $customerId,
            'subscription_id' => $subscriptionId,
            'event_at'        => $eventAt ?: null,
            'source'          => 'webhook.checkout.session.completed',
        ]);
        break;

    case 'customer.subscription.deleted':
        // Downgrade to free on subscription cancellation/expiry. Guarded on the
        // subscription id, so the deletion of an OLD subscription cannot revoke
        // one the customer has since bought.
        if ($matchByCustomer === '' && $matchByUser <= 0) {
            entitlement_log('webhook.deleted', 'ignored event ' . $eventId . ': no customer or user');
            break;
        }

        entitlement_cancel_from_stripe([
            'user_id'         => $matchByUser,
            'customer_id'     => $matchByCustomer,
            'subscription_id' => $subscriptionId,
            'event_at'        => $eventAt ?: null,
            'source'          => 'webhook.customer.subscription.deleted',
        ]);
        break;

    case 'invoice.payment_failed':
        // Flag the user. Status only: the customer keeps their plan while
        // Stripe's dunning runs.
        if ($matchByCustomer === '' && $matchByUser <= 0) {
            entitlement_log('webhook.past_due', 'ignored event ' . $eventId . ': no customer or user');
            break;
        }

        entitlement_flag_past_due([
            'user_id'         => $matchByUser,
            'customer_id'     => $matchByCustomer,
            'subscription_id' => $subscriptionId,
            'event_at'        => $eventAt ?: null,
            'source'          => 'webhook.invoice.payment_failed',
        ]);
        break;
}

http_response_code(200);

echo json_encode(['received' => true]);
