<?php
/**
 * notfound/403.php — what .htaccess serves for ErrorDocument 403.
 * The page itself is errors/error_page.php; see notfound/404.php for why.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

http_response_code(403);
$pageTitle  = '403 — Access Denied — Utiligo';
$_err_code  = '403';
$_err_title = 'Access Denied';
$_err_desc  = 'You don’t have permission to view this page. If you think that’s a mistake, get in touch.';

require_once __DIR__ . '/../errors/error_page.php';
