-- Migration 030: Whop subscriptions, and the ledger that makes them replay-safe
-- ========================================================================
-- Whop is the merchant of record for the two paid plans. This migration adds
-- everything the integration needs to be safe to receive the same event twice,
-- to receive events out of order, and to know which account a payment belongs to.
--
-- Database: USER database (utiligo_users_db via get_user_db()).
--
-- Safe to re-run: duplicate-column errors (42S21) are ignored by the runner, and
-- CREATE TABLE IF NOT EXISTS is idempotent. The runner executes this against the
-- platform database too, where utiligo_users does not exist and the two ALTERs
-- fail with 42S02 — also ignored.
--
-- NO SEMICOLONS IN THESE COMMENTS. The runner splits a file on `;`, so a
-- semicolon inside a comment truncates the file and silently skips everything
-- after it. migrations 021 and 025 both lost their real work to that.
--
-- ── whop_events, the idempotency ledger ─────────────────────────────────────
--
-- Whop delivers each event at least once and says so explicitly: "The same event
-- can arrive more than one time. Make your handler idempotent. Each retry of a
-- delivery has the same webhook-id." So the webhook-id is the primary key of
-- this table, and a second delivery of an event that already applied is dropped
-- before it can grant anything twice.
--
-- `status` is not decoration and the difference between its values is the whole
-- point of storing the row before doing the work:
--
--   received  we have accepted the delivery and started work. A retry that
--             finds this value MUST reprocess, because it means the previous
--             attempt died before finishing — treating it as a duplicate would
--             silently lose a real payment.
--   applied   the change landed. A retry is a no-op.
--   ignored   verified, understood, and deliberately nothing to do (an event
--             for a plan we do not sell, a membership that is not the one on
--             file). A retry is a no-op.
--   failed    work was attempted and raised. A retry reprocesses.
--
-- `event_at` is the envelope's own timestamp, kept so an incident review can see
-- what Whop thought the order was, and `detail` is the human sentence the
-- handler produced. Neither is read by the entitlement guards — those use
-- utiligo_users.subscription_event_at, which is written by the same UPDATE that
-- changes the plan (see includes/entitlements.php).

CREATE TABLE IF NOT EXISTS `whop_events` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `webhook_id`  VARCHAR(64)  NOT NULL,
  `event_type`  VARCHAR(64)  NOT NULL,
  `event_at`    DATETIME(6)  NULL DEFAULT NULL,
  `user_id`     INT UNSIGNED NULL DEFAULT NULL,
  `plan`        VARCHAR(20)  NULL DEFAULT NULL,
  `status`      VARCHAR(12)  NOT NULL DEFAULT 'received',
  `detail`      VARCHAR(255) NULL DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `applied_at`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_id` (`webhook_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_type_created` (`event_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── utiligo_users.whop_member_id / whop_membership_id ───────────────────────
--
-- Two identities, on purpose, because Whop's events carry different ones and
-- resolving the wrong one is how a subscription change lands on the wrong
-- account:
--
--   whop_member_id      (mber_...) the person. A membership event identifies the
--                       member, so this is what matches a renewal or a
--                       cancellation back to an account.
--   whop_membership_id  (mem_...)  the subscription. This is Whop's equivalent of
--                       stripe_subscription_id, and it is what stops the
--                       deactivation of an OLD membership from revoking a plan
--                       the customer has since rebought.
--
-- Deliberately NOT unique. A unique key would be correct in the ordinary case
-- (one Whop member per account) but would turn a data-entry mistake — the same
-- person paying while signed into two accounts — into a failed UPDATE that
-- leaves a paying customer on the free plan. The resolver in includes/whop.php
-- treats an ambiguous match as "do not act", which is recoverable, and the
-- index below keeps that lookup fast.

ALTER TABLE `utiligo_users` ADD COLUMN `whop_member_id` VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE `utiligo_users` ADD COLUMN `whop_membership_id` VARCHAR(64) NULL DEFAULT NULL;
ALTER TABLE `utiligo_users` ADD KEY `idx_whop_member` (`whop_member_id`);
ALTER TABLE `utiligo_users` ADD KEY `idx_whop_membership` (`whop_membership_id`);
