<?php
/**
 * refund-policy.php — Utiligo Refund Policy.
 */
$pageTitle = 'Refund Policy — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-3xl mx-auto px-6 py-16">
  <h1 class="text-3xl font-bold mb-2">Refund Policy</h1>
  <p class="text-slate-500 text-sm mb-10">Last updated: <?= date('F j, Y') ?></p>

  <div class="prose prose-invert prose-slate max-w-none text-slate-300 leading-relaxed space-y-5">

    <p>We want you to be confident when subscribing to Utiligo. This Refund Policy explains when payments are and are not eligible for a refund. By making any payment to Utiligo, you agree to the terms below.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">1. Default: No Refunds</h2>
    <div class="bg-white/5 border-l-4 border-amber-500/40 px-5 py-4 rounded-r-xl my-4">
      <p class="text-amber-100 font-semibold mb-1">All payments to Utiligo are non-refundable by default.</p>
      <p class="text-slate-300 text-sm">Refunds are issued, in Utiligo's sole discretion, only when an administrator judges that a refund is appropriate under the conditions described in Section 3.</p>
    </div>
    <p>Because Utiligo delivers access to live external data sources (Google Places API), generates downloadable static websites, and exposes premium tools the moment your subscription activates, we treat each paid billing period as a fully-delivered service. Charges already incurred for usage that has taken place will not be reversed, except under Section 3.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">2. Cancellation vs. Refund</h2>
    <ul class="list-disc pl-6 space-y-1.5">
      <li><strong class="text-white">Cancellation</strong> stops future renewals. You keep access to your paid plan until the end of the current billing period, after which your account automatically reverts to the Free tier.</li>
      <li><strong class="text-white">Refund</strong> returns (part of) an amount already charged. A refund, if any, is decided on a case-by-case basis by an administrator.</li>
      <li>Cancelling a subscription does <em>not</em> automatically entitle you to a refund of any prior charge.</li>
    </ul>
    <p>You can cancel any time from <em>Portal &rarr; Billing &rarr; Cancel subscription</em>. No further action is required and no notice period applies.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">3. When an Administrator May Approve a Refund</h2>
    <p>Refunds are <strong class="text-white">not guaranteed</strong> and are granted only at an administrator's discretion. An administrator may, but is not required to, issue a refund if one or more of the following applies:</p>
    <ul class="list-disc pl-6 space-y-1.5">
      <li>You were charged for a subscription renewal you did not intend to keep and you request a refund within 7 days of the renewal, <em>and</em> you have made no significant paid-tier use of the Service since that charge (e.g., no new lead searches, site generations, or ZIP exports attributable to the renewed period).</li>
      <li>The Service suffered a sustained, documented outage that prevented you from using the paid features for more than 5 consecutive days within a single billing period.</li>
      <li>You were charged in clear error (e.g., duplicate Stripe charge, charge after a successful cancellation, or charge for a plan you never activated).</li>
      <li>Other exceptional circumstances that an administrator, in its sole judgment, determines warrant a refund in fairness to the user and the platform.</li>
    </ul>
    <p>Any refund granted may be partial and may reflect the paid features already used during the billing period. Approved refunds will be returned to the original payment method within 5–10 business days, subject to Stripe's processing time.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">4. How to Request a Refund</h2>
    <p>To request a refund, email <a href="mailto:support@utiligo.ca" class="text-emerald-400 hover:underline">support@utiligo.ca</a> with the subject line "Refund Request" and include:</p>
    <ul class="list-disc pl-6 space-y-1.5">
      <li>The email address on your Utiligo account</li>
      <li>The date of the charge you are disputing</li>
      <li>A short explanation of why you believe a refund is appropriate</li>
    </ul>
    <p>Requests should be submitted within 30 days of the disputed charge. An administrator will review and respond by email within 5 business days. The administrator's decision is final.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">5. Account Bans or Terminations</h2>
    <p>If your account is suspended or terminated for a violation of our <a href="/terms.php" class="text-emerald-400 hover:underline">Terms of Service</a>, no refund is owed, and the administrator may, at its sole discretion, withhold any pending refund request associated with that account.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">6. Chargebacks</h2>
    <p>Initiating a credit-card chargeback without first contacting us at <a href="mailto:support@utiligo.ca" class="text-emerald-400 hover:underline">support@utiligo.ca</a> may be treated as a violation of these Terms and can result in immediate account suspension pending review. We are committed to resolving disputes directly when given the chance.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">7. Contact</h2>
    <p>Questions about this Refund Policy can be sent to <a href="mailto:support@utiligo.ca" class="text-emerald-400 hover:underline">support@utiligo.ca</a>.</p>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
