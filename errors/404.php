<?php
/**
 * errors/404.php — the errors/ directory's 404.
 *
 * Kept as a thin shell over errors/error_page.php so there is exactly one place
 * the treatment lives. It used to pull in errors/error_styles.css.php, a second
 * stylesheet whose palette had already drifted from the rest of the product.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

http_response_code(404);
$pageTitle  = '404 — Page Not Found — Utiligo';
$_err_code  = '404';
$_err_title = 'This page doesn’t exist';
$_err_desc  = 'The page you’re looking for may have been moved, deleted, or you might have typed the URL wrong.';

require_once __DIR__ . '/error_page.php';
