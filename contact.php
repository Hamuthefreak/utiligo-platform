<?php
/**
 * contact.php — Public contact form, linked from the site footer.
 * Sends the message to SMTP_FROM_EMAIL via the existing mailer helper
 * (Brevo REST API, same as password resets / verification emails), and
 * degrades gracefully to PHP mail() if BREVO_API_KEY isn't set.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$submitted = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid session. Please refresh and try again.';
    } elseif (!rate_limit_check('contact_form', 5)) {
        $error = 'Too many messages sent. Please wait a moment and try again.';
    } else {
        $name = sanitize($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $message = sanitize($_POST['message'] ?? '');

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$message) {
            $error = 'Please fill in your name, a valid email, and a message.';
        } else {
            $htmlBody = "<p><strong>From:</strong> {$name} ({$email})</p><p>" . nl2br($message) . '</p>';
            send_email(SMTP_FROM_EMAIL, 'New Contact Form Message — Utiligo', $htmlBody, '', 'Utiligo Contact Form');
            $submitted = true;
        }
    }
}

$pageTitle = 'Contact Us — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<section class="max-w-md mx-auto px-6 py-20">
  <div class="glass rounded-2xl p-8">
    <?php if ($submitted): ?>
      <div class="text-center">
        <div class="text-4xl mb-4">📬</div>
        <h1 class="text-2xl font-bold mb-2">Message Sent</h1>
        <p class="text-slate-400 text-sm">Thanks for reaching out — we'll get back to you soon.</p>
        <a href="/" class="inline-block mt-6 text-emerald-400 hover:underline text-sm">Back to Home</a>
      </div>
    <?php else: ?>
      <h1 class="text-2xl font-bold mb-2 text-center">Contact Us</h1>
      <p class="text-slate-400 text-sm text-center mb-6">Questions, feedback, or partnership ideas — we'd love to hear from you.</p>

      <?php if ($error): ?>
        <div class="bg-red-500/10 border border-red-400/30 text-red-400 rounded-lg px-4 py-2.5 text-sm mb-4"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
        <input type="text" name="name" required placeholder="Your name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
          class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:border-emerald-400 focus:outline-none">
        <input type="email" name="email" required placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
          class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:border-emerald-400 focus:outline-none">
        <textarea name="message" required rows="5" placeholder="How can we help?"
          class="w-full bg-slate-800 border border-slate-600 text-white placeholder-slate-400 rounded-lg px-4 py-2.5 focus:border-emerald-400 focus:outline-none"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
        <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-400 text-slate-950 py-3 rounded-full font-semibold">
          Send Message
        </button>
      </form>
    <?php endif; ?>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
