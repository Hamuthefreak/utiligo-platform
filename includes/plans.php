<?php
/**
 * includes/plans.php — Feature gating logic based on plan (free vs pro).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

const FEATURE_MAP = [
    'unlimited_leads' => ['pro'],
    'website_generation' => ['pro'],
    'zip_export' => ['pro'],
    'white_label' => ['pro'],
    'custom_logo' => ['pro'],
    'revenue_dashboard' => ['pro'],
    'basic_dashboard' => ['free', 'pro'],
];

function has_feature(string $feature, string $plan): bool
{
    return in_array($plan, FEATURE_MAP[$feature] ?? [], true);
}

function free_lead_limit(): int
{
    return FREE_LEAD_LIMIT;
}

function plan_label(string $plan): string
{
    return $plan === 'pro' ? 'Pro' : 'Free';
}

function require_pro(): void
{
    require_login();
    $user = current_user();
    if ($user['plan'] !== 'pro') {
        header('Location: /portal/billing.php?upgrade=1');
        exit;
    }
}
