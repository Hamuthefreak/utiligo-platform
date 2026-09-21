<?php
/**
 * notfound/401.php — what .htaccess serves for ErrorDocument 401.
 * The page itself is errors/error_page.php; see notfound/404.php for why.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

http_response_code(401);
$pageTitle  = '401 — Unauthorised — Utiligo';
$_err_code  = '401';
$_err_title = 'Unauthorised';
$_err_desc  = 'You need to be signed in to view this page.';
// The one error where signing in is the obvious next move, so it leads the row.
$_err_links = true;

require_once __DIR__ . '/../errors/error_page.php';
