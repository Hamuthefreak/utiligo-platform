<?php
/**
 * Migration 009 — add is_admin column to utiligo_users.
 * Promotes the first account created (id=1) to admin by default,
 * or the account matching the ADMIN_EMAIL env var if set.
 */
function migration_009_add_is_admin_column(PDO $pdo, string $db): void
{
    // This migration targets the USERS db, not the platform db.
    // The runner passes the platform DB; we open the user DB ourselves.
    require_once __DIR__ . '/../userdb.php';
    $udb = get_user_db();

    // Add column if absent
    $cols = $udb->query('SHOW COLUMNS FROM utiligo_users LIKE "is_admin"')->fetchAll();
    if (empty($cols)) {
        $udb->exec('ALTER TABLE utiligo_users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER subscription_status');
        $udb->exec('CREATE INDEX IF NOT EXISTS idx_is_admin ON utiligo_users (is_admin)');
    }

    // Promote: env var first, then id=1 fallback
    $adminEmail = getenv('UTILIGO_ADMIN_EMAIL') ?: null;
    if ($adminEmail) {
        $stmt = $udb->prepare('UPDATE utiligo_users SET is_admin=1 WHERE email=?');
        $stmt->execute([strtolower(trim($adminEmail))]);
    } else {
        $udb->exec('UPDATE utiligo_users SET is_admin=1 WHERE id=1 AND is_admin=0 LIMIT 1');
    }
}
