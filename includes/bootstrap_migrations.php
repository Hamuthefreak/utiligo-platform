<?php
/**
 * includes/bootstrap_migrations.php
 *
 * Thin wrapper called from config.php after the DB connection is ready.
 * Keeps config.php clean.
 */
if (!function_exists('run_pending_migrations')) {
    require_once __DIR__ . '/run_migrations.php';
}

try {
    // get_platform_db() is defined in db.php which is loaded before config.php
    // calls this file.  If it is not ready yet we skip silently.
    if (function_exists('get_platform_db')) {
        run_pending_migrations(
            get_platform_db(),
            dirname(__DIR__) . '/migrations'
        );
    }
} catch (Throwable $e) {
    // Never crash the whole site over a migration error
    if (function_exists('log_error')) {
        log_error('bootstrap_migrations', $e);
    }
}
