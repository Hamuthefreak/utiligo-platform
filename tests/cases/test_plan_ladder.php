<?php
/**
 * The plan ladder.
 *
 * THE INVARIANT: free ⊂ pro ⊂ entrepreneur.  Entrepreneur is the top tier, so
 * every capability and every limit Pro has, Entrepreneur has too — at least as
 * much of it.  Nothing may be scoped DOWN.
 *
 * WHY THIS FILE EXISTS
 * ───────────────────
 * Call scripts were briefly scoped to Pro alone.  Every individual line of that
 * change was defensible and it passed a suite of 730 tests, because the suite
 * asserted each gate where it lived rather than asserting the SHAPE of the plan
 * table.  The bug was not in any one gate: it was that upgrading a plan could
 * take a feature away, and there was nothing anywhere that said that must never
 * happen.
 *
 * It is also not a hypothetical.  plans.php already carried a live example of
 * exactly this drift — Pro's feature list contained `lead_enrich_basic` and
 * Entrepreneur's contained only `lead_enrich_full`, so Pro's set was not a
 * subset of Entrepreneur's.  Nothing read that array, so nothing broke; it took
 * reading it properly to notice.
 *
 * So this file asserts the ladder as a PROPERTY rather than gate by gate:
 *
 *   - every numeric limit is monotonic (with -1 meaning unlimited, i.e. largest)
 *   - every string list of sources / formats / providers grows
 *   - every named capability predicate that is true for Pro is true for
 *     Entrepreneur
 *   - the declarative feature set is a subset at each step
 *
 * A new gate added tomorrow is covered the moment it goes through the helpers,
 * and a deliberately Ent-only feature (can_schedule_searches) is fine — the
 * ladder only forbids going backwards, never forward.
 */

/* ─────────────────────────────────────────────────────────────────────────────
 * Limits are monotonic
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Every limit grows as the plan does');

/** -1 means unlimited, which is the top of the scale, not the bottom. */
$rank = static function (int $value): int {
    return $value === -1 ? PHP_INT_MAX : $value;
};

$numeric_limits = [
    'lead_limit'          => 'leads',
    'site_limit'          => 'active sites',
    'search_daily'        => 'searches per day',
    'generate_daily'      => 'site generations per day',
    'template_limit'      => 'templates',
    'team_seats'          => 'team seats',
    'custom_domain_limit' => 'custom domains',
    'export_daily'        => 'exports per day',
    'export_max_rows'     => 'rows per export',
];

$config = plan_config();

foreach ($numeric_limits as $key => $label) {
    $free = (int)($config['free'][$key] ?? 0);
    $pro  = (int)($config['pro'][$key] ?? 0);
    $ent  = (int)($config['entrepreneur'][$key] ?? 0);

    t_ok($rank($pro) >= $rank($free),
        $label . ': Pro is at least Free (' . $free . ' → ' . $pro . ')');
    t_ok($rank($ent) >= $rank($pro),
        $label . ': Entrepreneur is at least Pro (' . $pro . ' → ' . $ent . ')');
}

// The CRM cap is not in the config array — it has its own accessor because it
// was added later, which is precisely how a limit ends up outside a guard.
t_ok($rank(plan_client_limit('pro')) >= $rank(plan_client_limit('free')),
    'CRM clients: Pro is at least Free');
t_ok($rank(plan_client_limit('entrepreneur')) >= $rank(plan_client_limit('pro')),
    'CRM clients: Entrepreneur is at least Pro');

