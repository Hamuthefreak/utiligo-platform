<?php
/**
 * The SQL that hands out paid features, asserted without a database.
 *
 * entitlement_statement() is pure precisely so this is possible: these are the
 * same guards a live UPDATE would enforce, checked here in milliseconds and on
 * every run, including on machines with no MySQL at all.
 */

/** Build a statement the way entitlement_apply() would, with test defaults. */
function t_stmt(array $change, array $omit = []): array
{
    $c = $change + [
        'user_id'            => 0,
        'customer_id'        => '',
        'subscription_id'    => '',
        'plan'               => null,
        'status'             => null,
        'event_at'           => null,
        'allow_downgrade'    => false,
        'store_customer'     => false,
        'match_subscription' => false,
        'started_at'         => false,
        'source'             => 'test',
    ];

    // Mirrors entitlement_apply()'s normalisation exactly, or the tests would be
    // asserting a shape the real caller never produces: a blank status is
    // normalised to "no change", not written as an empty string.
    $plan   = $c['plan'] === null ? null : entitlement_normalize_plan((string)$c['plan']);
    $status = $c['status'] === null ? null : strtolower(trim((string)$c['status']));
    if ($status === '') {
        $status = null;
    }

    return entitlement_statement($c, $plan, $status, $omit);
}

t_section('The plan list in SQL cannot drift from the config');

$expectedList = "'" . implode("', '", plan_config() ? array_keys(plan_config()) : []) . "'";
t_is(entitlement_plan_sql_list(), $expectedList, 'the FIELD() list is generated from plan_config()');
t_is(entitlement_plan_sql_list(), "'free', 'pro', 'entrepreneur'", 'the list is the three real plans');

$rank = entitlement_rank_sql('plan');
t_like($rank, 'FIELD(', 'the rank expression is a FIELD() lookup');
t_like($rank, "COALESCE(NULLIF(LOWER(TRIM(plan)), ''), 'free')", 'empty/NULL reads as the default tier');
t_like($rank, "LOWER(TRIM(plan))", 'the column is normalised, matching the PHP side');

t_section('A verified grant (the webhook and the success page)');

$grant = [
    'user_id'         => 7,
    'plan'            => 'pro',
    'status'          => 'active',
    'customer_id'     => 'cus_1',
    'subscription_id' => 'sub_1',
    'event_at'        => 1700000000,
    'store_customer'  => true,
    'started_at'      => true,
];
[$sql, $params] = t_stmt($grant);

t_like($sql, 'UPDATE utiligo_users SET', 'it is an UPDATE of the users table');
t_like($sql, 'plan = ?', 'the plan is bound, never interpolated');
t_like($sql, 'subscription_status = ?', 'the status is bound');
t_like($sql, 'subscription_started_at = NOW()', 'the subscription start is stamped');
t_like($sql, 'stripe_customer_id = COALESCE(NULLIF(?, \'\'), stripe_customer_id)',
    'an empty customer id cannot clobber a stored one');
t_like($sql, 'subscription_event_at = FROM_UNIXTIME(?)', 'the event clock is advanced');
t_like($sql, 'id = ?', 'it targets one account');

t_section('The ordering guard');

t_like($sql, 'subscription_event_at IS NULL OR subscription_event_at < FROM_UNIXTIME(?)',
    'a grant only applies when it is strictly newer');
t_unlike($sql, '<= FROM_UNIXTIME', 'the comparison is strict, so a retry of the same event is a no-op');

[$noEventSql, ] = t_stmt(['user_id' => 7, 'plan' => 'pro', 'status' => 'active']);
t_unlike($noEventSql, 'FROM_UNIXTIME', 'no event time means no ordering guard at all');

t_section('The never-downgrade guard');

t_like($sql, "FIELD(COALESCE(NULLIF(LOWER(TRIM(plan)), ''), 'free'), 'free', 'pro', 'entrepreneur') > 0",
    'an unrecognised stored plan is left alone');
t_like($sql, "< FIELD(?, 'free', 'pro', 'entrepreneur')", 'the target must rank above the current plan');

[$freeSql, ] = t_stmt(['user_id' => 7, 'plan' => 'free', 'allow_downgrade' => true]);
t_unlike($freeSql, '< FIELD(', 'a downgrade is allowed when the caller says so');

t_section('There is no cancelled-status guard, on purpose');

