-- Migration 025: saved searches that run themselves
-- =================================================
-- A saved search with `notify_email = 1` used to be a *notifier and nothing
-- more*. cron/scheduled_searches.php only looked for leads that OTHER people's
-- searches had already dropped into the shared pool, so a customer whose city
-- nobody else searched got an email that said zero, every time — the one feature
-- only the Entrepreneur plan has could not find anything on its own.
--
-- These four columns are what let cron/scheduled_searches.php actually go and
-- search, and then deliver what it found.
--
--   run_every_hours    how often this saved search runs itself. 24 hours by
--                      default. This is the ONLY rate limit on automated Google
--                      Places spend, so every enqueue is gated on it: one run
--                      per window per saved search, whatever happened to the
--                      previous one.
--
--   last_enqueued_at   when a run was last started — the anchor the cadence is
--                      measured from. Deliberately separate from last_run_at
--                      (which records when a run was last *reported*, and is what
--                      the UI shows), so a run that fails still cannot be
--                      re-enqueued in a loop.
--
--   job_token          the lead_search_jobs token of the run still awaiting
--                      delivery. The cron enqueues into that queue rather than
--                      searching inline, so this column is the thread tying the
--                      finished job back to the saved search that asked for it.
--                      NULL means "nothing in flight and nothing to report",
--                      which is what makes a second enqueue impossible while one
--                      is outstanding.
--
--   last_error         why the last run failed, kept for the drawer and the log.
--                      A silently failing automation is worse than none: the
--                      customer is paying for a service that has quietly stopped.
--
-- Database: PLATFORM database (saved_searches lives with the lead workspace).
--
-- lead_search_jobs also gains one column here. The canonical DDL for that table
-- lives in migrations/024, and this is the flag that arrived with the automation
-- — it could not go in 024 because 024 has already been applied everywhere.
--
--   auto   true when the scheduled-search cron started the job rather than the
--          customer. Kept as a column instead of a key inside params_json so the
--          interactive enqueue can exclude it in SQL: a scheduled run uses the
--          same queue, and if it counted towards "a search is already in flight"
--          the customer's own click would be answered with the automation's job —
--          its progress, its results, and none of the params they just chose.

ALTER TABLE `lead_search_jobs`
  ADD COLUMN `auto` TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_id`;

-- The per-user concurrency checks read (user_id, auto, status) on every enqueue.
ALTER TABLE `lead_search_jobs`
  ADD KEY `idx_user_auto_status` (`user_id`, `auto`, `status`);
-- Safe to re-run: the runner records applied files in schema_migrations, and the
-- duplicate-column errors it ignores cover a database where an earlier attempt
-- half-applied this. Running against the USER database fails with 42S02
-- (unknown table), which the runner also ignores.

ALTER TABLE `saved_searches`
  ADD COLUMN `run_every_hours`  INT UNSIGNED  NOT NULL DEFAULT 24 AFTER `notify_email`;

ALTER TABLE `saved_searches`
  ADD COLUMN `last_enqueued_at` DATETIME      NULL DEFAULT NULL AFTER `last_run_at`;

ALTER TABLE `saved_searches`
  ADD COLUMN `job_token`        CHAR(32)      NULL DEFAULT NULL AFTER `last_enqueued_at`;

ALTER TABLE `saved_searches`
  ADD COLUMN `last_error`       VARCHAR(200)  NOT NULL DEFAULT '' AFTER `job_token`;

-- The cron's due-query reads notify_email = 1 and orders by last_enqueued_at.
ALTER TABLE `saved_searches`
  ADD KEY `idx_notify_due` (`notify_email`, `last_enqueued_at`);
