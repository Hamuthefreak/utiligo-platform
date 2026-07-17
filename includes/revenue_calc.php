<?php
/**
 * includes/revenue_calc.php — Shared revenue projection math.
 */
require_once __DIR__ . '/../config.php';

function calculate_revenue(int $sitesSold, float $pricePerSite): array
{
    $sitesSold = max(0, $sitesSold);
    $pricePerSite = max(0, $pricePerSite);
    $gross = $sitesSold * $pricePerSite;
    $netProfit = $gross - PRO_PLAN_PRICE;

    return [
        'sites_sold' => $sitesSold,
        'price_per_site' => $pricePerSite,
        'gross_revenue' => $gross,
        'subscription_cost' => PRO_PLAN_PRICE,
        'net_profit' => $netProfit,
        'breakdown_text' => "{$sitesSold} websites x $" . number_format($pricePerSite, 0) . " = $" . number_format($gross, 0),
    ];
}
