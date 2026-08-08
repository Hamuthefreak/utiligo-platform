<?php
/**
 * terms.php — Utiligo Terms of Service.
 */
$pageTitle = 'Terms of Service — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-3xl mx-auto px-6 py-16">
  <h1 class="text-3xl font-bold mb-2">Terms of Service</h1>
  <p class="text-slate-500 text-sm mb-10">Last updated: <?= date('F j, Y') ?></p>

  <div class="prose prose-invert prose-slate max-w-none text-slate-300 leading-relaxed space-y-5">

    <p>Welcome to Utiligo. These Terms of Service ("Terms") govern your use of the Utiligo website and services (the "Service") operated by Utiligo ("us", "we", or "our"). By creating an account or otherwise using the Service, you agree to be bound by these Terms. If you do not agree, you may not use the Service.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">1. Eligibility</h2>
    <p>You must be at least 16 years old and legally able to enter into a binding contract to use the Service. By using the Service you represent and warrant that you meet these requirements.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">2. Your Account</h2>
    <ul class="list-disc pl-6 space-y-1.5">
      <li>You must provide accurate and complete information when creating your account, and keep it up to date.</li>
      <li>You are responsible for safeguarding your password and for all activity that occurs under your account.</li>
      <li>You agree to notify us immediately of any unauthorized use of your account. We cannot and will not be liable for any loss or damage arising from your failure to comply with this obligation.</li>
      <li>One person or entity may not maintain multiple accounts for the purpose of circumventing plan limits or rate caps.</li>
    </ul>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">3. The Service</h2>
    <p>Utiligo is a SaaS platform that (a) surfaces local businesses that may not have a website, using the Google Places API, and (b) generates a static, deployable website based on the business details you provide. Plans differ in lead quota, generated-site quota, available templates, and additional features as described on our <a href="/#pricing" class="text-emerald-400 hover:underline">pricing page</a>. We reserve the right to update quotas, pricing, and feature availability at any time; changes will not retroactively apply to a billing period you have already paid for.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">4. Acceptable Use</h2>
    <p>You agree <em>not</em> to:</p>
    <ul class="list-disc pl-6 space-y-1.5">
      <li>Use the Service for any unlawful, fraudulent, or abusive purpose, or in violation of any applicable law.</li>
      <li>Attempt to reverse-engineer, decompile, scrape, or otherwise extract data from the Service beyond the explicit export features the Service provides.</li>
      <li>Resell, sublicense, lease, or share your account credentials or quotas with third parties outside of the team-seat allowances of an Entrepreneur plan.</li>
      <li>Generate websites that infringe on the rights of any third party, including copyright, trademark, privacy, or publicity rights, or that promote illegal, hateful, harassing, or harmful content.</li>
      <li>Submit queries designed to overload Google Places API, our databases, or any sub-processor's infrastructure.</li>
      <li>Circumvent rate limits, bans, or other security controls.</li>
    </ul>
    <p>Violations may result in immediate suspension or termination of your account, without refund, as described in Section 8.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">5. Generated Content & Ownership</h2>
    <ul class="list-disc pl-6 space-y-1.5">
      <li>You retain ownership of all business data you submit, leads you generate, and the website output the Service produces from your inputs.</li>
      <li>The website HTML, CSS, and JavaScript files generated for you are licensed to you on a perpetual, royalty-free basis. You are free to edit, host, sell, or transfer those files — including as-is inside a ZIP export — to your clients.</li>
      <li>You grant Utiligo a limited license to process your inputs through the Google Places and template-generation pipelines solely to provide the Service to you.</li>
      <li>You are solely responsible for the accuracy, legality, and appropriateness of the business data you submit, and for any obligations (including tax) arising from sites you sell to third parties.</li>
    </ul>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">6. Subscriptions, Billing, and Payment</h2>
    <ul class="list-disc pl-6 space-y-1.5">
      <li>Paid plans are billed monthly in advance via Stripe. Your subscription renews automatically on the same calendar day each month until cancelled.</li>
      <li>You may cancel your subscription at any time from the Portal &rarr; Billing page. Cancellation stops future renewals; your plan remains active through the end of the current paid billing period.</li>
      <li>Price changes will be announced with at least 30 days' notice. Existing subscribers keep the previous rate through the end of the then-current billing period.</li>
      <li>Failed payments may result in a "past_due" status. If a payment is not resolved within a reasonable window, your plan may automatically downgrade to Free.</li>
    </ul>
    <p>See our <a href="/refund-policy.php" class="text-emerald-400 hover:underline">Refund Policy</a> for information about returns and exceptions.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">7. Free Trial / Free Tier</h2>
    <p>The Free tier can be used indefinitely within published quotas and does not require a credit card. We may modify the Free tier's limits, templates, or feature set at any time. Free-tier usage is not a trial of a paid plan and does not guarantee continued availability of any specific feature.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">8. Suspension and Termination</h2>
    <p>We may suspend or terminate your account if you breach these Terms, if your account is compromised, or if required to protect the Service or other users. You may close your account at any time by emailing <a href="mailto:support@utiligo.ca" class="text-emerald-400 hover:underline">support@utiligo.ca</a> or via the in-app account-deletion option when available. Upon termination for cause, no refund is owed. Upon voluntary termination or termination without cause, refunds (if any) are governed by our Refund Policy.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">9. Intellectual Property</h2>
    <p>The Service itself, including the Utiligo name, logo, source code (other than site templates that are licensed to you per Section 5), design, and underlying infrastructure remain the exclusive property of Utiligo and its licensors. Nothing in these Terms grants you any right to use our trademarks or trade dress except as authorized in writing.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">10. Disclaimers</h2>
    <p>The Service is provided "as is" and "as available". We make no warranty that the Service will be uninterrupted, error-free, or fit for any particular purpose. Lead-finding results depend on the availability and accuracy of third-party data sources (Google Places) over which we have no control. Generated websites are produced algorithmically and may contain errors; you are responsible for reviewing output before presenting it to any client.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">11. Limitation of Liability</h2>
    <p>To the fullest extent permitted by law, Utiligo shall not be liable for any indirect, incidental, special, consequential, or punitive damages, or for any loss of profits, data, or business opportunity, arising out of or in connection with the Service. Our total aggregate liability under any claim shall not exceed the amount you paid to us in the 12 months preceding the event giving rise to the claim.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">12. Indemnification</h2>
    <p>You agree to indemnify and hold Utiligo and its operators harmless from any claims, damages, or expenses (including reasonable attorneys' fees) arising from your use of the Service, your violation of these Terms, or your infringement of any third-party rights in connection with the websites you generate or data you submit.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">13. Governing Law</h2>
    <p>These Terms are governed by the laws of Canada and the Province of Ontario, without regard to conflict-of-law principles. The courts of Ontario shall have exclusive jurisdiction over any disputes arising under these Terms.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">14. Changes to These Terms</h2>
    <p>We may revise these Terms from time to time. Material changes will be announced by email or via a notice on the site at least 30 days before they take effect. Continued use of the Service after the effective date constitutes acceptance of the revised Terms.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">15. Contact</h2>
    <p>Questions about these Terms can be sent to <a href="mailto:support@utiligo.ca" class="text-emerald-400 hover:underline">support@utiligo.ca</a>.</p>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
