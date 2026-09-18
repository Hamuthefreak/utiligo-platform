-- Migration 023: order plan changes by event time rather than arrival order
-- ========================================================================
-- Adds the columns includes/entitlements.php needs to make a plan change
-- depend on WHEN it happened instead of WHEN Stripe delivered it.
--
--   subscription_event_at    the unix timestamp of the last change applied,
--                            from Stripe's event `created` for webhook work and
--                            from the wall clock for local/admin changes. A write
--                            is only allowed when it is strictly newer than this,
--                            so out-of-order delivery and replayed events both
--                            resolve to the newest change winning.
--
--                            Microseconds, because Stripe timestamps are whole
--                            seconds while local changes are not: two operator
--                            actions inside one second would otherwise collide and
--                            the second would be refused by its own guard.
--
--   stripe_subscription_id   which subscription the account is actually on.
--                            customer.subscription.deleted used to be matched by
--                            customer id alone, so the deletion of an OLD
--                            subscription would revoke a newer one the customer
--                            had already bought. Its event timestamp is NEWER
--                            than the re-purchase, so time alone cannot save
--                            them -- identity has to break the tie.
--
-- Database: USER database (utiligo_users_db via get_user_db()).
-- Safe to re-run. Duplicate-column and duplicate-key errors are ignorable, and
-- the runner executes this against the platform DB too, where utiligo_users
-- does not exist and both ALTERs fail with 42S02 (also ignorable).

ALTER TABLE utiligo_users
  ADD COLUMN subscription_event_at DATETIME(6) NULL DEFAULT NULL
  AFTER subscription_started_at;

ALTER TABLE utiligo_users
  ADD COLUMN stripe_subscription_id VARCHAR(255) NULL DEFAULT NULL
  AFTER stripe_customer_id;

-- Index for the customer-matched lookups the subscription lifecycle events use.
-- Every subscription.updated/deleted/past_due webhook does
-- `WHERE stripe_customer_id = ?`, which was previously a full table scan.
ALTER TABLE utiligo_users
  ADD KEY idx_stripe_customer (stripe_customer_id);

-- Backfill the ordering clock for accounts that already have a subscription, so
-- the very first event to arrive after this migration is compared against the
-- upgrade that is already recorded rather than against NULL. subscription_started_at
-- is written by NOW() at the moment of the upgrade, which is a moment after the
-- Stripe event that caused it, so replaying that original event is correctly
-- refused while anything genuinely newer still applies.
--
-- Rows with no subscription_started_at (plans granted by an administrator rather
-- than through Stripe) keep a NULL clock. That is deliberate: inventing a
-- timestamp could block a legitimate event, and the column starts being kept
-- accurate from the next change onwards.
UPDATE utiligo_users
SET subscription_event_at = subscription_started_at
WHERE subscription_event_at IS NULL
  AND subscription_started_at IS NOT NULL;