// A "refuse if the account is cancelled" guard looks sensible and is wrong:
// it cannot tell a replayed URL from a real purchase, so it locks out the
// customer who cancels and then buys again. The event clock distinguishes the
// two cases exactly, so the status must not be consulted at all.
t_unlike($sql, "<> 'cancelled'", 'a grant does not consult the subscription status');
t_unlike($sql, 'subscription_status IS', 'and does not test it for NULL either');
t_like($sql, 'subscription_event_at IS NULL OR subscription_event_at < FROM_UNIXTIME(?)',
    'the event clock is the guard instead');

[$cancelSql, ] = t_stmt([
    'user_id' => 7, 'plan' => 'free', 'status' => 'cancelled',
    'allow_downgrade' => true,
]);
t_unlike($cancelSql, '< FIELD(', 'a cancellation is not blocked by the rank guard');
t_like($cancelSql, 'subscription_status = ?', 'a cancellation writes the status');

t_section('Placeholders and parameter order');

t_is(substr_count($sql, '?'), count($params), 'every placeholder has exactly one bound parameter');
t_is($params, ['pro', 'active', 'cus_1', 'sub_1', 1700000000, 7, 'pro', 1700000000],
    'parameters are in the order the placeholders appear');

t_section('Subscription identity (the re-subscribe case)');

[$subMatchSql, $subMatchParams] = t_stmt([
    'user_id' => 0, 'customer_id' => 'cus_1', 'subscription_id' => 'sub_old',
    'plan' => 'free', 'status' => 'cancelled',
    'allow_downgrade' => true, 'match_subscription' => true,
]);
t_like($subMatchSql, 'stripe_customer_id = ?', 'lifecycle events match on the customer');
t_like($subMatchSql, '(stripe_subscription_id IS NULL OR stripe_subscription_id = ?)',
    'and on the subscription, allowing a legacy NULL');
t_is(substr_count($subMatchSql, '?'), count($subMatchParams), 'parameters still line up');

t_section('Schema-drift fallbacks (whole column groups omitted)');

[$noCustomerSql, $noCustomerParams] = t_stmt($grant, ['stripe_customer_id']);
t_unlike($noCustomerSql, 'stripe_customer_id', 'the customer column can be dropped entirely');
t_is(substr_count($noCustomerSql, '?'), count($noCustomerParams), 'placeholders still line up after a drop');

[$noEventColSql, ] = t_stmt($grant, ['stripe_customer_id', 'stripe_subscription_id', 'subscription_event_at']);
t_unlike($noEventColSql, 'subscription_event_at', 'the event column can be dropped');
t_unlike($noEventColSql, 'FROM_UNIXTIME', 'and its guard goes with it');

[$minimalSql, $minimalParams] = t_stmt($grant,
    ['stripe_customer_id', 'stripe_subscription_id', 'subscription_event_at', 'subscription_started_at']);
t_unlike($minimalSql, 'subscription_started_at', 'the start column can be dropped');
t_like($minimalSql, 'plan = ?', 'the plan write itself never gets dropped');
t_is(substr_count($minimalSql, '?'), count($minimalParams), 'placeholders still line up in the minimal form');

t_section('Refusals');

t_is(t_stmt(['user_id' => 0, 'plan' => 'pro']), ['', []], 'no identity means no statement');
t_is(t_stmt(['user_id' => 7]), ['', []], 'nothing to change means no statement');
t_is(t_stmt(['user_id' => 7, 'status' => '']), ['', []], 'an empty status is not a change');

t_section('Local (non-Stripe) changes');

$local = entitlement_statement(
    [
        'user_id' => 7, 'customer_id' => '', 'subscription_id' => '',
        'plan' => 'pro', 'status' => 'active', 'event_at' => time(),
        'allow_downgrade' => true, 'store_customer' => false,
        'match_subscription' => false, 'started_at' => true,
        'source' => 'test',
    ],
    'pro', 'active'
);
t_like($local[0], 'subscription_event_at = FROM_UNIXTIME(?)',
    'an admin or test-mode change still moves the ordering clock');
t_unlike($local[0], '< FIELD(', 'and, being authoritative, is not held back by the rank guard');

// A moderation-only status change leaves the clock alone, so it cannot silently
// block a genuine cancellation Stripe has already sent.
[$statusOnly, ] = t_stmt([
    'user_id' => 7, 'status' => 'banned', 'allow_downgrade' => true,
]);
t_unlike($statusOnly, 'FROM_UNIXTIME', 'a ban/unban does not move the ordering clock');
t_like($statusOnly, 'subscription_status = ?', 'it writes only the status');
t_unlike($statusOnly, 'plan = ?', 'and never the plan');
