<?php
/**
 * includes/mailer.php — Email helper using Brevo's REST API directly via cURL.
 * Sender: noreply@utiligo.ca (set in config.php as SMTP_FROM_EMAIL).
 *
 * Why REST API instead of SMTP/PHPMailer: InfinityFree does not support
 * Composer, so pulling in the PHPMailer library is painful. Brevo's
 * v3/smtp/email REST endpoint needs only an API key and cURL (both available
 * on InfinityFree) — no libraries required.
 */
require_once __DIR__ . '/../config.php';

/* ═══════════════════════════════════════════════════════════════════════════
   CORE SEND
   ═══════════════════════════════════════════════════════════════════════════ */

function send_email(string $to, string $subject, string $htmlBody, string $textBody = '', string $toName = ''): bool
{
    if (defined('BREVO_API_KEY') && BREVO_API_KEY !== '' && BREVO_API_KEY !== 'YOUR_BREVO_API_KEY') {
        $payload = [
            'sender' => ['name' => SMTP_FROM_NAME, 'email' => SMTP_FROM_EMAIL],
            'to'     => [['email' => $to, 'name' => $toName ?: $to]],
            'subject'     => $subject,
            'htmlContent' => $htmlBody,
        ];
        if ($textBody) $payload['textContent'] = $textBody;

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: ' . BREVO_API_KEY,
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_errno($ch);
        curl_close($ch);

        if (!$err && $httpCode >= 200 && $httpCode < 300) return true;
        error_log('Brevo send failed (HTTP ' . $httpCode . '): ' . $response);
    }

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
    return @mail($to, $subject, $htmlBody, $headers);
}

