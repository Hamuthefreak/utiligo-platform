<?php
/**
 * 404.php — root-level 404.
 *
 * Apache serves this path for many not-found requests, and /notfound/404.php for
 * the ones that fall through to ErrorDocument. Both render the same page from
 * errors/error_page.php, so the two can never disagree.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

http_response_code(404);
$pageTitle  = '404 — Page Not Found — Utiligo';
$_err_code  = '404';
$_err_title = 'This page doesn’t exist';
$_err_desc  = 'The page you’re looking for may have been moved, deleted, or you might have typed the URL wrong.';

$bad = htmlspecialchars(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
if ($bad && $bad !== '/404.php' && $bad !== '/404') {
    $_err_extra = $bad;
}

require_once __DIR__ . '/errors/error_page.php';
