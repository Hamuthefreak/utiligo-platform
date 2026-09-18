# Payment-path tests

An automated check on the code that decides what a customer is entitled to:
checkout creation, webhook handling, post-checkout reconciliation, and
cancellation.

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
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  -e MYSQL_DATABASE=utiligo_test_users \
  mysql:8.0
```

Normally nothing else is needed. To point the suite somewhere else, set the
environment variables `config.php` already reads — `USERDB_HOST`, `USERDB_NAME`,
`USERDB_USER`, `USERDB_PASS`, and the matching `DB_*` for the platform database:

```bash
USERDB_NAME=my_test_users DB_USER=root DB_PASS= DB_NAME=my_test_platform \
php tests/run.php
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

## Honest gaps

- **A successful live Stripe call is never exercised.** The stub returns
  realistic payloads, but a real payment is the only thing that proves the field
  names against Stripe's current API. The webhook's field paths are corroborated
  by the Checkout Session object documented at
  `docs.stripe.com/api/checkout/sessions/object`.
- **`customer.subscription.updated` is not handled anywhere in the application.**
  A customer who changes plan at Stripe rather than through checkout will keep
  their old plan until something else touches the account. Out of scope here,
  but it is the next gap.
- **Plans granted by an administrator before migration 023 keep a NULL ordering
  clock** until their next entitlement change, so a stale event could still
  apply to them. The migration backfills every account that has a
  `subscription_started_at`.
- The suite drives pages through PHP's built-in server, so it exercises no
  rewrite rules or TLS. It is an integration suite, not a deployment test.

## Adding a case

Drop a `tests/cases/test_*.php` file. It is included by `run.php`, and `$context`
holds `db_ready`, `app_url` and `stub_url` — throw `T_Skip` if what you need is
not there. Helpers live in `tests/lib/harness.php`.

Note that a case file must not rely on helpers defined in another case file:
files run in alphabetical order, and a function that has not been defined yet is
a fatal error. Give local helpers a distinctive name.
