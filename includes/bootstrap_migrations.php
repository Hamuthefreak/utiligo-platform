<?php
/**
 * includes/bootstrap_migrations.php
 *
 * Runs pending SQL migrations against BOTH databases on every request.
 * Called from config.php — safe to call on every page load (cheap SELECT
 * on schema_migrations; only does real work when new .sql files are found).
 *
 * FIX (2026-08-01): Previously only ran against the platform DB and was
 * called before db.php was loaded, so get_platform_db() did not exist yet
 * and migrations silently never ran.  Now self-loads both db.php and
 * userdb.php so the connections are always available, and runs the runner
 * against the user DB as well so tables like utiligo_remember_tokens
 * (required by auth.php) are created automatically.
 */

if (!function_exists('run_pending_migrations')) {
    require_once __DIR__ . '/run_migrations.php';
}

$_migrations_dir = dirname(__DIR__) . '/migrations';

// ── Platform DB ──────────────────────────────────────────────────────────────
try {
    if (!function_exists('get_platform_db')) {
        require_once __DIR__ . '/../db.php';
    }
    run_pending_migrations(get_platform_db(), $_migrations_dir);
} catch (Throwable $e) {
    if (function_exists('log_error')) {
        log_error('bootstrap_migrations_platform', $e);
    }
}

// ── User DB ───────────────────────────────────────────────────────────────────
// The user DB needs the same migration runner so that tables like
// utiligo_remember_tokens are created automatically without phpMyAdmin.
try {
    if (!function_exists('get_user_db')) {
        require_once __DIR__ . '/../userdb.php';
    }
    run_pending_migrations(get_user_db(), $_migrations_dir);
} catch (Throwable $e) {
    if (function_exists('log_error')) {
        log_error('bootstrap_migrations_userdb', $e);
    }
}

unset($_migrations_dir);
