<?php
/**
 * White-label has been removed from Utiligo.
 * Redirect anyone who lands here back to dashboard.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
header('Location: /portal/index.php');
exit;
