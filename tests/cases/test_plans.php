<?php
/**
 * Plan order and ranking.
 *
 * The never-downgrade rule and the SQL rank guard are both built from
 * entitlement_plan_order(), so a wrong or drifting order would silently either
 * block real upgrades or permit downgrades by a stale event. These are cheap
 * assertions guarding something expensive.
 */

t_section('Plan order');

$order = entitlement_plan_order();
t_same_list($order, ['free', 'pro', 'entrepreneur'], 'order is cheapest-first');
t_is($order, array_keys(plan_config()), 'order is derived from plan_config(), not a second copy');
t_is(entitlement_default_plan(), 'free', 'a new account starts on free');
t_ok(in_array(entitlement_default_plan(), $order, true), 'the default plan is a real plan');

t_section('Ranking and normalisation');

t_is(entitlement_plan_rank('free'), 0, 'free ranks 0');
t_is(entitlement_plan_rank('pro'), 1, 'pro ranks 1');
t_is(entitlement_plan_rank('entrepreneur'), 2, 'entrepreneur ranks 2');

t_is(entitlement_plan_rank('  Pro  '), 1, 'surrounding whitespace is ignored');
t_is(entitlement_plan_rank('ENTREPRENEUR'), 2, 'case is ignored');
t_is(entitlement_plan_rank('Platinum'), null, 'an unknown plan is not ranked at all');
t_is(entitlement_plan_rank(''), null, 'an empty plan is not ranked');
t_is(entitlement_plan_rank('0'), null, 'a numeric string is not a plan');
t_is(entitlement_plan_rank('free2'), null, 'a near-miss is not a plan');

t_is(entitlement_normalize_plan(' Entrepreneur '), 'entrepreneur', 'normalisation trims and lowercases');
t_is(entitlement_normalize_plan('gold'), null, 'normalisation rejects unknown plans');

t_section('Upgrade detection');

t_ok(entitlement_is_upgrade('free', 'pro'), 'free → pro is an upgrade');
t_ok(entitlement_is_upgrade('free', 'entrepreneur'), 'free → entrepreneur is an upgrade');
t_ok(entitlement_is_upgrade('pro', 'entrepreneur'), 'pro → entrepreneur is an upgrade');
t_ok(!entitlement_is_upgrade('pro', 'pro'), 'the same plan is not an upgrade');
t_ok(!entitlement_is_upgrade('pro', 'free'), 'pro → free is not an upgrade');
t_ok(!entitlement_is_upgrade('entrepreneur', 'pro'), 'entrepreneur → pro is not an upgrade');
t_ok(!entitlement_is_upgrade('bogus', 'pro'), 'an unknown current plan is not treated as free');
t_ok(!entitlement_is_upgrade('free', 'bogus'), 'an unknown target is not an upgrade');
