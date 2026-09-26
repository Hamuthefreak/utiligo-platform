# Whop setup

Whop is the merchant of record for both subscription plans. Everything that knows
Whop exists is in `includes/whop.php` (signature verification, the intent table,
identity resolution, the ledger) with two entry points: `whop-checkout.php` starts
a purchase, `whop-webhook.php` is what actually grants the plan.

**Nothing in the product grants a plan from a URL.** A pricing button sends the
customer to Whop; a signed `payment.succeeded` webhook (or a Stripe session this
server verifies, for the old flow) is the only thing that writes an entitlement.

The two live plans, already the defaults in `config.php`:

| Plan | Whop plan id | Price | Shareable link |
|---|---|---|---|
| Pro | `plan_F1oV5alSzN82W` | $21.99/mo | https://whop.com/checkout/plan_F1oV5alSzN82W |
| Entrepreneur | `plan_AHQpUK6syZ5Pu` | $49.99/mo | https://whop.com/checkout/plan_AHQpUK6syZ5Pu |

The buttons fall back to those links when the API key is absent, so a pricing
button is never an error page. What the API key adds is the metadata that says
*which account* is buying; what turns a payment into a plan is the webhook.

**Until `WHOP_WEBHOOK_SECRET` is set, checkout refuses to start.** That is
deliberate. Without the secret every delivery is refused with a 401, so a sale
would take the customer's money and leave them on the free plan while Whop
retried a webhook nobody could verify for three days. A customer we did not sell
to is a lost sale; a customer who paid and got nothing is a refund. The refusal is
`whop_can_accept_payments()` in `includes/whop.php`, it is asserted in
`tests/cases/test_whop.php` ("a signed-in customer … is redirected rather than
sent to pay"), and Admin → Payments is where the person who can fix it is told
what is missing.

---

## 1. In your Whop dashboard

1. **API key** — `whop.com/dashboard/developer` → create an account API key.
   Copy it once; Whop will not show it again.
2. **Webhook** — Developer → Webhooks → add endpoint:

   | Field | Value |
   |---|---|
   | Endpoint URL | `https://utiligo.ca/whop-webhook.php` |
   | Events | `payment.succeeded`, `membership.deactivated` |
   | API version | **v1** (only v1 is signed the way this code verifies) |

   Subscribe to exactly those two. A third event type is ignored and logged — that
   is how a webhook edited in the dashboard shows up, rather than being acted on.
3. **Copy the signing secret** — the `ws_…` string, straight after creating the
   webhook.
4. **Business id** — the `biz_…` id of the account that owns the plans (Developer →
   API, or the dashboard URL). Needed to create a checkout configuration.

## 2. On the server

There are two ways to get the three values onto the live site. The first is the
one to use.

### 2a. Admin → Settings → Payments (Whop) — no FTP, no redeploy

Log in as an admin, open **Admin → Settings**, scroll to **Payments (Whop)**,
paste the three values and save. They are written to
`storage/config_overrides.php`, which `config.php` loads **before** anything else,
so they take effect on the next request and survive every deploy: that file is not
in the repository (it is git-ignored), so the FTP deploy never touches it.

Two details that matter:

* Both secrets are **write-only in the form**: the page never echoes them back, so
  a blank field means "keep what is already saved" rather than "delete it". Saving
  the page to change a plan limit cannot wipe your API key.
* The section is grouped with a readiness banner at the top of the page, and the
  same facts are laid out on **Admin → Payments**. The endpoint URL, the two event
  names and the API version to register in Whop are on the Settings page too, next
  to the field the signing secret goes in.

**If a save seems to do nothing, open `storage/config_overrides.log`.** The settings
page appends one line per page load and one per save — the outcome, the byte count, the
names of the secret fields that came back filled, and PHP's own reason when a write is
refused. Key names only, never a value. A `load` line with no `save` after it means the
form never reached the server at all; a `save result=FAILED` line carries the reason; no
lines at all means the file manager is open on a different copy of the site (the absolute
path this page writes to is printed on the page itself, above the form).

The payment keys are read **straight out of `storage/config_overrides.php`** by the
payment code, so a value saved here takes effect even on a server whose `config.php`
predates this file and never learned to load it — the readiness banner on
**Admin → Payments** and the strip on **Admin → Settings** still say which saved settings
are being ignored, and the settings page can insert the missing `require_once` into that
`config.php` for you.

`BREVO_API_KEY` is editable the same way, under **Brevo** on that page — without
it `send_email()` falls back to PHP's `mail()`, which shared hosting usually
disables, and the alerts in §3b would be written to the log and never delivered.

### 2b. By hand in `config.php` — if you would rather not use the admin form

`config.php` is **excluded from the FTP deploy**, so the live copy is not the one
in this repository and nothing below reaches production by pushing.

Add these at the top of the deployed `config.php` (the blocks in that file are all
`if (!defined(...))`, so a definition placed above them wins):

```php
define('WHOP_API_KEY',        'whop_...');   // from step 1.1
define('WHOP_WEBHOOK_SECRET', 'ws_...');     // from step 1.3
define('WHOP_ACCOUNT_ID',     'biz_...');    // from step 1.4
```

Also confirm `APP_ENV` is `production` there (it is the default). That is what
keeps the billing page's development card form off the live site, and it is not
optional — see "Payment safety" below.

Without the webhook secret, checkout is refused and the billing page says so.
Without the API key, checkout still works but sells through the shareable links,
and the webhook then has to identify the buyer by the email they paid with (which
has to be verified on their account).

## 3. Verify it, without waiting for a customer

**Admin → Payments** is the console for this, and it does the checks for you:

| Button | What it proves |
|---|---|
| Create a test checkout | The API key, the account id and the plan id all work — it sends the *same* request the Subscribe button sends and reports what Whop answers. Charges nothing. |
| Sign and verify a sample | The secret is readable, the key derivation agrees, the envelope parses and the decision table answers. It signs a payment for a plan we do not sell, so the correct answer is `verified` then `ignored` — anything that granted a plan here is a bug. |
| Deliver a signed test event to our own endpoint | The endpoint is reachable and accepts a delivery — the one thing the offline check cannot prove. Same shape, so the worst a mistake can do is write one `ignored` ledger row. |

Below the buttons are the deliveries themselves, the account(s) with a Whop
membership but no paid plan (each with a **Grant** that goes through the same
entitlement writer as the webhook), and the exact endpoint URL, event names and
API version to register in the Whop dashboard — the same block is on
**Admin → Settings**, one scroll above the field the signing secret goes in.

The same checks by hand, if you prefer a terminal:

```bash
# 1. The endpoint exists and refuses an unsigned POST. Expect 405 then 401.
curl -s -o /dev/null -w '%{http_code}\n' https://utiligo.ca/whop-webhook.php
curl -s -o /dev/null -w '%{http_code}\n' -X POST -d '{"type":"payment.succeeded"}' \
     https://utiligo.ca/whop-webhook.php

# 2. A signed delivery end to end. Use Whop's dashboard "Send test event" button:
#    it signs with your real secret, so a 200 here proves the secret is right.
```

Then look at what happened, in this order:

- `storage/php_errors.log` — one line per delivery: `[whop][event] applied
  payment.succeeded: paid for pro (account 12, via metadata)`.
- The database, user database:

  ```sql
  SELECT id, plan, subscription_status, whop_member_id, whop_membership_id,
         subscription_event_at
    FROM utiligo_users WHERE id = 12;

  SELECT webhook_id, event_type, status, user_id, plan, detail, created_at
    FROM whop_events ORDER BY id DESC LIMIT 20;
  ```

`whop_events.status` is the whole story of a delivery:

| Status | Meaning | What happens on a redelivery |
|---|---|---|
| `received` | accepted, work started | reprocessed — a crashed handler must not swallow a payment |
| `applied` | the plan changed | dropped |
| `ignored` | verified and deliberately a no-op | dropped |
| `failed` | could not be applied (see `detail`) | reprocessed |

A `failed` row that says **"could not identify the account"** is a real payment
attached to nobody: the customer paid and is still on free. Whop retries for about
three days, which self-heals the common cause (their email was unverified at the
time). If it has not resolved, fix the account from **Admin → Payments**: the
"Subscribed at Whop, still on Free here" list is built from our own columns, and
Grant applies the plan the customer is paying for through
`entitlement_grant_from_whop()` — the same writer the webhook uses, with the
membership id already on file, so the ordering clock moves with the plan and a
later real event cannot undo the fix.

```sql
-- Then resend the event from Whop's dashboard; the row is left open on purpose so
-- a retry is processed rather than mistaken for a duplicate.
SELECT * FROM whop_events WHERE status = 'failed' ORDER BY id DESC;
```

## 3b. Alerts — the half that is not about the plan

A payment that cannot be applied is invisible from the only side that would
notice: the customer sees "Free", assumes the plan is coming, and says nothing. So
the four failures that cost money send one email each to **`ADMIN_EMAIL`**
(*Settings → Alerts*, which also says whether an alert could be delivered at all —
an empty address, or an address with no mail API key, means the alert reaches the
log file and nobody else):

| Alert | What it means | Throttle |
|---|---|---|
| *could not be attached to any account* | A verified payment with no metadata match, no known member and no unique verified email. The customer has paid. | once per delivery |
| *for a plan this deployment does not sell* | A paid payment whose plan id maps to no tier — a renamed plan, or another product sharing the webhook. | once per delivery |
| *could not be written* | The account was identified and the database refused the write; Whop has been asked to retry. | once per delivery |
| *deliveries are being refused* | Every webhook is failing signature verification — a rotated or mismatched secret, or forgery. Nothing can be applied until it is fixed. | once per six hours |

The throttle is on purpose and it is the difference between an alert and noise:
Whop retries a failed delivery for about three days, so "once per delivery" is one
email rather than a hundred, and a burst of bad payments is capped at
`WHOP_ALERT_MAX_PER_HOUR` (3) per kind per hour before it is only logged. State
lives in `storage/whop_alert_*` — delete those files to re-arm an alert.

## 4. Payment safety, and why each rule exists

These are enforced in code and pinned by `tests/cases/test_whop.php`:

- **Signature first.** The raw body is read, the HMAC over
  `{webhook-id}.{webhook-timestamp}.{raw body}` is checked in constant time, and
  only then is the JSON decoded. An unsigned request cannot reach the database at
  all — no ledger row, no lookup, one HMAC.
- **Replay window.** A timestamp more than `WHOP_WEBHOOK_TOLERANCE` (300s) from our
  clock is refused in either direction, so a captured delivery cannot be replayed.
- **Ordering by event time, not arrival.** Whop does not promise order. Every
  change carries the event's own timestamp, and the entitlement writer refuses
  anything that is not strictly newer, so a delayed cancellation cannot revoke a
  fresh purchase.
- **The subscription must match.** A cancellation only applies to the membership id
  on file, so the *old* subscription's cancellation cannot end the *new* one a
  customer just bought.
- **One checkout, ever, per subscriber.** `whop-checkout.php` refuses to start a
  second checkout for an account with a live subscription (Whop or Stripe) and
  sends them to Whop's billing portal instead — a second membership would bill them
  twice, invisibly.
- **Identity in a fixed order**: our own checkout metadata, then the stored member
  id, then a *unique verified* email. A payment is never what confirms an email,
  and an ambiguous match is refused rather than guessed.
- **Secrets never leave the server.** No config value is echoed into a page, an
  attribute or a log line; logged messages are redacted, and `whop_config_report()`
  answers the admin pages with a boolean and a length rather than a masked preview,
  because a masked value is still a value in a response body.
- **No sale we cannot honour.** Checkout refuses while the deployment has no webhook
  secret (`whop_can_accept_payments()`), because every delivery would be refused and
  a paying customer would stay on free. The refusal is shown on the billing page
  before the plan cards and in place of the button.

**Manual activation is off.** The billing page used to render a card form under a
banner reading "any 12-digit number works, no real charge" — in a real browser that
was a free-plan button. It now needs `TEST_PAYMENT_MODE` (Admin → Settings, default
**off**) *and* a non-production `APP_ENV`, in both the form and the POST handler.

## 5. Stripe is still there

Existing Stripe subscribers keep working: `stripe-checkout.php`,
`stripe-webhook.php` and `purchase-success.php` are untouched. An account with a
Stripe subscription cannot start a Whop checkout, because a subscription cannot be
moved between merchants — it is refused with an explanation on the billing page.

## 6. Where the tests live

`tests/cases/test_whop.php` covers the whole path against a real database and a
Whop API stub (`tests/lib/whop_stub.php`) — signature attacks, replay, out-of-order
delivery, identity, the ledger, checkout creation and the billing page's refusals.
`php tests/run.php` runs it; `api.whop.com` and Stripe are never contacted.
