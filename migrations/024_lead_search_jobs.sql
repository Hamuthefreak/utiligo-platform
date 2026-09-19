-- 024_lead_search_jobs.sql
--
-- Async lead-search queue. A lead search used to run inside the user's own
-- request: api/find-leads.php sleeps ~3s between Google Places page tokens and
-- then fans out to four more sources, so a 3-page search held a PHP worker for
-- 30-60 seconds. Two concurrent searches from one customer could tie up the
-- whole FPM pool.
--
-- Now the request only validates + enqueues (milliseconds). cron/
-- lead_search_worker.php claims a row and does the slow part, while the browser
-- polls api/lead-search-status.php for progress.
--
-- Canonical DDL. includes/lead_search_jobs.php also issues a matching
-- CREATE TABLE IF NOT EXISTS as a defensive safety-net for fresh installs
-- where the migration runner hasn't run yet — keep the two in sync.
CREATE TABLE IF NOT EXISTS `lead_search_jobs` (
    `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED     NOT NULL,
    `token`            CHAR(32)         NOT NULL,
    `status`           VARCHAR(12)      NOT NULL DEFAULT 'queued',
    `params_json`      TEXT             NOT NULL,
    `progress`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `stage`            VARCHAR(80)      NOT NULL DEFAULT '',
    `result_json`      MEDIUMTEXT       NULL,
    `error_code`       VARCHAR(40)      NOT NULL DEFAULT '',
    `error_message`    VARCHAR(500)     NOT NULL DEFAULT '',
    `error_extra_json` TEXT             NULL,
    `attempts`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `worker`           VARCHAR(64)      NOT NULL DEFAULT '',
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `kicked_at`        DATETIME         NULL,
    `started_at`       DATETIME         NULL,
    `heartbeat_at`     DATETIME         NULL,
    `finished_at`      DATETIME         NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_token` (`token`),
    KEY `idx_status_created` (`status`, `created_at`),
    KEY `idx_user_created` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
