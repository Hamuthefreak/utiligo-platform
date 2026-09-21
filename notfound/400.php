<?php
/**
 * notfound/400.php — what .htaccess serves for ErrorDocument 400.
 * The page itself is errors/error_page.php; see notfound/404.php for why.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

http_response_code(400);
$pageTitle  = '400 — Bad Request — Utiligo';
$_err_code  = '400';
$_err_title = 'Bad Request';
$_err_desc  = 'Something was wrong with that request. Going back and trying again usually fixes it.';

require_once __DIR__ . '/../errors/error_page.php';
