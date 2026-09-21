<?php
/**
 * notfound/404.php — what .htaccess serves for ErrorDocument 404.
 *
 * Ten lines, because the page itself lives in ONE place now (errors/error_page.php).
 * This file used to carry its own copy of the markup, as did 400, 401, 403, 503 and
 * the root 404 — six copies of the same block, each with its own gradient and its
 * own blurred glow, already drifting apart. The only thing specific to a 404 is the
 * sentence and the fact that we know which path was requested, so that is all this
 * file says.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

http_response_code(404);
$pageTitle  = '404 — Page Not Found — Utiligo';
$_err_code  = '404';
// Raw characters, not entities: errors/error_page.php escapes what it is given,
// so an &rsquo; here would be printed to the customer as "&amp;rsquo;".
$_err_title = 'This page doesn’t exist';
$_err_desc  = 'The page you’re looking for may have been moved, deleted, or you might have typed the URL wrong.';

// Echo back what was asked for — it is how someone spots a typo. Suppressed when
// the path is the error page itself or the rewrite's own alias, because quoting
// those back is noise.
$bad = htmlspecialchars(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
if ($bad && $bad !== '/notfound/404.php' && $bad !== '/404') {
    $_err_extra = $bad;
}

require_once __DIR__ . '/../errors/error_page.php';
