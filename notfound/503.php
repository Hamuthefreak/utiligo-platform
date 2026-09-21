<?php
/**
 * notfound/503.php — what .htaccess serves for ErrorDocument 503.
 * The page itself is errors/error_page.php; see notfound/404.php for why.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

http_response_code(503);
$pageTitle  = '503 — Down for Maintenance — Utiligo';
$_err_code  = '503';
$_err_title = 'Back Soon';
$_err_desc  = 'Utiligo is down for a few minutes of maintenance. Nothing you have done is lost — check back shortly.';

require_once __DIR__ . '/../errors/error_page.php';
