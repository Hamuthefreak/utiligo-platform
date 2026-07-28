<?php
/**
 * logout.php — Clears the session and remember-me cookie, then redirects home.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

logout_user();

header('Location: /');
exit;