/* ─────────────────────────────────────────────────────────────────────────────
 * Lists grow
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Every list of sources, formats and providers grows');

$list_gates = [
    'lead sources'      => 'plan_lead_sources',
    'export formats'    => 'plan_export_formats',
    'enrich providers'  => 'plan_enrich_providers',
];

foreach ($list_gates as $label => $fn) {
    $free = $fn('free');
    $pro  = $fn('pro');
    $ent  = $fn('entrepreneur');

    t_same_list(array_values(array_diff($free, $pro)), [],
        $label . ': Free grants nothing Pro does not have');
    t_same_list(array_values(array_diff($pro, $ent)), [],
        $label . ': Entrepreneur grants everything Pro does');
}

// A concrete check that the diff above cannot be satisfied by everything being
// empty — the failure mode where a missing constant silently becomes ''.
t_ok(count(plan_export_formats('entrepreneur')) > 0, 'Entrepreneur can export something');
t_ok(count(plan_lead_sources('entrepreneur')) > 0, 'and can search some sources');
t_ok(count(plan_enrich_providers('entrepreneur')) > 0, 'and can enrich');
t_ok(count(plan_export_formats('entrepreneur')) >= count(plan_export_formats('pro')),
    'with at least the formats Pro gets');

/* ─────────────────────────────────────────────────────────────────────────────
 * Capability predicates
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Nothing that is true for Pro is false for Entrepreneur');

// Every boolean gate in the product. A gate that is true for Pro and false for
// Entrepreneur is the exact bug this file exists for — it makes upgrading a
// downgrade. Add new gates here as they are written.
$predicates = [
    'is_paid_plan'          => 'is_paid_plan',
    'plan_has_pro_features' => 'plan_has_pro_features',
    'can_use_lead_workspace'=> 'can_use_lead_workspace',
    'can_use_call_scripts'  => 'can_use_call_scripts',
];

foreach ($predicates as $label => $fn) {
    $free = (bool)$fn('free');
    $pro  = (bool)$fn('pro');
    $ent  = (bool)$fn('entrepreneur');

    t_ok(!$pro || $ent, $label . ': true for Pro means true for Entrepreneur');
    t_ok(!$free || $pro, $label . ': true for Free means true for Pro');
}

// Negative plans must be refused everywhere rather than sweeping in as "paid".
foreach (array_keys($predicates) as $label) {
    t_ok(!$predicates[$label](''), $label . ': refuses an empty plan');
    t_ok(!$predicates[$label](null), $label . ': refuses a null plan');
    t_ok(!$predicates[$label]('platinum'), $label . ': refuses a plan name that does not exist');
}

// The one deliberate Ent-only feature. It is allowed to be an upward addition —
// false for Pro, true for Entrepreneur — because the ladder only forbids going
// backwards. Stated as a test so a future reader cannot mistake it for drift.
t_ok(!can_schedule_searches('pro'), 'scheduling is not a Pro feature');
t_ok(can_schedule_searches('entrepreneur'),
    'and being Ent-only is an addition above the ladder, not a break in it');

/* ─────────────────────────────────────────────────────────────────────────────
 * The declarative feature set
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The feature list is a subset at each step');

// Nothing reads this array today — every gate is a predicate above — but it is
// the written description of the plans, and it had already drifted once.
$freeFeatures = get_plan_config('free')['features'];
$proFeatures  = get_plan_config('pro')['features'];
$entFeatures  = get_plan_config('entrepreneur')['features'];

t_same_list(array_values(array_diff($freeFeatures, $proFeatures)), [],
    'every Free feature is also a Pro feature');
t_same_list(array_values(array_diff($proFeatures, $entFeatures)), [],
    'every Pro feature is also an Entrepreneur feature');

t_ok(count($entFeatures) >= count($proFeatures),
    'and Entrepreneur is not described as having less');
t_ok(count($proFeatures) > count($freeFeatures),
    'while Pro is described as having more than Free, so the lists are not all the same');

/* ─────────────────────────────────────────────────────────────────────────────
 * The gate the API and the layout actually call
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The call-script gate is the paid gate, and not a narrower one');

t_ok(can_use_call_scripts('pro'), 'Pro has call scripts');
t_ok(can_use_call_scripts('entrepreneur'),
    'Entrepreneur has call scripts — scoping this to Pro made upgrading take a feature away');
t_ok(!can_use_call_scripts('free'), 'Free does not');
t_is(can_use_call_scripts('entrepreneur'), plan_has_pro_features('entrepreneur'),
    'the call-script gate is an alias for the paid gate rather than a separate rule');

// If this ever legitimately needs to narrow, it has to be a plan decision with a
// pricing-page consequence — see the docblock on can_use_call_scripts().
t_ok(!is_paid_plan('free'), 'and Free is still not a paying customer');
