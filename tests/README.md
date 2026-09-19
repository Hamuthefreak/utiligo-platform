# Payment-path and lead-search tests

An automated check on the two parts of the product that are most expensive to
get wrong: the code that decides what a customer is entitled to (checkout
creation, webhook handling, post-checkout reconciliation, cancellation), and the
code that runs a lead search (the async queue, its worker, and the poll the
browser uses to follow it).

```
php tests/run.php                 # everything
php tests/run.php --require-db    # fail (rather than skip) when MySQL is missing
```

No Composer, no PHPUnit, no Stripe account, no network access.

## What it needs

| Requirement | Why | If missing |
|---|---|---|
| PHP 8 | the application's own floor | — |
| `pdo_mysql` | the tests read and write a real database | the DB tests skip |
| `curl` | the HTTP tests drive real pages | the HTTP tests skip |
| a MySQL server on `127.0.0.1` | see below | the DB tests skip |

`run.php` re-executes itself once with `-d extension=pdo_mysql -d extension=curl`
if those extensions are installed but not enabled, so a bare `php tests/run.php`
usually just works.

**The tests never touch a remote database.** `t_db()` refuses any non-loopback
host and aborts the run, so a stray `storage/config_overrides.php` cannot turn a
test run into a production write.

### Getting a MySQL

```bash
docker run -d --name utiligo-test-mysql \
  -p 3306:3306 \
  -e MYSQL_ROOT_PASSWORD=utiligo_test_pw \
  -e MYSQL_DATABASE=utiligo_test_users \
  mysql:8.0
```

Or use `tests/mysql.sh start`, which does the same thing and waits until the
server accepts connections.

**Give it a real password.** `config.php` reads credentials as
`getenv('USERDB_PASS') ?: 'CHANGE_ME'`, and an empty string is falsy — so an
empty password silently becomes the literal `CHANGE_ME` and every connection is
refused with a confusing "Access denied".

Normally nothing else is needed. To point the suite somewhere else, set the
environment variables `config.php` already reads — `USERDB_HOST`, `USERDB_NAME`,
`USERDB_USER`, `USERDB_PASS`, and the matching `DB_*` for the platform database:

```bash
USERDB_NAME=my_test_users DB_USER=root DB_PASS=utiligo_test_pw \
DB_NAME=my_test_platform USERDB_PASS=utiligo_test_pw php tests/run.php
```

Publish the container on **3306**: `userdb.php` builds its DSN from host, name
and charset only, so there is no environment variable for a non-standard port.

The suite creates both databases if they are missing and applies `migrations/`
with the application's own runner, so the schema under test is always the
current one — including any migration added later.

CI uses a MySQL service container — see `.github/workflows/tests.yml`. That job
runs with `--require-db`, so a green build means the database work genuinely
executed.

## What is covered

| File | Scope |
|---|---|
| `test_plans.php` | plan order and ranking, which the never-downgrade rule is built from |
| `test_entitlement_sql.php` | the SQL guards on the entitlement write, asserted without a database |
| `test_signature.php` | webhook signature verification and the replay window |
| `test_checkout.php` | the Checkout Session the purchase asks for, against a local Stripe stub |
| `test_webhook.php` | the real endpoint over HTTP: replay, out-of-order delivery, forgery |
| `test_reconciliation.php` | the real success page: settled/paid checks, ownership, staleness |
| `test_cancellation.php` | the cancellation lifecycle, including re-subscription |
| `test_upgrade_in_place.php` | the checkout changing a running subscription instead of selling a second one |
| `test_subscription_updated.php` | Stripe-side plan changes and status transitions, through `.created`/`.updated` |
| `test_lead_search_queue.php` | the async lead search: atomic claiming, crash recovery, enqueue gating, quota, and a completed search run end to end through the worker |
| `test_auto_search.php` | saved searches that run themselves: the cadence gate, what an automated run asks for, delivery of the digest, and the failures it survives |

Mail is replaced by `tests/lib/mail_stub.php`, reached by `MAIL_API_BASE` — the
same arrangement as Stripe, and for the same reason. Before that variable existed
`send_email()` either posted to Brevo for real or fell through to PHP's `mail()`,
so "was the customer emailed, and what did it say" could not be asserted at all.
There is also `t_mail_sent()` / `t_mail_sent_to($email)`, which read a log of every
message the stub accepted.

Stripe is replaced by `tests/lib/stripe_stub.php`. That is not a test-only branch
inside the application: it is reached by setting `STRIPE_API_BASE`, a supported
configuration, so the request-building code under test is the real thing.

The stub records every request into `tests/tmp/stripe_requests.log`, not just the
most recent one, because the assertions that matter most here are about calls
that must NOT happen — `t_stripe_requests_to('/v1/checkout/sessions')` being
empty is what proves a second subscription was not sold. `t_set_stripe_failures()`
injects an error status for a given `METHOD PATH`, so "what does the app do when
Stripe is unreachable" can be asked without breaking the network.

