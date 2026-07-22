<?php
/**
 * includes/run_migrations.php
 *
 * Auto-migration runner for Utiligo.
 *
 * HOW IT WORKS
 * ────────────
 * 1. On first call it creates a `schema_migrations` table if it does not exist.
 * 2. It scans every *.sql file inside /migrations, sorted numerically by filename.
 * 3. Any file not yet recorded in schema_migrations is executed statement-by-statement.
 * 4. Duplicate-column errors (SQLSTATE 42S21) are silently skipped so ALTER TABLE
 *    statements are safe to re-run against an already-patched MySQL 5.7 database.
 * 5. Each successfully completed file is recorded so it NEVER runs again.
 *
 * HOW TO ADD A NEW MIGRATION
 * ──────────────────────────
 * Create  migrations/007_your_description.sql  with your SQL statements.
 * Push to GitHub, deploy the file to InfinityFree.
 * The next HTTP request automatically applies it — no phpMyAdmin needed.
 *
 * CALL SITE
 * ─────────
 * Called once near the top of config.php (already wired in).
 * Safe to call on every request — the SELECT on schema_migrations is
 * cheap and the file-scan only runs expensive SQL when there is new work.
 */

function run_pending_migrations(PDO $pdo, string $migrations_dir): void
{
    // 1. Bootstrap the tracking table
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `schema_migrations` (
           `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
           `filename`   VARCHAR(255) NOT NULL,
           `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
           PRIMARY KEY (`id`),
           UNIQUE KEY `uq_filename` (`filename`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    // 2. Load already-applied filenames into a Set for O(1) lookup
    $applied = [];
    try {
        $rows = $pdo->query("SELECT filename FROM schema_migrations ORDER BY filename")
                    ->fetchAll(PDO::FETCH_COLUMN);
        $applied = array_flip($rows);
    } catch (Throwable $e) {
        // Table might not exist yet on very first boot — handled above
    }

    // 3. Scan migration files, sorted numerically
    $files = glob($migrations_dir . DIRECTORY_SEPARATOR . '*.sql');
    if (!$files) return;
    sort($files); // lexicographic = numeric because filenames are zero-padded

    foreach ($files as $filepath) {
        $filename = basename($filepath);
        if (isset($applied[$filename])) continue; // already done

        $sql = @file_get_contents($filepath);
        if ($sql === false) continue;

        // 4. Execute statement-by-statement so one failure doesn't abort the batch
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn(string $s) => $s !== ''
        );

        $all_ok = true;
        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                $sqlstate = $e->getCode();
                // 42S21 = duplicate column, 42S01 = table already exists
                // 42000 + "Duplicate key name" = index already exists
                $msg = $e->getMessage();
                $ignorable =
                    $sqlstate === '42S21'                              // duplicate column
                    || $sqlstate === '42S01'                           // table exists
                    || str_contains($msg, 'Duplicate column name')
                    || str_contains($msg, 'already exists')
                    || str_contains($msg, 'Duplicate key name');

                if (!$ignorable) {
                    // Real error — log it but don't crash the whole request
                    if (function_exists('log_error')) {
                        log_error('migration_failed', $e, [
                            'file' => $filename,
                            'stmt' => substr($stmt, 0, 200),
                        ]);
                    }
                    $all_ok = false;
                    // Don't mark this file as applied so it retries next request
                    break;
                }
                // ignorable error — column already existed, that's fine, continue
            }
        }

        // 5. Mark as applied only if every statement succeeded (or was ignorable)
        if ($all_ok) {
            try {
                $pdo->prepare(
                    "INSERT IGNORE INTO schema_migrations (filename) VALUES (?)"
                )->execute([$filename]);
            } catch (Throwable $e) {
                // Non-fatal
            }
        }
    }
}
