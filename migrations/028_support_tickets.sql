-- Migration 028: support tickets, messages and attachments
-- ========================================================
-- The support bubble. Until now a customer who hit a wall had nowhere inside the
-- product to say so -- the only route was to hunt for an email address and
-- describe the problem from memory. A ticket is that route, and keeping it in the
-- product means the conversation sits next to the thing that went wrong.
--
-- Tables live in the PLATFORM database, beside the lead workspace, because that is
-- where the application's own data lives. The runner applies every migration to
-- BOTH databases, so this also creates unused copies in the USER database -- IF
-- NOT EXISTS makes that second application a no-op, exactly as migrations
-- 024/025/027 do.
--
--   support_tickets.status
--       open | pending | closed. 'pending' is set when support has replied and the
--       ball is back in the customer's court, which is what lets an operator see
--       at a glance which conversations are waiting on somebody else. A customer
--       message always returns the ticket to 'open'.
--
--   unread_user / unread_admin
--       Counts kept ON the ticket rather than derived from a read flag per
--       message. The only question ever asked is "how many new", by the bubble
--       badge and the admin nav badge, and a column beats a join for that.
--
--   message_count / last_message_at
--       So a ticket list can be drawn -- ordered, with a "waiting since" line --
--       without reading the messages table at all.
--
--   support_messages.author_type
--       'user' | 'admin', stored rather than inferred from a role lookup at read
--       time, so a transcript stays correct even if the account is later promoted
--       or demoted. It is a historical fact about who spoke.
--
--   support_attachments
--       One row per file, linked to the message that carried it. stored_path is
--       relative to the app root and lives under storage/, which .htaccess denies,
--       so a leaked path cannot be fetched directly: api/support-file.php is the
--       only way in and it checks who is asking.

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `subject`         VARCHAR(160) NOT NULL,
  `status`          VARCHAR(16)  NOT NULL DEFAULT 'open',
  `unread_user`     INT UNSIGNED NOT NULL DEFAULT 0,
  `unread_admin`    INT UNSIGNED NOT NULL DEFAULT 0,
  `message_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `last_message_at` DATETIME     NOT NULL,
  `created_at`      DATETIME     NOT NULL,
  `updated_at`      DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_last` (`user_id`, `last_message_at`),
  KEY `idx_status_last` (`status`, `last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_messages` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`   INT UNSIGNED NOT NULL,
  `author_type` VARCHAR(8)   NOT NULL,
  `author_id`   INT UNSIGNED NOT NULL,
  `body`        MEDIUMTEXT   NOT NULL,
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_attachments` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`     INT UNSIGNED NOT NULL,
  `message_id`    INT UNSIGNED NOT NULL,
  `user_id`       INT UNSIGNED NOT NULL,
  `original_name` VARCHAR(160) NOT NULL,
  `stored_path`   VARCHAR(255) NOT NULL,
  `mime_type`     VARCHAR(80)  NOT NULL,
  `file_size`     INT UNSIGNED NOT NULL,
  `width`         INT UNSIGNED NULL DEFAULT NULL,
  `height`        INT UNSIGNED NULL DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_message` (`message_id`),
  KEY `idx_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
