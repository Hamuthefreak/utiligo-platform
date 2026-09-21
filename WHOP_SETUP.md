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

The site sells through those links **with no configuration at all** — the buttons
fall back to them when the API key is absent. What the API key adds is the
metadata that says *which account* is buying, and the webhook is what turns a
payment into a plan.

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

Without the API key the pricing buttons still work, they just sell through the
shareable links, and the webhook has to identify the buyer by the email they paid
with (which has to be verified on their account).

## 3. Verify it, without waiting for a customer

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
time). If it has not resolved, fix the account and re-apply by hand:

```sql
-- Then resend the event from Whop's dashboard; the row is left open on purpose so
-- a retry is processed rather than mistaken for a duplicate.
SELECT * FROM whop_events WHERE status = 'failed' ORDER BY id DESC;
```

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
  attribute or a log line; logged messages are redacted.

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
