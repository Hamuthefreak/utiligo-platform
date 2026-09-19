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
| `test_subscription_updated.php` | Stripe-side plan changes and status transitions, through `.created`/`.updated` |
| `test_lead_search_queue.php` | the async lead search: atomic claiming, crash recovery, enqueue gating, quota, and a completed search run end to end through the worker |

Stripe is replaced by `tests/lib/stripe_stub.php`. That is not a test-only branch
inside the application: it is reached by setting `STRIPE_API_BASE`, a supported
configuration, so the request-building code under test is the real thing.

The suite is built around scenarios that cost money:

- a replayed `checkout.session.completed` re-granting a cancelled plan
- a late `customer.subscription.deleted` revoking a plan the customer just
  re-bought
- an **unsigned** POST to the webhook (which used to be accepted — see below)
- `?plan=entrepreneur` on the success URL
- a Stripe session belonging to a different account
- a customer who cancels and then buys again

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

- **A successful live Stripe call is never exercised.** The stub returns
  realistic payloads, but a real payment is the only thing that proves the field
  names against Stripe's current API. The webhook's field paths are corroborated
  by the Checkout Session object documented at
  `docs.stripe.com/api/checkout/sessions/object`.
- **Billing-portal plan changes are handled, but only for the subscription the
  app holds.** `customer.subscription.created`/`.updated` are now interpreted
  (see `test_subscription_updated.php`), and every branch matches on the
  subscription id, so an event naming a subscription the account has replaced is
  ignored rather than applied. That guard is what makes an old subscription's
  update harmless — but it also means an update to a *newer* subscription the
  account has not recorded yet is ignored too, and the app never learns about a
  subscription started in the Stripe dashboard against a customer id it does not
  know. Reconciling that needs a call to Stripe's subscription list, which this
  suite has no way to make.
- **Nothing cancels a superseded subscription at checkout.** Buying a second plan
  creates a second live Stripe subscription rather than changing the first, so a
  customer who upgrades twice through checkout is billed twice until one is
  cancelled by hand. The webhook handles the entitlement correctly either way;
  the billing is the part that would need the Stripe API.
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

## Adding a case

Drop a `tests/cases/test_*.php` file. It is included by `run.php`, and `$context`
holds `db_ready`, `app_url` and `stub_url` — throw `T_Skip` if what you need is
not there. Helpers live in `tests/lib/harness.php`.

Note that a case file must not rely on helpers defined in another case file:
files run in alphabetical order, and a function that has not been defined yet is
a fatal error. Give local helpers a distinctive name.