The suite is built around scenarios that cost money:

- a replayed `checkout.session.completed` re-granting a cancelled plan
- a late `customer.subscription.deleted` revoking a plan the customer just
  re-bought
- an **unsigned** POST to the webhook (which used to be accepted — see below)
- `?plan=entrepreneur` on the success URL
- a Stripe session belonging to a different account
- a customer who cancels and then buys again
- a customer with a running subscription pressing upgrade, double-clicking the
  same plan, or upgrading while Stripe is unreachable

### Call scripts (the floating dock)

`test_call_scripts.php` covers `includes/call_scripts.php` (validation, ordering,
starter scripts, bulk-import parsing, placeholder substitution) and
`api/call-scripts.php`. The model layer is pure, so its rules are asserted
directly rather than only through the endpoint.

The assertions that matter most, because each one is a way this could quietly be
wrong:

- **the starters are seeded once, ever.** Deleting all three and listing again
  must leave an empty panel. The marker is `utiligo_users.call_script_seeded_at`,
  NOT a row in `lead_activity_log` where it started — that log is best-effort by
  design, and a correctness rule cannot rest on a write the code is told it may
  drop.
- **an unfilled placeholder is left standing and reported**, never blanked. A
  script that silently says "Hi ," is discovered mid-call.
- **every op is scoped to the caller**, including `reorder`, which takes a list of
  ids and would otherwise be a way to rewrite another account's ordering.
- **the dock is served to both paid tiers and to nobody else.** Free gets no
  script, no stylesheet and no config; Pro and Entrepreneur get all three, and
  the pop-out window is behind the same gate.

### The plan ladder

`test_plan_ladder.php` asserts the SHAPE of the plan table rather than each gate
where it lives: `free ⊂ pro ⊂ entrepreneur`. Limits are monotonic (with -1 read
as unlimited), source/format/provider lists grow, every named capability true for
Pro is true for Entrepreneur. A deliberately Ent-only feature is fine — the ladder
only forbids going backwards.

It exists because call scripts were briefly scoped to Pro alone, and the change
passed 730 tests while making upgrading a downgrade: every gate was correct in
isolation and nothing stated that Entrepreneur must never lose a feature. Mutating
`can_use_call_scripts()` back to Pro-only now fails 12 assertions, including
two in this file — and mutating `plan_has_pro_features()` fails 8, six of them in
files with nothing to do with call scripts.

The same file caught a live piece of drift that predated all of this. `plans.php`
carried a hand-written `features` array that only a `has_feature()` helper read —
and no production code called the helper — and it already disagreed with the
gates: it said Pro lacked `lead_enrich_basic` while Entrepreneur had the fuller
name, so the table was wrong on its own terms. Nothing enforced it, which is why
nothing had broken. Rather than keep two competing descriptions of the plans,
that array and its helper have been deleted; the named predicates are now the
only source of truth, and this file is what keeps them honest.

One thing this file cannot reach: whether the panel *renders*. See
`tests/browser/dock_harness.html` for that, which is how the collapse bug and the
unsaved first-run position were found.

### The signup plan modal

`test_signup_modal.php` guards the plan showcase on a fresh `register.php`. Its
primary CTA navigates to `selectedPlan.url`, but no card in the modal's PLANS
array defined `url` — so choosing a plan sent the browser to the literal string
"undefined", and the chosen plan never reached signup. Nothing looked broken: the
cards rendered perfectly, and the button did nothing useful.

The file reads the card `key`/`url` pairs out of the JS (it is a JS literal, and
this suite has no JS engine), asserts each card names a rooted target that matches
its plan, then fetches that target over real HTTP — a 200 from the real
`register.php`, with a body that already has the plan selected. Free is included,
and must NOT arrive with a paid plan baked in.

### The scheduled-search automation

A saved search with `notify_email = 1` used to be a notifier and nothing more: the
cron only reported leads that other people's searches had already put in the
shared pool. `test_auto_search.php` covers the replacement — the cron enqueues a
real search into the same queue an interactive search uses, then reports what that
run found.

The assertions worth knowing about:

- **A run in flight blocks a second one**, and `last_enqueued_at` is stamped when a
  run *starts*, so a failure cannot be retried in a loop. This is the only rate
  limit on automated Places spend.
- **The automation yields to the person at the screen.** An automated job is
  excluded from the interactive "is a search already in flight?" check, so a
  customer's own click is never answered with the automation's job — its progress,
  its results, and none of the params they just chose. Mutation-tested: reverting
  that exclusion fails two assertions.
- **Locked leads are never emailed.** A free-tier result payload masks its leads
  and returns the rest as locked stubs, so an account that was downgraded while its
  job sat in the queue would otherwise be emailed contacts it no longer pays for.
- **An empty run and a failed run send nothing**, but both are still recorded, and
  both release the saved search — an automation that stops running is worse than
  one that reports nothing, so it can never be wedged by one bad run.

### The lead-search queue

