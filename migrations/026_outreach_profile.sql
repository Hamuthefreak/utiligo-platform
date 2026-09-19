-- Migration 026: the sender profile for outreach drafts
-- =====================================================
-- Leads are delivered as a list of businesses. What the customer actually needs
-- at that moment is something to SEND — and an email is only worth sending if it
-- signs off as a person with an offer, not as "Utiligo".
--
--   outreach_profile   JSON, on utiligo_users. Keys:
--                        sender_name    who signs it (defaults to full_name)
--                        business_name  their agency or trading name
--                        offer          what they sell, in their own words
--                        website        optional, goes in the signature
--                        phone          optional, goes in the signature
--
-- JSON rather than five columns because it is a single blob the customer edits
-- whole, it may grow (a tone, a second template), and nothing queries it — the
-- draft builder reads it once per draft and falls back to sensible defaults for
-- every key that is missing.
--
-- Database: USER database (utiligo_users). Running it against the platform
-- database fails with 42S02 (unknown table), which the runner ignores.

ALTER TABLE `utiligo_users`
  ADD COLUMN `outreach_profile` JSON NULL DEFAULT NULL AFTER `notif_prefs`;