function brevo_upsert_contact(string $email, array $attributes = [], array $listIds = []): bool
{
    if (!defined('BREVO_API_KEY') || BREVO_API_KEY === '' || BREVO_API_KEY === 'YOUR_BREVO_API_KEY') return false;
    $payload = ['email' => $email, 'attributes' => $attributes, 'updateEnabled' => true];
    if ($listIds) $payload['listIds'] = $listIds;

    $ch = curl_init('https://api.brevo.com/v3/contacts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_exec($ch);
    curl_close($ch);
    return $httpCode >= 200 && $httpCode < 300;
}

/* ═══════════════════════════════════════════════════════════════════════════
   EMAIL WRAPPER  —  polished dark design, mobile-first
   ═══════════════════════════════════════════════════════════════════════════

   Design principles
   -----------------
   • Table-based layout (Gmail / Outlook safe — no flexbox / grid)
   • Max-width 600 px; fluid on small screens via width:100%
   • All CSS inlined — no <style> blocks (strips in many clients)
   • System font stack with Inter as first choice
   • Dark background (#0F172A) with card (#1A2540) — matches app palette
   • Accent: Utiligo green  #10B981
   • CTA button: 48 px tall × full padding, rounded pill, tap-friendly
   • Footer with muted text and unsubscribe note

   ═══════════════════════════════════════════════════════════════════════════ */

function email_wrapper(string $preheader, string $bodyHtml, string $footerExtra = ''): string
{
    $year    = date('Y');
    $site    = 'https://utiligo.ca';
    $logo    = 'https://utiligo.ca/assets/img/utiligo-logo-email.png'; // fallback text shown if 404

    return <<<HTML
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>Utiligo</title>
  <!--[if mso]>
  <noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript>
  <![endif]-->
</head>
<body style="margin:0;padding:0;background-color:#0F172A;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">

<!-- Pre-header (hidden preview text) -->
<div style="display:none;max-height:0;overflow:hidden;font-size:1px;line-height:1px;color:#0F172A;">
  {$preheader}&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;&#847;&nbsp;
</div>

<!-- Outer table -->
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
       style="background-color:#0F172A;min-height:100vh;">
  <tr>
    <td align="center" style="padding:40px 16px 60px;">

      <!-- Card -->
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"
             style="max-width:580px;background-color:#1A2540;border-radius:20px;overflow:hidden;
                    box-shadow:0 20px 60px rgba(0,0,0,0.5);">

        <!-- ── Header bar ── -->
        <tr>
          <td style="background:linear-gradient(135deg,#0F172A 0%,#1E3A5F 100%);
                     padding:32px 40px 28px;text-align:center;
                     border-bottom:1px solid rgba(16,185,129,0.25);">
            <!-- Wordmark fallback if logo image fails -->
            <a href="{$site}" style="text-decoration:none;">
              <!--[if !mso]><!-->
              <img src="{$logo}" alt="Utiligo" width="130" height="auto"
                   style="display:block;margin:0 auto;max-width:130px;height:auto;"
                   onerror="this.style.display='none';document.getElementById('logo-text').style.display='block';">
              <!--<![endif]-->
              <span id="logo-text" style="display:none;font-family:Inter,Arial,sans-serif;
                    font-size:26px;font-weight:800;letter-spacing:-0.5px;color:#FFFFFF;">
                utili<span style="color:#10B981;">go</span>
              </span>
            </a>
          </td>
        </tr>

        <!-- ── Body ── -->
        <tr>
          <td style="padding:40px 40px 36px;font-family:Inter,-apple-system,BlinkMacSystemFont,
                     'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#CBD5E1;font-size:15px;
                     line-height:1.7;">
            {$bodyHtml}
          </td>
        </tr>

        <!-- ── Footer ── -->
        <tr>
          <td style="padding:24px 40px 32px;border-top:1px solid rgba(255,255,255,0.07);
                     text-align:center;font-family:Inter,-apple-system,BlinkMacSystemFont,
                     'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            {$footerExtra}
            <p style="margin:0 0 6px;font-size:12px;color:#475569;">
              &copy; {$year} Utiligo &mdash;
              <a href="{$site}" style="color:#10B981;text-decoration:none;">utiligo.ca</a>
            </p>
            <p style="margin:0;font-size:11px;color:#334155;">
              You received this email because you have a Utiligo account.
            </p>
          </td>
        </tr>

      </table>
      <!-- /Card -->

    </td>
  </tr>
</table>

</body>
</html>
HTML;
}

/* ── Shared style atoms ─────────────────────────────────────────────────── */

/** Primary CTA button — pill shape, green, 48 px tall, tap-friendly */
function _email_btn(string $href, string $label): string
{
    return <<<HTML
<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:28px auto 0;">
  <tr>
    <td style="border-radius:100px;background:#10B981;" align="center">
      <a href="{$href}"
         style="display:inline-block;padding:14px 36px;font-family:Inter,-apple-system,
                BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;
                font-size:15px;font-weight:700;color:#0F172A;text-decoration:none;
                border-radius:100px;mso-padding-alt:0;text-align:center;
                white-space:nowrap;">
        <!--[if mso]><i style="letter-spacing:25px;mso-font-width:-100%;mso-text-raise:30pt">&nbsp;</i><![endif]-->
        {$label}
        <!--[if mso]><i style="letter-spacing:25px;mso-font-width:-100%">&nbsp;</i><![endif]-->
      </a>
    </td>
  </tr>
</table>
HTML;
}

/** Secondary small link — shown below the button as a plain-text fallback */
function _email_link_fallback(string $href): string
{
    return "<p style=\"font-size:12px;color:#475569;text-align:center;margin:16px 0 0;word-break:break-all;\">"
         . "Or copy this link: <a href=\"{$href}\" style=\"color:#10B981;text-decoration:none;\">{$href}</a></p>";
}

/** Section heading inside email body */
function _email_h(string $text): string
{
    return "<h2 style=\"margin:0 0 16px;font-family:Inter,-apple-system,BlinkMacSystemFont,"
         . "'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:22px;font-weight:800;"
         . "color:#F1F5F9;letter-spacing:-0.3px;\">{$text}</h2>";
}

/** Muted paragraph */
function _email_muted(string $text): string
{
    return "<p style=\"font-size:12px;color:#475569;margin:12px 0 0;\">{$text}</p>";
}

/** Highlight box (green-tinted callout) */
function _email_callout(string $text): string
{
    return "<div style=\"background:rgba(16,185,129,0.1);border-left:3px solid #10B981;"
         . "border-radius:0 10px 10px 0;padding:14px 18px;margin:20px 0;\">"
         . "<p style=\"margin:0;font-size:14px;color:#A7F3D0;line-height:1.6;\">{$text}</p>"
         . "</div>";
}

/* ═══════════════════════════════════════════════════════════════════════════
   INDIVIDUAL EMAIL FUNCTIONS
   ═══════════════════════════════════════════════════════════════════════════ */

function send_verification_email(string $to, string $fullName, string $verifyLink): bool
{
    $first   = htmlspecialchars(explode(' ', trim($fullName))[0]);
    $preheader = "Almost there! Verify your email to activate your Utiligo account.";

    $body = _email_h('Confirm your email')
          . "<p style=\"margin:0 0 8px;color:#CBD5E1;\">Hi {$first},</p>"
          . "<p style=\"margin:0 0 20px;color:#CBD5E1;\">Welcome aboard! Click the button below to verify your email address and activate your account.</p>"
          . _email_btn($verifyLink, 'Verify my email')
          . _email_link_fallback($verifyLink)
          . _email_muted('This link expires in 24 hours. If you didn\'t create a Utiligo account, you can safely ignore this email.');

    return send_email($to, 'Verify your Utiligo account', email_wrapper($preheader, $body), '', $fullName);
}

function send_welcome_email(string $to, string $fullName): bool
{
    $first     = htmlspecialchars(explode(' ', trim($fullName))[0]);
    $preheader = "Your Utiligo Pro subscription is active — let's find some leads!";

    $features = "
      <table role=\"presentation\" width=\"100%\" cellspacing=\"0\" cellpadding=\"0\" border=\"0\" style=\"margin:24px 0;\">
        <tr>
          <td style=\"padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.06);\">
            <span style=\"color:#10B981;font-weight:700;margin-right:8px;\">✓</span>
            <span style=\"color:#CBD5E1;font-size:14px;\">Unlimited lead searches</span>
          </td>
        </tr>
        <tr>
          <td style=\"padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.06);\">
            <span style=\"color:#10B981;font-weight:700;margin-right:8px;\">✓</span>
            <span style=\"color:#CBD5E1;font-size:14px;\">AI-powered website builder</span>
          </td>
        </tr>
        <tr>
          <td style=\"padding:10px 0;\">
            <span style=\"color:#10B981;font-weight:700;margin-right:8px;\">✓</span>
            <span style=\"color:#CBD5E1;font-size:14px;\">White-label branding &amp; client portals</span>
          </td>
        </tr>
      </table>";

    $body = _email_h("Welcome to Pro, {$first}! 🎉")
          . "<p style=\"margin:0 0 4px;color:#CBD5E1;\">Your subscription is active and ready. Here's what you now have access to:</p>"
          . $features
          . _email_btn('https://utiligo.ca/portal/index.php', 'Go to Dashboard')
          . _email_muted('Questions? Reply to this email — we respond fast.');

    return send_email($to, 'Welcome to Utiligo Pro!', email_wrapper($preheader, $body), '', $fullName);
}

function send_password_reset_email(string $to, string $fullName, string $resetLink): bool
{
    $first     = htmlspecialchars(explode(' ', trim($fullName))[0]);
    $preheader = "Reset your Utiligo password — this link expires in 1 hour.";

    $body = _email_h('Reset your password')
          . "<p style=\"margin:0 0 20px;color:#CBD5E1;\">Hi {$first}, we received a request to reset the password for your Utiligo account. Click below to choose a new one.</p>"
          . _email_btn($resetLink, 'Reset my password')
          . _email_link_fallback($resetLink)
          . _email_callout('⏱ This link expires in <strong>1 hour</strong>.')
          . _email_muted("Didn't request this? You can safely ignore this email — your account is unchanged.");

    return send_email($to, 'Reset your Utiligo password', email_wrapper($preheader, $body), '', $fullName);
}

function send_2fa_code_email(string $to, string $fullName, string $code): bool
{
    $first     = htmlspecialchars(explode(' ', trim($fullName))[0]);
    $preheader = "Your Utiligo login code is {$code} — expires in 10 minutes.";

    $digits = implode('', array_map(
        fn($d) => "<span style=\"display:inline-block;width:38px;height:52px;line-height:52px;
                   background:#0F172A;border:1px solid rgba(16,185,129,0.35);border-radius:10px;
                   margin:0 3px;text-align:center;font-size:26px;font-weight:800;
                   color:#10B981;\">{$d}</span>",
        str_split($code)
    ));

    $body = _email_h('Login verification code')
          . "<p style=\"margin:0 0 24px;color:#CBD5E1;\">Hi {$first}, use the code below to complete your sign-in.</p>"
          . "<div style=\"text-align:center;margin:8px 0 24px;\">{$digits}</div>"
          . _email_callout('⏱ Expires in <strong>10 minutes</strong>. Do not share this code with anyone.')
          . _email_muted("Didn't try to log in? Secure your account immediately by changing your password.");

    return send_email($to, "Your Utiligo code: {$code}", email_wrapper($preheader, $body), '', $fullName);
}

function send_payment_reminder_email(string $to, string $fullName, string $reason = 'past_due'): bool
{
    $first = htmlspecialchars(explode(' ', trim($fullName))[0]);
    $configs = [
        'past_due' => [
            'preheader' => "Action required: your Utiligo Pro payment didn't go through.",
            'heading'   => 'Payment issue',
            'icon'      => '⚠️',
            'msg'       => "Your most recent payment for <strong>Utiligo Pro</strong> didn't go through. Please update your payment method to keep your Pro features active — leads, sites, and white-label branding.",
            'cta_label' => 'Update payment method',
        ],
        'upcoming' => [
            'preheader' => "Heads-up: your Utiligo Pro subscription renews soon.",
            'heading'   => 'Upcoming renewal',
            'icon'      => '🔔',
            'msg'       => "Your <strong>Utiligo Pro</strong> subscription will renew soon at <strong>\$21.99 / month</strong>. No action needed if your payment method is up to date.",
            'cta_label' => 'View billing',
        ],
        'cancelled_access_ending' => [
            'preheader' => "Your Utiligo Pro access is ending soon — resubscribe to keep your data.",
            'heading'   => 'Pro access ending',
            'icon'      => '📅',
            'msg'       => "Your <strong>Utiligo Pro</strong> access will end soon as your subscription was cancelled. Resubscribe anytime to restore unlimited leads, websites, and white-label branding.",
            'cta_label' => 'Resubscribe now',
        ],
    ];
    $cfg = $configs[$reason] ?? $configs['past_due'];

    $body = _email_h("{$cfg['icon']} {$cfg['heading']}")
          . "<p style=\"margin:0 0 20px;color:#CBD5E1;\">Hi {$first},</p>"
          . "<p style=\"margin:0 0 20px;color:#CBD5E1;\">{$cfg['msg']}</p>"
          . _email_btn('https://utiligo.ca/portal/billing.php', $cfg['cta_label'])
          . _email_muted('Questions? Reply to this email and we\'ll sort it out right away.');

    return send_email(
        $to,
        'Action needed: Utiligo Pro billing',
        email_wrapper($cfg['preheader'], $body),
        '',
        $fullName
    );
}

function send_marketing_email(string $to, string $fullName, string $subject, string $bodyHtml): bool
{
    $footer = "<p style=\"font-size:11px;color:#334155;margin:0 0 12px;\">
        <a href=\"https://utiligo.ca/unsubscribe\" style=\"color:#475569;text-decoration:underline;\">
          Unsubscribe from marketing emails
        </a>
      </p>";
    return send_email($to, $subject, email_wrapper($subject, $bodyHtml, $footer), '', $fullName);
}
