<?php
/**
 * purchase-success.php
 * Stripe redirects here after a successful checkout.
 * Sets sessionStorage flag for the purchase animation, then
 * loads the portal (animation runs client-side on that page).
 *
 * Stripe passes: ?plan=pro|entrepreneur&session_id=...
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

if (!is_logged_in()) {
    header('Location: /login.php');
    exit;
}

$allowed = ['free','pro','entrepreneur'];
$plan    = isset($_GET['plan']) && in_array($_GET['plan'], $allowed) ? $_GET['plan'] : 'free';

// Persist animation signal to portal
$_SESSION['purchase_animation_plan'] = $plan;

$pageTitle = 'Welcome to Utiligo — ' . ucfirst($plan);
require_once __DIR__ . '/includes/header.php';
?>
<!-- This page briefly renders, then JS redirects to portal.
     The onboarding animation plays ON the portal page. -->
<link rel="stylesheet" href="/assets/css/onboarding.css">
<script>
  // Set sessionStorage NOW (before portal redirect)
  sessionStorage.setItem('utl_purchase_ob_<?= htmlspecialchars($plan) ?>', '1');
  window.location.replace('/portal/index.php');
</script>
<noscript>
  <meta http-equiv="refresh" content="0;url=/portal/index.php">
</noscript>
