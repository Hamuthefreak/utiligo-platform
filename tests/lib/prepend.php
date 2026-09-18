<?php
/**
 * tests/lib/prepend.php
 *
 * Loaded into the test application server via `-d auto_prepend_file=`, before
 * anything else runs.
 *
 * includes/global_error_handler.php picks the first writable log location and
 * writes there, and storage/php_errors.log is tracked in git — so without this,
 * a PHP warning raised while a test drives a page would append lines to a file
 * in the working tree. Pointing the constant at tests/tmp keeps the suite
 * non-destructive in a shared checkout.
 *
 * It can only redirect the handler's own writes. The handler also mirrors each
 * line through error_log(), and it sets that ini value to its own candidate, so
 * a genuine PHP fault during a test can still leave a line in
 * storage/php_errors.log. Treat that as signal, not noise: it means a page
 * raised a real error, and the file should be restored afterwards.
 */

if (!defined('UTILIGO_PHP_ERROR_LOG')) {
    $dir = __DIR__ . '/../tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    define('UTILIGO_PHP_ERROR_LOG', $dir . '/app_errors.log');
}
