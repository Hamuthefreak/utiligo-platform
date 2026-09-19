<?php
/**
 * includes/entitlements.php
 *
 * The ONLY place in Utiligo that writes `utiligo_users.plan` or
 * `utiligo_users.subscription_status` as an entitlement change.
 *
 * WHY THIS EXISTS
 * ──────────────
 * Granting and revoking a paid plan used to be six hand-written UPDATE
 * statements spread across stripe-webhook.php, portal/billing.php,
 * admin/users.php, portal/settings.php and purchase-success.php. They disagreed
 * with each other in ways that cost real money:
 *
 *   • Only the webhook carried the "stripe_customer_id column may not exist
 *     yet" fallback. billing.php and purchase-success.php each grew their own
 *     copy of the same try/catch.
 *   • Nothing recorded WHEN a change happened, so a change's effect depended on
 *     the order Stripe happened to deliver (or re-deliver) its events. A
 *     retried `checkout.session.completed` could re-grant a plan to a customer
 *     who had already cancelled, and a late `customer.subscription.deleted`
 *     could revoke one they had just re-bought.
 *   • The never-downgrade rule existed in exactly one place (the success page),
 *     so nothing stopped a stale event from walking a customer backwards.
 *
 * EVENT ORDERING
 * ──────────────
 * Every Stripe-originated change carries `event_at`: the unix timestamp Stripe
 * put on the event (or, for local changes such as an admin action, the moment
 * the change took effect). It is stored in `subscription_event_at`, and a write
 * is only allowed when it is strictly newer than the one that last landed:
 *
 *     subscription_event_at IS NULL OR subscription_event_at < :event_at
 *
 * Two consequences worth stating plainly:
 *
 *   1. Arrival order stops mattering. Events may be delivered out of order, or
 *      replayed by Stripe's retry schedule, and the newest change still wins.
 *   2. A retry of an event that already applied is a natural no-op, because its
 *      timestamp is not strictly newer. That is also why the guard is `<` and
 *      not `<=`: if the first attempt FAILED, the timestamp was never advanced
 *      (it is written by the same UPDATE), so the retry still gets through.
 *      No separate "seen event ids" table is needed.
 *
 * The residual limitation is that Stripe timestamps have one-second resolution,
 * so two events landing inside the same second cannot be ordered against each
 * other. Where that matters — a cancellation racing a re-subscription — the
 * subscription id check below breaks the tie on identity rather than time.
 *
 * WHY THERE IS NO "IS THIS ACCOUNT CANCELLED?" GUARD
 * ────────────────────────────────────────────────
 * The obvious belt-and-braces guard — refuse to grant a paid plan to an account
 * whose subscription reads 'cancelled' — is wrong, and was removed after the
 * test suite caught it. It cannot tell a replayed URL from a real purchase, so
 * it blocks the customer who cancels and then buys again: a paying customer
 * left on the free plan, which is the exact failure this module exists to
 * prevent.
 *
 * The event clock already distinguishes the two cases exactly. A checkout
 * session's `created` is stamped before the purchase completes, so it is always
 * older than a cancellation of the subscription that purchase produced — a
 * replayed success URL therefore loses to the cancellation on time alone. A
 * genuine re-subscription produces a new session, created after the
 * cancellation, and wins. Time decides; the stored status does not need a vote.
 *
 * NEVER THROWS
 * ────────────
 * Every entry point swallows its errors and reports them in the returned
 * result. Callers include stripe-webhook.php (which must still answer Stripe
 * 200 so the event is not retried forever), a post-payment landing page, and
 * admin actions. A failed entitlement write is recoverable; a 500 is not.
 */

require_once __DIR__ . '/plans.php';
// Only *defines* get_user_db(); the connection is lazy, so requiring this
// cannot 500 a caller that never touches the database.
require_once __DIR__ . '/../userdb.php';

/**
 * The account's stored plan, or null when it cannot be read.
 *
 * Exists so a caller can ask "what does this account actually have?" without
 * duplicating the column name. purchase-success.php uses it to decide whether a
 * verified purchase is reflected in the account before celebrating it.
 *
 * Either identity works. The customer-id form exists because Stripe's
 * subscription events are matched on the customer, so the caller handling one has
 * a customer id and no user id yet — and asking "is this account already paid?"
 * with a user id of 0 would answer "no" for every such event.
 */