A lead search is enqueued (`lead_search_jobs`) rather than run in the request,
because it sleeps 3s per Google Places page token and then fans out to four more
source engines — 30-60 seconds of a PHP worker per search.

Two things are turned off in the test environment on purpose, and both are
configuration the application already supports:

- `UTILIGO_LEAD_SEARCH_KICK=0` disables the
  fire-and-forget kick that would otherwise start the worker the moment a search
  is enqueued. The tests invoke the worker explicitly instead, so a job cannot be
  consumed before the test has arranged its fixtures. **In production the kick is
  the thing that makes a search start immediately**; the cron entry below is the
  backstop when outbound HTTP is blocked.
- `GOOGLE_PLACES_API_KEY` is pinned to the placeholder, so a developer machine
  with a real key in its environment can never make the suite call Google. The
  runner refuses to start on that value, which is what the failure-path test
  asserts.

A completed search is exercised offline by seeding the `lead_cache` row the
runner looks for: the real runner then writes the lead to the shared pool,
unlocks it and completes the job, with no Google call at all.

Deploying this needs a cron entry, same shape as the other workers:

```
* * * * * curl -s "https://utiligo.ca/cron/lead_search_worker.php?secret=YOUR_CRON_SECRET" > /dev/null
```

## Honest gaps

- **The scheduled-search automation is proven up to the worker, not through it.**
  The tests run the real cron, so the enqueue, the cadence gate, the delivery and
  every failure path are real — but the search itself is simulated by completing
  the job row by hand. A real Places crawl inside a scheduled run is the same code
  path `test_lead_search_queue.php` already exercises, and no test here contacts
  Google.
- **A digest can lag a run by one cron interval.** Reporting is a second pass, so a
  job that finishes just after a pass was delivered is emailed on the next one — up
  to 30 minutes later with the documented schedule. That is deliberate (see
  cron/scheduled_searches.php) and irrelevant to a daily email, but it is not
  instant.
- **A successful live Stripe call is never exercised.** The stub returns
  realistic payloads, but a real payment is the only thing that proves the field
  names against Stripe's current API. The webhook's field paths are corroborated
  by the Checkout Session object documented at
  `docs.stripe.com/api/checkout/sessions/object`.
- **Billing-portal plan changes are handled, but events are still gated on the
  subscription the app holds.** `customer.subscription.created`/`.updated` are
  interpreted (see `test_subscription_updated.php`), and every branch matches on
  the subscription id, so an event naming a subscription the account has
  replaced is ignored rather than applied. That guard is what makes an old
  subscription's update harmless — but it also means an update to a *newer*
  subscription the account has not recorded yet is ignored. The one gap that is
  now closed is the one that mattered: the checkout asks Stripe's subscription
  list which subscription is live before it sells anything, and records it even
  when the stored id disagrees (see `test_upgrade_in_place.php`). An event that
  arrives before any purchase still has nothing to match on.
- **A plan change made in the Stripe dashboard, with no purchase here, is still
  not noticed.** `test_upgrade_in_place.php` proves the checkout now asks Stripe
  which subscription a customer has running and changes that one, and that it
  records what it finds — so a customer whose stored subscription id was stale is
  picked up the next time they start a purchase. What is still missing is a
  subscription started entirely outside the app being learned about *without* a
  purchase: that would be a reconciliation job over Stripe's subscription list,
  and it is not covered here.
- **Plans granted by an administrator before migration 023 keep a NULL ordering
  clock** until their next entitlement change, so a stale event could still
  apply to them. The migration backfills every account that has a
  `subscription_started_at`.
- The suite drives pages through PHP's built-in server, so it exercises no
  rewrite rules or TLS. It is an integration suite, not a deployment test.
- **The kick is off in tests**, so the self-HTTP path that starts the worker on
  a real deployment is not exercised here. The worker is proven, and the claim is
  proven atomic, but "does the enqueue actually manage to wake the worker up" is
  only observable on a real host.
- PHP's built-in server is single-threaded, so a test cannot poll the status
  endpoint *while* a worker is running. The tests wait for the job row to
  terminalize instead — the same thing the browser achieves by polling.

## Testing something visual

The PHP suite never renders a pixel, so anything whose failure mode is visual or
interactive is not covered by it — the call dock being the current example. For
those, `tests/browser/*.html` holds a harness served by the app's own dev server
that loads the real assets against a canned API:

    php -S 127.0.0.1:8123 -t .      # from the project root
    # then open /tests/browser/dock_harness.html

These are manual and are not run in CI. They earn their place anyway: every bug
the dock has had was found there and nowhere else.

## Adding a case

Drop a `tests/cases/test_*.php` file. It is included by `run.php`, and `$context`
holds `db_ready`, `app_url` and `stub_url` — throw `T_Skip` if what you need is
not there. Helpers live in `tests/lib/harness.php`.

Note that a case file must not rely on helpers defined in another case file:
files run in alphabetical order, and a function that has not been defined yet is
a fatal error. Give local helpers a distinctive name.
