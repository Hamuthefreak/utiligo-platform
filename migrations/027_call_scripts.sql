-- Migration 027: call scripts for the floating call dock
-- =====================================================
-- A lead is a phone number and a business. What the customer needs while the
-- phone is actually ringing is the thing they are going to SAY, and today that
-- lives in a notes app on a second screen or in their head. This table is the
-- storage for a persistent, always-there panel of scripts they can flip through
-- mid-call without leaving the page they are on.
--
--   name          the tab label. Single line, because it is drawn in a header
--                 and in a switcher list — a pasted paragraph would break both.
--   body          the script itself, verbatim. Never HTML-escaped on the way in
--                 (it is read aloud, so its own line breaks and blank lines are
--                 meaning), only on the way out.
--   sort_order    the customer's own ordering, so their most-used script can sit
--                 first. Gaps are left between inserts on purpose (10, 20, 30) so
--                 inserting between two scripts is a write to one row.
--   source        'starter' for the three scripts seeded on first open, 'manual'
--                 for anything the customer wrote, 'import' for a bulk paste.
--                 Kept so the UI can label a starter as one they can safely
--                 rewrite, and so seeding can be told apart from authorship.
--   times_used    incremented when a script is copied or sent, which is the only
--                 honest answer to "which of these is actually working".
--   last_used_at  so the switcher can float the ones they reach for.
--
-- Database: PLATFORM database (call_scripts belongs with the lead workspace,
-- next to saved_searches). The runner applies every file to both databases, so
-- this also creates an unused copy in the USER database — the same thing
-- migrations 024/025 do for saved_searches and lead_search_jobs, and IF NOT
-- EXISTS makes the second application a no-op.

-- Seeded once per account, not once per script.
--
-- Kept on the account rather than as a marker row in lead_activity_log, which is
-- where it started: that log is explicitly best-effort ("callers should not
-- depend on them succeeding"), so a single failed analytics write would have the
-- three starter scripts reappear every time the customer deleted them, forever.
-- "Has this account been seeded" is a fact about the account, so it lives on the
-- account, next to outreach_profile.
ALTER TABLE `utiligo_users`
  ADD COLUMN `call_script_seeded_at` DATETIME NULL DEFAULT NULL AFTER `outreach_profile`;

CREATE TABLE IF NOT EXISTS `call_scripts` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NOT NULL,
  `name`         VARCHAR(120) NOT NULL,
  `body`         MEDIUMTEXT   NOT NULL,
  `sort_order`   INT          NOT NULL DEFAULT 0,
  `source`       VARCHAR(16)  NOT NULL DEFAULT 'manual',
  `times_used`   INT UNSIGNED NOT NULL DEFAULT 0,
  `last_used_at` DATETIME     NULL DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL,
  `updated_at`   DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_order` (`user_id`, `sort_order`, `id`),
  KEY `idx_user_last_used` (`user_id`, `last_used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
