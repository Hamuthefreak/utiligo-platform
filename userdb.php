<?php
/**
 * userdb.php — PDO connection to the user accounts database.
 * Kept separate from the platform DB per the architecture spec, so user auth
 * data can be isolated / migrated independently.
 */
require_once __DIR__ . '/config.php';

function get_user_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . USERDB_HOST . ';dbname=' . USERDB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, USERDB_USER, USERDB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
