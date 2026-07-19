<?php
/**
 * verify-email.php — Legacy redirect to verify.php
 * This file is kept only to avoid dead links from old verification emails.
 * All new verification emails point to /verify.php directly.
 */
require_once __DIR__ . '/config.php';
$token = trim($_GET['token'] ?? '');
$dest  = '/verify.php' . ($token ? '?token=' . urlencode($token) : '');
header('Location: ' . $dest, true, 301);
exit;
