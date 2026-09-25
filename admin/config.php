<?php
/**
 * admin/config.php — the old "Config Editor", now a signpost.
 *
 * WHY THIS FILE IS A REDIRECT AND NOT A PAGE
 * ──────────────────────────────────────────
 * This page and admin/settings.php were two writers of the same file
 * (storage/config_overrides.php) with two different lists of fields, so whichever
 * was saved last decided which keys were still editable, and a key the saving page
 * did not know about was demoted to a preserved line nobody could change. Two
 * pages, one file, one CSRF slot is a mismatch by construction.
 *
 * Then the part that made it urgent: the root .htaccess denied
 * <FilesMatch "^(config|db|userdb)\.php$"> by base name, which also matches inside
 * /admin/ — so this page answered 403 to everyone, including the admin, and the
 * Whop keys and the alert address were reachable only from a page nobody could
 * open. (Both are fixed: the deny is now a RewriteRule on the request URI, and
 * every section lives on one form.)
 *
 * The redirect stays so an old bookmark, a note in a runbook or a link in a chat
 * message lands on the page that can actually save the value it was pointing at.
 * It writes nothing and reads no POST body on purpose — that is what makes
 * admin/settings.php the single writer.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_auth.php';

require_admin();

// 302, not 301: a permanent redirect would be cached by the browser and would
// outlive any future rename of the page.
header('Location: /admin/settings.php', true, 302);
exit;
