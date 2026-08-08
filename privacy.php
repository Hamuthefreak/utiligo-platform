<?php
/**
 * privacy.php — Utiligo Privacy Policy.
 */
$pageTitle = 'Privacy Policy — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-3xl mx-auto px-6 py-16">
  <h1 class="text-3xl font-bold mb-2">Privacy Policy</h1>
  <p class="text-slate-500 text-sm mb-10">Last updated: <?= date('F j, Y') ?></p>

  <div class="prose prose-invert prose-slate max-w-none text-slate-300 leading-relaxed space-y-5">

    <p>This Privacy Policy describes how Utiligo ("we", "us", or "our") collects, uses, and protects your information when you use our website and services (the "Service"). By creating an account or using the Service, you agree to the practices described below.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">1. Information We Collect</h2>
    <ul class="list-disc pl-6 space-y-1.5">
      <li><strong class="text-white">Account information</strong> — your name, email address, and password (stored as a one-way bcrypt hash). We never see or store your password in plain text.</li>
      <li><strong class="text-white">Profile & billing data</strong> — your subscription plan, payment status, and a customer identifier returned by our payment processor (Stripe). We do <em>not</em> store full credit card numbers on our servers.</li>
      <li><strong class="text-white">Usage data</strong> — searches you run, leads you unlock, sites you generate, and analytics event logs such as page views, device type, and approximate country (derived from Cloudflare's <code>HTTP_CF_IPCOUNTRY</code> header for aggregate reports only).</li>
      <li><strong class="text-white">Optional authentication tokens</strong> — if you enable "Remember me", we store an SHA-256–hashed selector/validator pair so we can identify your device on future visits without exposing your credentials.</li>
    </ul>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">2. How We Use Your Information</h2>
    <ul class="list-disc pl-6 space-y-1.5">
      <li>To create and maintain your account and authenticate your sessions.</li>
      <li>To operate the Service — running lead searches against Google Places, generating client websites, and storing the generated output.</li>
      <li>To send transactional emails (verification links, password resets, 2-factor codes, payment receipts, and security alerts).</li>
      <li>To detect and prevent abuse, fraud, brute-force login attempts, and automated scraping.</li>
      <li>To provide aggregated, anonymized analytics to our team about how the platform is performing.</li>
      <li>To respond to support requests and comply with valid legal requests.</li>
    </ul>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">3. Google Places API</h2>
    <p>When you run a lead search, your query (city, industry, keywords) is sent to the Google Places API. Utiligo acts as the requesting party on your behalf; Google's <a href="https://policies.google.com/privacy" target="_blank" rel="noopener" class="text-emerald-400 hover:underline">Privacy Policy</a> governs how Google handles that request. We cache search results for up to 48 hours to minimize API calls and cost.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">4. Stripe — Payment Processing</h2>
    <p>Payments are processed by Stripe. We receive only a customer reference ID and subscription status — we never see, transmit, or store your card number, CVV, or full card details. Stripe is PCI-DSS Level 1 certified and manages all payment-card data on its own infrastructure.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">5. Brevo — Email Delivery</h2>
    <p>We use Brevo (Sendinblue) to deliver transactional and marketing emails. Brevo receives the recipient email address and message content necessary to deliver the email and may log delivery events (opens, clicks) for the purpose of measuring deliverability. Your interactions with those emails are governed by Brevo's policy.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">6. Data Retention</h2>
    <p>We keep your account data for as long as your account is active. If you request account deletion (see below), we permanently remove your personal information from our primary databases within 30 days, except where retention is required for legitimate business or legal reasons (e.g., fraud investigations, billing disputes, tax obligations).</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">7. Data Sharing</h2>
    <p>We do <strong class="text-white">not</strong> sell or rent your personal information. We share data only with the sub-processors listed above (Google, Stripe, Brevo, Cloudflare), each acting under their respective privacy policies, and only with the minimum information needed to deliver the Service. We may disclose information when required by law or to protect the rights, property, or safety of Utiligo, our users, or the public.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">8. Your Rights</h2>
    <p>You can request a full export of your data at any time from the Portal &rarr; Account Settings (GDPR-style data portability). You may also request permanent deletion of your account by contacting <a href="mailto:support@utiligo.ca" class="text-emerald-400 hover:underline">support@utiligo.ca</a>. We will respond within 30 days.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">9. Security</h2>
    <p>We protect your account with industry-standard measures: bcrypt password hashing, CSRF tokens on all state-changing requests, rate limiting on sensitive endpoints, server-side image upload validation (magic-byte + extension), and optional two-factor authentication (TOTP via Google Authenticator-compatible apps or email one-time codes). All connections are served over HTTPS. No method of transmission or storage is 100% secure, but we work continuously to keep the Service safe.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">10. Cookies</h2>
    <p>We use a single first-party session cookie (and an optional remember-me cookie if you opt in) to keep you logged in. We do not use third-party tracking or advertising cookies. The session cookie is set with the <code>HttpOnly</code> and <code>SameSite=Lax</code> flags and is transmitted only over HTTPS in production.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">11. Children's Privacy</h2>
    <p>The Service is not directed to anyone under the age of 16, and we do not knowingly collect personal information from children. If you believe we have collected information from a child, please contact us and we will delete it promptly.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">12. Changes to This Policy</h2>
    <p>We may update this Privacy Policy from time to time. Material changes will be announced by email or via a notice on the site. The "Last updated" date above always reflects the most recent revision.</p>

    <h2 class="text-xl font-bold text-white mt-8 mb-2">13. Contact Us</h2>
    <p>Questions about this policy or your data can be sent to <a href="mailto:support@utiligo.ca" class="text-emerald-400 hover:underline">support@utiligo.ca</a>.</p>

  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