function entitlement_current_plan(int $userId, string $customerId = ''): ?string
{
    $customerId = trim($customerId);
    if ($userId <= 0 && $customerId === '') {
        return null;
    }

    try {
        if ($userId > 0) {
            $stmt = get_user_db()->prepare('SELECT plan FROM utiligo_users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
        } else {
            $stmt = get_user_db()->prepare('SELECT plan FROM utiligo_users WHERE stripe_customer_id = ? LIMIT 1');
            $stmt->execute([$customerId]);
        }

        $row = $stmt->fetch();
        return $row ? (string)($row['plan'] ?? '') : null;
    } catch (\Throwable $e) {
        entitlement_log('read', 'could not read the plan for '
            . ($userId > 0 ? 'user ' . $userId : 'customer ' . $customerId) . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * The Stripe identity and subscription state this account has recorded.
 *
 * Exists for the checkout, which has to answer one question before it sells
 * anything: does this customer ALREADY have a running subscription? Getting that
 * wrong by defaulting to "no" charges them twice, so the answer has to come from
 * the one place that knows the columns.
 *
 * Returns ['customer_id', 'subscription_id', 'plan', 'status', 'found'].
 * `found => false` means the row could not be read at all, which a caller must
 * treat as "unknown" rather than "nothing running".
 *
 * The reduced-column retry is not decorative: `stripe_subscription_id` arrives
 * with migration 023, and an install that has not run it would otherwise fail
 * this SELECT, report "no customer", and mint a brand-new Stripe customer for
 * someone who already subscribes.
 */
function entitlement_stripe_state(int $userId): array
{
    $state = [
        'customer_id'     => '',
        'subscription_id' => '',
        'plan'            => entitlement_default_plan(),
        'status'          => 'none',
        'found'           => false,
    ];

    if ($userId <= 0) {
        return $state;
    }

    // Newest schema first, then the shapes an older install would have.
    $columns = [
        'plan, subscription_status, stripe_customer_id, stripe_subscription_id',
        'plan, subscription_status, stripe_customer_id',
    ];

    foreach ($columns as $columnList) {
        try {
            $stmt = get_user_db()->prepare('SELECT ' . $columnList . ' FROM utiligo_users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            if (!$row) {
                return $state;
            }

            return [
                'customer_id'     => trim((string)($row['stripe_customer_id'] ?? '')),
                'subscription_id' => trim((string)($row['stripe_subscription_id'] ?? '')),
                'plan'            => (string)($row['plan'] ?? entitlement_default_plan()),
                'status'          => (string)($row['subscription_status'] ?? 'none'),
                'found'           => true,
            ];
        } catch (\Throwable $e) {
            if (!entitlement_is_schema_error($e)) {
                entitlement_log('stripe-state', 'could not read the row for user ' . $userId . ': ' . $e->getMessage());
                return $state;
            }
        }
    }

    entitlement_log('stripe-state', 'no readable utiligo_users row shape for user ' . $userId);
    return $state;
}

/**
 * Canonical plan order, cheapest first.
 *
 * Taken from plan_config() rather than duplicated, so this module cannot drift
 * from the plan table the rest of the app already trusts. Both the PHP-side
 * rank comparison and the SQL FIELD() guard below are built from this list.
 */
function entitlement_plan_order(): array
{
    return array_keys(plan_config());
}

/** The plan a brand-new account starts on. Single definition, used by signup. */
function entitlement_default_plan(): string
{
    return 'free';
}

/**
 * Position in entitlement_plan_order(), or null for a plan we don't recognise.
 * Recognising nothing is different from recognising "free": an unknown stored
 * value must never be silently treated as an upgrade from free.
 */
function entitlement_plan_rank(string $plan): ?int
{
    $rank = array_search(strtolower(trim($plan)), entitlement_plan_order(), true);
    return $rank === false ? null : (int)$rank;
}

/** Lowercase/trim a plan name, or null when it is not a plan we know. */
function entitlement_normalize_plan(string $plan): ?string
{
    $plan = strtolower(trim($plan));
    return entitlement_plan_rank($plan) === null ? null : $plan;
}

/** Does moving from $current to $target increase entitlement? */
function entitlement_is_upgrade(string $current, string $target): bool
{
    $from = entitlement_plan_rank($current);
    $to   = entitlement_plan_rank($target);
    return $from !== null && $to !== null && $to > $from;
}

/**
 * The plan names as a quoted SQL list.
 *
 * Goes into SQL text unparameterised because it is built from plan_config() — a
 * literal array in this codebase — and never from user input or a request
 * parameter. Keep it that way: nothing caller-supplied may reach this string.
 */
function entitlement_plan_sql_list(): string
{
    return "'" . implode("', '", entitlement_plan_order()) . "'";
}

/**
 * A SQL expression ranking the `plan` column, for use in guards.
 *
 * LOWER(TRIM(...)) mirrors entitlement_plan_rank() so the PHP check and the SQL
 * guard agree whatever the column's collation is: under a case-sensitive
 * collation a stored 'Pro' would otherwise rank 0 in SQL while PHP called it an
 * upgrade from free. An empty/NULL plan reads as the default (free) tier.
 */
function entitlement_rank_sql(string $column = 'plan'): string
{
    $fallback = entitlement_default_plan();
    return "FIELD(COALESCE(NULLIF(LOWER(TRIM($column)), ''), '$fallback'), "
         . entitlement_plan_sql_list() . ")";
}

/**
 * Build the UPDATE for one entitlement change. Pure and connection-free, so the
 * guards can be asserted directly — they are the part that hands out paid
 * features, and the part that would otherwise need a live MySQL to exercise.
 *
 * Returns [sql, params]; ['', []] when the change is not expressible (no
 * identity, or nothing to set).
 *
 * @param array $c    normalised change (see entitlement_apply)
 * @param array $omit optional columns to leave out, for installs whose schema
 *                    predates a migration. See entitlement_apply's retry.
 */
function entitlement_statement(array $c, ?string $plan, ?string $status, array $omit = []): array
{
    $sets = [];
    $params = [];
    $where = [];
    $wparams = [];

    /* ── Identity first, so the WHERE clause reads in the natural order ── */
    $userId     = (int)$c['user_id'];
    $customerId = trim((string)$c['customer_id']);

    if ($userId > 0) {
        $where[]   = 'id = ?';
        $wparams[] = $userId;
    } elseif ($customerId !== '') {
        // Matched by Stripe customer, for the subscription lifecycle events
        // that only carry a customer id.
        $where[]   = 'stripe_customer_id = ?';
        $wparams[] = $customerId;
    } else {
        return ['', []];
    }

    /* ── SET ── */
    if ($plan !== null) {
        $sets[]   = 'plan = ?';
        $params[] = $plan;

        if (empty($c['allow_downgrade'])) {
            // The never-downgrade rule, restated in SQL so it is atomic against
            // a concurrent writer. `rank > 0` leaves an unrecognised stored
            // plan alone rather than guessing at it.
            //
            // The comparison is `<=`, not `<`: buying the SAME plan again is
            // not a downgrade, and it has real work to do — a customer who
            // cancelled and re-subscribed to the plan they had is stored as
            // 'pro' with status 'cancelled', so a strict `<` would refuse the
            // purchase and leave them reading as cancelled despite paying. The
            // event clock still refuses a replayed older purchase.
            $rank    = entitlement_rank_sql('plan');
            $where[] = "$rank > 0 AND $rank <= FIELD(?, " . entitlement_plan_sql_list() . ")";
            $wparams[] = $plan;
        }
    }

    if ($status !== null) {
        $sets[]   = 'subscription_status = ?';
        $params[] = $status;
    }

    if (!empty($c['store_customer']) && !in_array('stripe_customer_id', $omit, true)) {
        // COALESCE/NULLIF so an empty customer id never clobbers a stored one.
        $sets[]   = "stripe_customer_id = COALESCE(NULLIF(?, ''), stripe_customer_id)";
        $params[] = $customerId;
    }

    $subscriptionId = trim((string)$c['subscription_id']);
    if ($subscriptionId !== '' && !in_array('stripe_subscription_id', $omit, true)) {
        $sets[]   = 'stripe_subscription_id = ?';
        $params[] = $subscriptionId;

        if (!empty($c['match_subscription'])) {
            // Only act on the subscription we actually hold. This is what stops
            // a cancelled-and-resubscribed customer losing their new plan when
            // the OLD subscription's deletion event finally arrives — its
            // timestamp is newer, so ordering alone cannot save them.
            // A NULL column means "recorded before we tracked subscriptions",
            // and is accepted so a real cancellation is never lost.
            $where[]   = '(stripe_subscription_id IS NULL OR stripe_subscription_id = ?)';
            $wparams[] = $subscriptionId;
        }
    }

    if (!empty($c['started_at']) && !in_array('subscription_started_at', $omit, true)) {
        $sets[] = 'subscription_started_at = NOW()';
    }

    // Formatted as a fixed-precision decimal string rather than passed as a
    // float: microsecond precision is what makes two local changes in the same
    // second distinguishable, and PDO's default float rendering would drop it
    // (PHP's precision setting is 14 significant digits, and these values have
    // 10 to the left of the point). Stripe's whole-second timestamps arrive as
    // ints and format to an exact .000000, which is why they need no special
    // case here.
    $eventAt = $c['event_at'] === null ? null : sprintf('%.6F', (float)$c['event_at']);
    if ($eventAt !== null && !in_array('subscription_event_at', $omit, true)) {
        // FROM_UNIXTIME() and NOW(6) both resolve in MySQL's session time zone,
        // so a Stripe timestamp and a local one are directly comparable. The
        // migration backfills the column from subscription_started_at, which
        // NOW() also wrote, so pre-existing rows keep the same frame.
        $sets[]    = 'subscription_event_at = FROM_UNIXTIME(?)';
        $params[]  = $eventAt;
        $where[]   = '(subscription_event_at IS NULL OR subscription_event_at < FROM_UNIXTIME(?))';
        $wparams[] = $eventAt;
    }

    if (!$sets) {
        return ['', []];
    }

    $sql = 'UPDATE utiligo_users SET ' . implode(', ', $sets)
         . ' WHERE ' . implode(' AND ', $where);

    return [$sql, array_merge($params, $wparams)];
}

/**
 * Apply one entitlement change. The only function that writes plan/status.
 *
 * Recognised keys in $change:
 *
 *   'user_id'            int     match the account by id
 *   'customer_id'        string  …or by Stripe customer (when user_id is 0)
 *   'subscription_id'    string  Stripe subscription this change refers to
 *   'plan'               string  target plan; null to leave the plan alone
 *   'status'             string  subscription_status; null to leave it alone
 *   'event_at'           int     unix seconds this change took effect. null =
 *                                no ordering guard (applies unconditionally)
 *   'allow_downgrade'    bool    permit moving down the plan order (default false)
 *   'store_customer'     bool    also record stripe_customer_id
 *   'match_subscription' bool    require the stored subscription id to match
 *   'started_at'         bool    set subscription_started_at = NOW()
 *   'source'             string  for the error log; name the caller
 *
 * Returns ['applied' => bool, 'reason' => string, 'affected' => int,
 *          'plan' => ?string, 'source' => string]. Never throws.
 */
function entitlement_apply(array $change): array
{
    $c = $change + [
        'user_id'            => 0,
        'customer_id'        => '',
        'subscription_id'    => '',
        'plan'               => null,
        'status'             => null,
        'event_at'           => null,
        'allow_downgrade'    => false,
        'store_customer'     => false,
        'match_subscription' => false,
        'started_at'         => false,
        'source'             => 'entitlement',
    ];

    $source = (string)$c['source'];

    $plan = null;
    if ($c['plan'] !== null) {
        $plan = entitlement_normalize_plan((string)$c['plan']);
        if ($plan === null) {
            return entitlement_result(false, 'unrecognised plan', 0, null, $source);
        }
    }

    $status = null;
    if ($c['status'] !== null) {
        $status = strtolower(trim((string)$c['status']));
        if ($status === '') {
            $status = null;
        }
    }

    if ($plan === null && $status === null) {
        return entitlement_result(false, 'nothing to change', 0, null, $source);
    }

    if ((int)$c['user_id'] <= 0 && trim((string)$c['customer_id']) === '') {
        return entitlement_result(false, 'no user or customer to match', 0, null, $source);
    }

    try {
        $pdo = get_user_db();
    } catch (\Throwable $e) {
        entitlement_log($source, 'no database: ' . $e->getMessage());
        return entitlement_result(false, 'database unavailable', 0, $plan, $source);
    }

    // Read first purely to explain the outcome and skip pointless writes — the
    // SQL guards remain authoritative, so nothing here is load-bearing.
    $current = entitlement_read($pdo, $c);

    if ($current !== null) {
        if ($plan !== null && empty($c['allow_downgrade'])) {
            $from = entitlement_plan_rank((string)($current['plan'] ?? ''));
            $to   = entitlement_plan_rank($plan);
            if ($from === null) {
                // '' is the only unrecognised value that is genuinely "never
                // set" — that is the free tier, so treat it as rank 0.
                $from = trim((string)($current['plan'] ?? '')) === ''
                    ? entitlement_plan_rank(entitlement_default_plan())
                    : null;
            }
            // Strictly lower is a downgrade and is refused. Equal is allowed —
            // see the SQL guard above for why a same-plan re-purchase matters.
            if ($from === null || $to === null || $to < $from) {
                entitlement_log($source, "refused: {$plan} would be a downgrade (currently "
                    . (string)($current['plan'] ?? '?') . ")");
                return entitlement_result(false, 'would be a downgrade', 0, $plan, $source);
            }
        }
    }

    // Optional columns are dropped one group at a time, in the order the
    // migrations that introduced them would have run. This replaces the
    // per-call-site "retry without stripe_customer_id" copies that used to live
    // in the webhook, billing and the success page.
    $fallbacks = [
        [],
        ['stripe_customer_id'],
        ['stripe_customer_id', 'stripe_subscription_id'],
        ['stripe_customer_id', 'stripe_subscription_id', 'subscription_event_at'],
        ['stripe_customer_id', 'stripe_subscription_id', 'subscription_event_at', 'subscription_started_at'],
    ];

    $lastError = '';
    foreach ($fallbacks as $i => $omit) {
        [$sql, $params] = entitlement_statement($c, $plan, $status, $omit);
        if ($sql === '') {
            return entitlement_result(false, 'nothing to change', 0, $plan, $source);
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $affected = (int)$stmt->rowCount();

            if ($i > 0) {
                entitlement_log($source, 'applied without column(s): ' . implode(', ', $omit));
            }
            if ($affected > 0) {
                entitlement_log($source, 'applied plan=' . ($plan ?? '(unchanged)')
                    . ' status=' . ($status ?? '(unchanged)') . ' affected=' . $affected);
            } else {
                // A guarded no-op and an unguarded one both return 0 rows. Two of
                // the three cases behind that are exactly the ones worth being
                // able to see in an incident review — a replay, and an event for a
                // subscription the account has since replaced — so say which.
                // Explanatory only: the guards in the SQL are authoritative.
                entitlement_log($source, 'matched no rows: '
                    . entitlement_no_change_detail($pdo, $c, $current, $plan, $status));
            }

            return entitlement_result($affected > 0, $affected > 0 ? 'applied' : 'no change', $affected, $plan, $source);
        } catch (\Throwable $e) {
            $lastError = $e->getMessage();

            if ($i === count($fallbacks) - 1) {
                break;
            }
            // Only the schema-shape cases are worth retrying. Anything else
            // (deadlock, permission, value too long) fails the same way
            // however many columns we drop, so skip straight to the last shape
            // and report the original error.
            if (!entitlement_is_schema_error($e)) {
                break;
            }
            entitlement_log($source, 'retrying without ' . implode(', ', $fallbacks[$i + 1]) . ': ' . $lastError);
        }
    }

    entitlement_log($source, 'write failed: ' . $lastError);
    return entitlement_result(false, 'write failed', 0, $plan, $source);
}

/**
 * Explain a guarded UPDATE that matched no rows. Never throws.
 *
 * 'no change' is the right thing to RETURN — callers treat it as a no-op — but it
 * conflates three situations the log should not conflate: a replayed event, an
 * event for a subscription the account has replaced, and a genuine nothing-to-do.
 * The first two are the bug class this module exists to fix, so a support engineer
 * needs to be able to tell them apart without reading the SQL.
 *
 * The timestamp comparison is done by MySQL rather than in PHP on purpose: the
 * guard compares in the session time zone, and a PHP-side comparison of a string
 * timestamp against a unix one would not necessarily agree with it.
 */
function entitlement_no_change_detail(PDO $pdo, array $c, ?array $current, ?string $plan, ?string $status): string
{
    if ($current === null) {
        return 'no matching account, or the row could not be read';
    }

    $detail = ['stored plan=' . (string)($current['plan'] ?? '?')
        . ' status=' . (string)($current['subscription_status'] ?? '?')];

    try {
        $userId     = (int)$c['user_id'];
        $customerId = trim((string)$c['customer_id']);
        $eventAt    = $c['event_at'] === null ? null : sprintf('%.6F', (float)$c['event_at']);

        $sql = 'SELECT stripe_subscription_id'
             . ($eventAt === null
                    ? ', 0 AS event_not_newer'
                    : ', (subscription_event_at IS NOT NULL AND subscription_event_at >= FROM_UNIXTIME(?)) AS event_not_newer')
             . ' FROM utiligo_users WHERE '
             . ($userId > 0 ? 'id = ?' : 'stripe_customer_id = ?') . ' LIMIT 1';

        $params = $eventAt === null ? [] : [$eventAt];
        $params[] = $userId > 0 ? $userId : $customerId;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        if ($row) {
            if (!empty($row['event_not_newer'])) {
                $detail[] = 'its event is not newer than the one already applied (replayed or out of order)';
            }

            $requested = trim((string)$c['subscription_id']);
            $stored    = trim((string)($row['stripe_subscription_id'] ?? ''));
            if (!empty($c['match_subscription']) && $requested !== '' && $stored !== '' && $stored !== $requested) {
                $detail[] = 'it names subscription ' . $requested . ', but the account is on ' . $stored;
            }
        }
    } catch (\Throwable $e) {
        $detail[] = 'could not explain (' . $e->getMessage() . ')';
    }

    // The third case: the account already is what was asked for, so nothing was
    // refused and there is genuinely nothing to do.
    $planMatches = $plan === null
        || entitlement_plan_rank((string)($current['plan'] ?? '')) === entitlement_plan_rank($plan);
    $statusMatches = $status === null
        || strtolower(trim((string)($current['subscription_status'] ?? ''))) === $status;
    if ($planMatches && $statusMatches) {
        $detail[] = 'the account is already in the requested state';
    }

    return implode('; ', $detail);
}

/**
 * Current stored state for the account this change targets, or null when the
 * row cannot be read (including "no such user", which is not an error here —
 * the UPDATE simply matches nothing).
 */
function entitlement_read(PDO $pdo, array $c): ?array
{
    try {
        $userId     = (int)$c['user_id'];
        $customerId = trim((string)$c['customer_id']);

        if ($userId > 0) {
            $stmt = $pdo->prepare('SELECT id, plan, subscription_status, subscription_event_at, stripe_subscription_id
                                   FROM utiligo_users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
        } elseif ($customerId !== '') {
            $stmt = $pdo->prepare('SELECT id, plan, subscription_status, subscription_event_at, stripe_subscription_id
                                   FROM utiligo_users WHERE stripe_customer_id = ? LIMIT 1');
            $stmt->execute([$customerId]);
        } else {
            return null;
        }

        $row = $stmt->fetch();
        return $row ?: null;
    } catch (\Throwable $e) {
        // A schema predating the newer columns lands here. That is not fatal —
        // the guarded UPDATE below still runs — so stay quiet and let it decide.
        return null;
    }
}

function entitlement_result(bool $applied, string $reason, int $affected, ?string $plan, string $source): array
{
    return [
        'applied'  => $applied,
        'reason'   => $reason,
        'affected' => $affected,
        'plan'     => $plan,
        'source'   => $source,
    ];
}

function entitlement_log(string $source, string $message): void
{
    error_log('[entitlement][' . $source . '] ' . $message);
}

/**
 * Is this failure a missing-column/table problem — i.e. a schema that predates
 * one of the migrations this module depends on?
 *
 * Only those are worth retrying with fewer columns. The same shapes the
 * migration runner treats as ignorable are treated as recoverable here.
 */
function entitlement_is_schema_error(\Throwable $e): bool
{
    $code = (string)$e->getCode();
    if (in_array($code, ['42S22', '42S02', '42S21'], true)) {  // column / table not found
        return true;
    }

    $message = $e->getMessage();
    foreach (['Unknown column', 'Unknown table', "doesn't exist", 'no such column'] as $needle) {
        if (stripos($message, $needle) !== false) {
            return true;
        }
    }

    return false;
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Intent wrappers. Call sites use these rather than entitlement_apply() directly
 * so the guards each flow needs are stated once, next to the reasoning.
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * Grant a plan from a Stripe-verified purchase — from the webhook, or from the
 * post-checkout safety net on purchase-success.php. $eventAt is the Stripe
 * timestamp, so a replayed or out-of-order event cannot re-grant.
 */
function entitlement_grant_from_stripe(int $userId, string $plan, array $opts = []): array
{
    $source    = (string)($opts['source'] ?? 'stripe-grant');
    $requested = $plan;
    $plan      = entitlement_normalize_plan($plan);

    // A purchase can only buy a paid plan. Without this, a checkout event whose
    // metadata says 'free' would be treated as a change to the free tier
    // instead of as nonsense, and would reset the account's subscription status
    // to 'active' on the way through.
    if ($plan === null || !is_paid_plan($plan)) {
        entitlement_log($source, 'refused: not a paid plan ("' . $requested . '")');
        return entitlement_result(false, 'not a paid plan', 0, $plan, $source);
    }

    return entitlement_apply([
        'user_id'         => $userId,
        'plan'            => $plan,
        'status'          => 'active',
        'customer_id'     => (string)($opts['customer_id'] ?? ''),
        'subscription_id' => (string)($opts['subscription_id'] ?? ''),
        'event_at'        => $opts['event_at'] ?? null,
        'store_customer'  => true,
        'started_at'      => true,
        'source'          => $source,
    ]);
}

/**
 * Revoke entitlement when a subscription ends (customer.subscription.deleted).
 * Matched by subscription id where we know it, so the deletion of an OLD
 * subscription cannot cancel a newer one.
 */
function entitlement_cancel_from_stripe(array $opts): array
{
    return entitlement_apply([
        'user_id'            => (int)($opts['user_id'] ?? 0),
        'customer_id'        => (string)($opts['customer_id'] ?? ''),
        'subscription_id'    => (string)($opts['subscription_id'] ?? ''),
        'plan'               => entitlement_default_plan(),
        'status'             => 'cancelled',
        'event_at'           => $opts['event_at'] ?? null,
        'allow_downgrade'    => true,   // cancellation IS a downgrade
        'match_subscription' => true,
        'source'             => (string)($opts['source'] ?? 'stripe-cancellation'),
    ]);
}

/**
 * Flag a failed payment. Deliberately status-only: the customer keeps their
 * plan while Stripe dunning runs, matching the previous behaviour.
 */
function entitlement_flag_past_due(array $opts): array
{
    return entitlement_apply([
        'user_id'            => (int)($opts['user_id'] ?? 0),
        'customer_id'        => (string)($opts['customer_id'] ?? ''),
        'subscription_id'    => (string)($opts['subscription_id'] ?? ''),
        'plan'               => null,
        'status'             => 'past_due',
        'event_at'           => $opts['event_at'] ?? null,
        'match_subscription' => true,
        'source'             => (string)($opts['source'] ?? 'stripe-past-due'),
    ]);
}

/**
 * An authoritative change made by an administrator, or by a local action that
 * has no Stripe event behind it (the test-mode subscribe button).
 *
 * No ordering guard: an operator pressing a button is not a Stripe event and
 * must take effect immediately, in either direction. It still records
 * `subscription_event_at = NOW()` so that a stale Stripe event arriving
 * afterwards — one that describes something that already happened — is refused
 * rather than overturning the decision.
 */
function entitlement_set_plan_local(int $userId, string $plan, array $opts = []): array
{
    $plan = entitlement_normalize_plan($plan);
    if ($plan === null) {
        return entitlement_result(false, 'unrecognised plan', 0, null, (string)($opts['source'] ?? 'local'));
    }

    $isFree = $plan === entitlement_default_plan();

    return entitlement_apply([
        'user_id'         => $userId,
        'plan'            => $plan,
        'status'          => $isFree ? 'none' : 'active',
        // A real instant, not a whole second: two local changes inside the same
        // second must both be able to land (an operator clearing a plan and
        // granting it back), and at second resolution the second one would lose
        // its own ordering guard and be refused.
        'event_at'        => microtime(true),
        'allow_downgrade' => true,
        'started_at'      => !$isFree,
        'source'          => (string)($opts['source'] ?? 'local'),
    ]);
}

/**
 * Change only the subscription status.
 *
 * Leaving the plan alone is the point: a cancellation keeps the paid features
 * until the period actually ends, which the Stripe `subscription.deleted` event
 * then finalises.
 *
 * `stamp_clock` decides whether this also moves the ordering clock:
 *
 *   • true — for the self-service cancel. The customer has made a decision at
 *     this moment, so a saved success URL replayed afterwards must lose to it.
 *     Without the stamp, a cancelled account has no record of *when* it was
 *     cancelled and a replayed URL could resurrect the subscription.
 *
 *   • false (default) — for moderation (ban/unban), which is not an entitlement
 *     decision at all. Stamping there would let an unban silently block a
 *     genuine cancellation that Stripe had already sent.
 */
function entitlement_set_status(int $userId, string $status, array $opts = []): array
{
    return entitlement_apply([
        'user_id'         => $userId,
        'plan'            => null,
        'status'          => $status,
        'event_at'        => !empty($opts['stamp_clock']) ? microtime(true) : null,
        'allow_downgrade' => true,
        'source'          => (string)($opts['source'] ?? 'local-status'),
    ]);
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Subscription sync — customer.subscription.created / .updated
 *
 * WHY THESE EVENTS MATTER
 * ───────────────────────
 * checkout.session.completed answers one question: "what did they just buy?".
 * It cannot answer it a second time, because a subscription is not only created
 * once — it changes. Stripe's own billing portal lets a customer switch from Pro
 * to Entrepreneur, switch back, mark the subscription to cancel at period end,
 * or (most importantly) recover from a failed payment. Every one of those sends
 * customer.subscription.updated and nothing else. Until this section existed the
 * application ignored the event entirely, so:
 *
 *   • A customer who moved up a tier in the portal kept paying the higher price
 *     while every gate in the app still read the old plan.
 *   • An account flagged 'past_due' by invoice.payment_failed stayed flagged for
 *     good. When Stripe's retry succeeded and the subscription went back to
 *     active, nothing in the app was watching, so the customer's own billing
 *     page went on saying past due after their card had been fixed.
 *
 * The two halves below are deliberately separate: the intent (what the object
 * means) is a pure function of the payload, so its whole status table can be
 * asserted without a database, and the apply half is the only part that writes.
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * The plan a Stripe price id stands for, or null when it is not one of ours.
 *
 * Read from the same two constants stripe-checkout.php prices its sessions with,
 * so this mapping cannot drift from what the customer was actually charged.
 * A placeholder value (YOUR_…_PRICE_ID) never matches anything.
 */
function entitlement_plan_from_price_id(string $priceId): ?string
{
    $priceId = trim($priceId);
    if ($priceId === '' || str_starts_with($priceId, 'YOUR_')) {
        return null;
    }

    $prices = [
        'pro'          => defined('STRIPE_PRO_PRICE_ID') ? (string)STRIPE_PRO_PRICE_ID : '',
        'entrepreneur' => defined('STRIPE_ENT_PRICE_ID') ? (string)STRIPE_ENT_PRICE_ID : '',
    ];

    foreach ($prices as $plan => $configured) {
        if ($configured !== '' && !str_starts_with($configured, 'YOUR_') && $configured === $priceId) {
            return $plan;
        }
    }

    return null;
}

/**
 * Which paid plan a subscription is on. The PRICE decides; metadata is only a
 * fallback.
 *
 * That order is the whole point, and getting it backwards reintroduces the bug
 * this section exists to fix. checkout stamps metadata[plan] from the plan that
 * was bought and carries it onto the subscription — but it is stamped ONCE, at
 * creation. When a customer changes plan in Stripe's billing portal, Stripe
 * swaps the PRICE and sends this event; it has no way to rewrite our metadata.
 * So a metadata-only lookup reports the plan they started on forever.
 *
 * The fallback still earns its place: a price we do not recognise (a legacy or
 * dashboard-created price) must not cancel anything, and metadata that names a
 * real plan is better evidence than nothing. Both paths are restricted to paid
 * plans, so this can never return 'free' and turn a status sync into a
 * downgrade.
 */
function entitlement_plan_from_subscription(array $subscription): ?string
{
    $priceId = (string)($subscription['items']['data'][0]['price']['id'] ?? '');
    $plan    = entitlement_plan_from_price_id($priceId);

    if ($plan === null) {
        $plan = entitlement_normalize_plan((string)($subscription['metadata']['plan'] ?? ''));
    }

    return ($plan !== null && is_paid_plan($plan)) ? $plan : null;
}

/** Shape one intent. Kept tiny so the table below reads as a table. */
function entitlement_intent(string $action, ?string $plan, ?string $status, string $reason): array
{
    return ['action' => $action, 'plan' => $plan, 'status' => $status, 'reason' => $reason];
}

/**
 * What a subscription object should do to entitlement. Pure: no database, no
 * Stripe, no clock. The whole status table is therefore directly assertable.
 *
 * Returns ['action' => 'grant'|'flag'|'revoke'|'ignore', 'plan' => ?string,
 *          'status' => ?string, 'reason' => string].
 *
 * The table, and why each row is what it is:
 *
 *   active / trialing     grant. This is also how a customer RECOVERS from a
 *                         failed payment, which is why it must be able to move
 *                         the status back to 'active' and not only forward.
 *   past_due              flag only. Stripe is still dunning, and the customer
 *                         keeps the features they are being asked to pay for —
 *                         the same stance invoice.payment_failed takes.
 *   unpaid                revoke. Dunning has finished without payment, so the
 *                         grace period is over. This cannot be left to the
 *                         deletion event: Stripe does not always cancel an
 *                         unpaid subscription, so no deletion may ever arrive.
 *   canceled              revoke, and the caller guards it on the subscription
 *   incomplete_expired    id — so this cannot take away a plan the customer
 *                         bought afterwards, the same protection the deletion
 *                         handler has.
 *   incomplete / paused   ignore. `incomplete` means the FIRST payment has not
 *                         succeeded, so granting here would hand out a paid plan
 *                         for a checkout that never completed.
 *   anything else         ignore, and say so. An unrecognised status is not
 *                         evidence of anything, and guessing costs money.
 */
function entitlement_subscription_intent(array $subscription): array
{
    $status = strtolower(trim((string)($subscription['status'] ?? '')));

    if ($status === '') {
        return entitlement_intent('ignore', null, null, 'the subscription carries no status');
    }

    if ($status === 'active' || $status === 'trialing') {
        $plan = entitlement_plan_from_subscription($subscription);
        if ($plan === null) {
            return entitlement_intent('ignore', null, null,
                'active, but neither its price nor its metadata names a plan we know');
        }
        return entitlement_intent('grant', $plan, 'active', $status . ' on ' . $plan);
    }

    if ($status === 'past_due') {
        return entitlement_intent('flag', null, 'past_due', 'dunning has started');
    }

    if ($status === 'unpaid') {
        return entitlement_intent('revoke', null, null, 'dunning finished without payment');
    }

    if ($status === 'canceled' || $status === 'incomplete_expired') {
        return entitlement_intent('revoke', null, null, 'the subscription has ended');
    }

    if ($status === 'incomplete' || $status === 'paused') {
        return entitlement_intent('ignore', null, null, $status . ' does not entitle anyone');
    }

    return entitlement_intent('ignore', null, null, 'unrecognised status ' . $status);
}

/**
 * Sync entitlement with a Stripe subscription object.
 *
 * The only path that can move a customer between the two paid tiers, and so the
 * only one that sets allow_downgrade: a customer who trades Entrepreneur for Pro
 * in Stripe's portal has made a legitimate decision that has to be applied. What
 * stops a STALE downgrade from landing is the event clock, not a blanket refusal
 * to ever go down a tier.
 *
 * Every branch matches on the subscription id, so an event describing a
 * subscription the account has since replaced cannot overwrite the current plan.
 * In the ordinary single-subscription case the stored id is either empty or the
 * same one, so the guard costs nothing.
 *
 * `match_subscription` (default true) is the one option a caller may turn off: a
 * checkout that has just verified with Stripe which subscription is live is
 * entitled to record it, even when our stored id says otherwise or says nothing.
 * Without that, a customer whose subscription we had lost track of would be
 * billed correctly and still read as free forever, because every event about the
 * new subscription would be refused by the same guard.
 */
function entitlement_sync_subscription(array $opts): array
{
    $source       = (string)($opts['source'] ?? 'stripe-subscription');
    $subscription = is_array($opts['subscription'] ?? null) ? $opts['subscription'] : [];
    $intent       = entitlement_subscription_intent($subscription);

    $common = [
        'user_id'            => (int)($opts['user_id'] ?? 0),
        'customer_id'        => (string)($opts['customer_id'] ?? ''),
        'subscription_id'    => (string)($opts['subscription_id'] ?? ''),
        'event_at'           => $opts['event_at'] ?? null,
        // The subscription-id guard is on by default and is what stops an event
        // describing a subscription the account has replaced from overwriting
        // the current plan. The checkout turns it off for exactly one case:
        // it has just asked Stripe which of this customer's subscriptions is
        // live, so it knows better than the stored id does — and its whole job
        // is to record the subscription the money is actually running on.
        'match_subscription' => (bool)($opts['match_subscription'] ?? true),
        'source'             => $source,
    ];

    switch ($intent['action']) {
        case 'grant':
            // Only a first-time grant restarts the subscription clock; a tier
            // change is the same subscription, so its start date must not move.
            // Asked by customer id as well as user id, because an event matched
            // on the customer arrives with no user id and would otherwise look
            // like a first grant every single time.
            $current = entitlement_current_plan($common['user_id'], $common['customer_id']);

            return entitlement_apply($common + [
                'plan'            => $intent['plan'],
                'status'          => $intent['status'],
                'allow_downgrade' => true,
                'store_customer'  => true,
                'started_at'      => !is_paid_plan($current),
            ]);

        case 'flag':
            // Status only — the plan is deliberately left alone, exactly as
            // invoice.payment_failed leaves it.
            return entitlement_apply($common + [
                'plan'   => null,
                'status' => $intent['status'],
            ]);

        case 'revoke':
            // Reuse the cancellation wrapper: same downgrade-to-free, same
            // subscription-id guard, so both routes to 'cancelled' agree.
            return entitlement_cancel_from_stripe($common);
    }

    entitlement_log($source, 'ignored: ' . $intent['reason']);
    return entitlement_result(false, 'ignored: ' . $intent['reason'], 0, null, $source);
}
