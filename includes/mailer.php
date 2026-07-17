<?php
/**
 * includes/mailer.php — Email helper using Brevo's REST API directly via cURL.
 * Sender: noreply@utiligo.ca (set in config.php as SMTP_FROM_EMAIL).
 *
 * Why REST API instead of SMTP/PHPMailer: InfinityFree does not support
 * Composer, so pulling in the PHPMailer library is painful. Brevo's
 * v3/smtp/email REST endpoint needs only an API key and cURL (both available
 * on InfinityFree) — no libraries required.
 *
 * Get your API key: Brevo dashboard -> SMTP & API -> API Keys -> Create a new API key.
 * Set it as BREVO_API_KEY in config.php.
 */
require_once __DIR__ . '/../config.php';

function send_email(string $to, string $subject, string $htmlBody, string $textBody = '', string $toName = ''): bool
{
    if (defined('BREVO_API_KEY') && BREVO_API_KEY !== '' && BREVO_API_KEY !== 'YOUR_BREVO_API_KEY') {
        $payload = [
            'sender' => ['name' => SMTP_FROM_NAME, 'email' => SMTP_FROM_EMAIL],
            'to' => [['email' => $to, 'name' => $toName ?: $to]],
            'subject' => $subject,
            'htmlContent' => $htmlBody,
        ];
        if ($textBody) {
            $payload['textContent'] = $textBody;
        }

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'api-key: ' . BREVO_API_KEY,
            'content-type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_errno($ch);
        curl_close($ch);

        if (!$err && $httpCode >= 200 && $httpCode < 300) {
            return true;
        }
        error_log('Brevo send failed (HTTP ' . $httpCode . '): ' . $response);
    }

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
    return @mail($to, $subject, $htmlBody, $headers);
}

function brevo_upsert_contact(string $email, array $attributes = [], array $listIds = []): bool
{
    if (!defined('BREVO_API_KEY') || BREVO_API_KEY === '' || BREVO_API_KEY === 'YOUR_BREVO_API_KEY') {
        return false;
    }
    $payload = [
        'email' => $email,
        'attributes' => $attributes,
        'updateEnabled' => true,
    ];
    if ($listIds) {
        $payload['listIds'] = $listIds;
    }
    $ch = curl_init('https://api.brevo.com/v3/contacts');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'accept: application/json',
        'api-key: ' . BREVO_API_KEY,
        'content-type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode >= 200 && $httpCode < 300;
}

function email_wrapper(string $title, string $bodyHtml): string
{
    return "
    <div style=\"font-family:Inter,Arial,sans-serif;background:#0F172A;padding:32px;\">
      <div style=\"max-width:480px;margin:0 auto;background:#1E293B;border-radius:16px;padding:32px;color:#fff;\">
        <h2 style=\"color:#10B981;margin-top:0;\">$title</h2>
        $bodyHtml
        <p style=\"font-size:12px;color:#94A3B8;margin-top:32px;border-top:1px solid rgba(255,255,255,0.1);padding-top:16px;\">
          Utiligo — <a href=\"https://utiligo.ca\" style=\"color:#10B981;\">utiligo.ca</a>
        </p>
      </div>
    </div>";
}

function send_verification_email(string $to, string $fullName, string $verifyLink): bool
{
    $body = "<p>Hi {$fullName},</p><p>Welcome to Utiligo! Confirm your email to activate your account:</p>
        <p><a href=\"{$verifyLink}\" style=\"display:inline-block;background:#10B981;color:#0F172A;padding:12px 24px;border-radius:999px;font-weight:600;text-decoration:none;\">Verify Email</a></p>
        <p style=\"font-size:13px;color:#94A3B8;\">Or paste this link: {$verifyLink}</p>";
    return send_email($to, 'Verify your Utiligo account', email_wrapper('Confirm your email', $body), '', $fullName);
}

function send_welcome_email(string $to, string $fullName): bool
{
    $body = "<p>Hi {$fullName},</p><p>Your Utiligo Pro subscription is active. Start finding leads and generating websites now.</p>
        <p><a href=\"https://utiligo.ca/portal/index.php\" style=\"display:inline-block;background:#10B981;color:#0F172A;padding:12px 24px;border-radius:999px;font-weight:600;text-decoration:none;\">Go to Dashboard</a></p>";
    return send_email($to, 'Welcome to Utiligo Pro!', email_wrapper('Welcome to Pro 🎉', $body), '', $fullName);
}

function send_password_reset_email(string $to, string $fullName, string $resetLink): bool
{
    $body = "<p>Hi {$fullName},</p><p>Click the link below to reset your password. This link expires in 1 hour.</p>
        <p><a href=\"{$resetLink}\" style=\"display:inline-block;background:#10B981;color:#0F172A;padding:12px 24px;border-radius:999px;font-weight:600;text-decoration:none;\">Reset Password</a></p>
        <p style=\"font-size:13px;color:#94A3B8;\">If you did not request this, you can safely ignore this email.</p>";
    return send_email($to, 'Reset your Utiligo password', email_wrapper('Password Reset', $body), '', $fullName);
}

function send_2fa_code_email(string $to, string $fullName, string $code): bool
{
    $body = "<p>Hi {$fullName},</p><p>Your Utiligo login verification code is:</p>
        <p style=\"font-size:32px;font-weight:800;letter-spacing:8px;color:#10B981;text-align:center;\">{$code}</p>
        <p style=\"font-size:13px;color:#94A3B8;\">This code expires in 10 minutes. If you didn't request this, secure your account immediately.</p>";
    return send_email($to, 'Your Utiligo login code: ' . $code, email_wrapper('Login Verification Code', $body), '', $fullName);
}

function send_payment_reminder_email(string $to, string $fullName, string $reason = 'past_due'): bool
{
    $messages = [
        'past_due' => 'Your last payment for Utiligo Pro didn\'t go through. Please update your payment method to keep your Pro features active.',
        'upcoming' => 'Your Utiligo Pro subscription will renew soon at $21.99. No action is needed if your payment method is up to date.',
        'cancelled_access_ending' => 'Your Utiligo Pro access will end soon since your subscription was cancelled. Resubscribe anytime to keep your leads, sites, and white-label branding.',
    ];
    $msg = $messages[$reason] ?? $messages['past_due'];
    $body = "<p>Hi {$fullName},</p><p>{$msg}</p>
        <p><a href=\"https://utiligo.ca/portal/billing.php\" style=\"display:inline-block;background:#10B981;color:#0F172A;padding:12px 24px;border-radius:999px;font-weight:600;text-decoration:none;\">Manage Billing</a></p>";
    return send_email($to, 'Action needed: Utiligo Pro billing', email_wrapper('Billing Reminder', $body), '', $fullName);
}

function send_marketing_email(string $to, string $fullName, string $subject, string $bodyHtml): bool
{
    return send_email($to, $subject, email_wrapper($subject, $bodyHtml), '', $fullName);
}
