<?php
/**
 * admin/payments.php — the state of the money path, and the things that fix it.
 *
 * WHY THIS PAGE EXISTS
 * ────────────────────
 * "Is payment working?" used to be answerable only by reading config.php on the
 * server, then reading includes/whop.php, then guessing. That is a bad place for
 * the answer to live, because the failure mode is not a broken page: it is a
 * customer whose card was charged while every page in the product still says
 * "Free". So this page answers four questions, in order:
 *
 *   1. Can we sell?      whop_can_accept_payments() — without a webhook secret we
 *                        refuse the sale rather than take money we cannot apply.
 *   2. Can we identify?  the API key, which is what attaches the buyer's account
 *                        to the payment instead of guessing from an email.
 *   3. Can we verify?    a signed sample run through the real verifier, parser and
 *                        intent table — the whole decision path minus the network.
 *   4. Did it land?      the whop_events ledger, and the accounts that have a Whop
 *                        membership but are still on the free plan.
 *
 * WHAT IT WILL NOT DO
 * ───────────────────
 * It never prints a secret. Not the key, not the webhook secret, not a prefix of
 * either: whop_config_report() answers with a boolean and a length, and the only
 * thing that leaves this page is whether a value is set. The `Test` button creates
 * a real checkout configuration — that is the call the customer's button makes, and
 * testing a stand-in for it would prove nothing — but a checkout configuration
 * charges nobody; nothing is authorised until a card is typed on Whop's own page.
 *
 * @see includes/whop.php    the boundary this page reports on
 * @see WHOP_SETUP.md        the same steps written out for a first install
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../userdb.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/whop.php';

require_admin();
$admin = $GLOBALS['admin_user'];

$report  = whop_config_report();
$notice  = '';   // a sentence about something that just worked
$problem = '';   // a sentence about something that just did not
$probe   = [];   // the rendered result of the signature-path check

/* ═══════════════════════════════════════════════════════════════════════════
 * ACTIONS
 * ═══════════════════════════════════════════════════════════════════════════ */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {        $action = (string)$_POST['action'];

    // Every action on this page is an operator touching the money path, so every
    // one of them leaves a line naming who did it. _admin_log() is the same
    // append-only record the login and session checks use, and it already stamps
    // the ip and the account.
    _admin_log('INFO', 'payments: ' . $action . ' requested');

    // One token slot PER ACTION, because admin_csrf_token() replaces the token it
    // holds for a form key: three forms sharing one key would mean only the last
    // one rendered carries a live token, and the other two would refuse with "that
    // form expired" while looking perfectly fine. (Found exactly that way.)
    //
    // The reconcile form goes one step further and keys on the account too, because
    // it is rendered once per row: one shared slot would leave every row but the
    // last holding a dead token. The key here and the key passed to
    // admin_csrf_token() when the row is drawn must match exactly — a mismatch
    // reads to the operator as "the form expired", which is why it is computed here
    // and only here.
    $csrfKey = 'payments.' . $action . ($action === 'reconcile' ? '.' . (int)($_POST['user_id'] ?? 0) : '');

    if (!admin_csrf_verify($csrfKey, $_POST['csrf_token'] ?? null)) {
        _admin_log('WARN', 'payments: ' . $action . ' refused — bad or expired CSRF token');
        $problem = 'That form expired. Refresh the page and try again — nothing was changed.';
    } elseif ($action === 'test_connection') {
        /* THE CONNECTION TEST IS THE REAL CALL.
         *
         * This is exactly what whop-checkout.php does when a customer clicks
         * Subscribe: POST /api/v1/checkout_configurations with our account id, our
         * plan id, and the buyer's account in the metadata. Testing a read-only
         * endpoint instead would prove the key is valid and nothing else — not the
         * plan id, not the account id, not the shape of the payload, which are the
         * three things that actually break.
         *
         * It costs nothing and charges nobody: a checkout configuration is a
         * session the customer has not paid into. */
        $returnUrl = rtrim((string)APP_BASE_URL, '/')
                   . (defined('WHOP_RETURN_PATH') ? (string)WHOP_RETURN_PATH : '/purchase-success.php');

        $created = whop_create_checkout((int)$admin['id'], 'pro', $returnUrl);

        if ($created['ok'] && $created['url'] !== '') {
            $notice = 'Whop accepted the request. The checkout it returned is <code class="text-emerald-300">'
                . htmlspecialchars($created['session']) . '</code> — nothing was charged, and nothing is charged '
                . 'until a card is typed on Whop\'s page. This is the same call the Subscribe button makes, so '
                . 'the API key, the account id and the Pro plan id are all three working.';
            whop_log('admin', 'payments: a connection test from account ' . (int)$admin['id']
                . ' created checkout payload ' . $created['session']);
            _admin_log('INFO', 'payments: connection test OK, checkout payload ' . $created['session']);
        } else {
            $problem = 'Whop refused the request: ' . htmlspecialchars($created['error'])
                . ' — this is the same error a customer would have seen, so nothing is being sold with this '
                . 'configuration. Check the API key (it starts with <code>company_</code>), the account id '
                . '(<code>biz_</code>) and the Pro plan id.';
            whop_log('admin', 'payments: a connection test failed: ' . $created['error']);
            _admin_log('ERROR', 'payments: connection test FAILED — ' . $created['error']);
        }
    } elseif ($action === 'check_signature') {
        /* THE SIGNATURE PATH, PROVEN WITHOUT THE NETWORK.
         *
         * A sample body is signed here with the configured secret, in the same
         * Standard-Webhooks construction Whop uses, and fed through the real
         * verifier, the real parser and the real intent table. What that proves is
         * everything on our side: the secret is present, the key derivation agrees
         * with the signature, the envelope parses, and an event for a plan we do not
         * sell is IGNORED rather than guessed at.
         *
         * What it cannot prove is delivery — whether Whop's servers can reach the
         * endpoint. Nothing running inside the application can prove that, and
         * pretending otherwise would be the worst kind of green tick. So the page
         * says so, and the real proof is the first row in the ledger below. */
        $body = json_encode([
            'id'          => 'msg_probe_' . bin2hex(random_bytes(6)),
            'type'        => 'payment.succeeded',
            'api_version' => 'v1',
            'timestamp'   => gmdate('Y-m-d\TH:i:s.000\Z'),
            'data'        => [
                // A plan id nobody sells. The interesting answer here is "ignored",
                // not "applied": a probe must not be able to grant a plan.
                'id'       => 'pay_probe',
                'status'   => 'paid',
                'plan'     => ['id' => 'plan_probe_not_sold_here'],
                'member'   => ['id' => 'mber_probe'],
                'metadata' => ['utiligo_user_id' => (string)(int)$admin['id']],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $probeId  = 'msg_probe_' . bin2hex(random_bytes(6));
        $probeTs  = time();
        $keys     = whop_signature_keys(whop_webhook_secret());
        $key      = $keys[0] ?? '';
        $sig      = $key === '' ? '' : base64_encode(hash_hmac('sha256', $probeId . '.' . $probeTs . '.' . $body, $key, true));

        $verified = whop_verify_webhook($body, [
            'id'        => $probeId,
            'timestamp' => (string)$probeTs,
            'signature' => 'v1,' . $sig,
        ]);

        $parsed = $verified['ok'] ? whop_parse_event($body) : ['ok' => false, 'reason' => 'not parsed: the signature failed'];
        $intent = $parsed['ok'] ? whop_intent($parsed) : ['action' => '-', 'plan' => null, 'reason' => 'not decided: the body did not parse'];

        $probe = [
            'signed_with' => count($keys) . ' key derivation(s) available',
            'verified'    => $verified['ok'],
            'reason'      => (string)$verified['reason'],
            'key_index'   => (int)$verified['key_index'],
            'action'      => (string)$intent['action'],
            'intent'      => (string)$intent['reason'],
        ];

        _admin_log('INFO', 'payments: signature sample ' . ($verified['ok'] ? 'verified' : 'REFUSED')
            . ' — ' . $verified['reason'] . '; decision ' . $intent['action'] . ' (' . $intent['reason'] . ')');

        if (!$verified['ok']) {
            $problem = 'The signature path is broken and this is why a real payment would never be applied: '
                . htmlspecialchars($verified['reason']);
        }
    } elseif ($action === 'reconcile') {
        /* RECONCILIATION: A CUSTOMER WHO PAID AND IS STILL ON FREE.
         *
         * The list below is built from our own columns, and every row on it is a
         * person whose card is being charged while the product shows them the free
         * tier. The grant goes through entitlement_grant_from_whop() — the same
         * writer the webhook uses, with the account's own member and membership ids
         * — so the plan, the subscription status and the ordering clock move exactly
         * as they would have from a delivered event. It is not a direct UPDATE:
         * bypassing the entitlement layer here would leave the clock stale and let
         * the next real event overwrite the fix. */
        $targetId = (int)($_POST['user_id'] ?? 0);
        $plan     = strtolower(trim((string)($_POST['plan'] ?? '')));

        if ($targetId <= 0 || !is_paid_plan($plan)) {
            $problem = 'Pick an account and a plan to grant.';
        } else {
            $state = entitlement_whop_state($targetId);
            $result = entitlement_grant_from_whop($targetId, $plan, [
                'member_id'     => (string)$state['member_id'],
                'membership_id' => (string)$state['membership_id'],
                'source'        => 'admin.payments.reconcile',
            ]);

            if (!empty($result['applied'])) {
                $notice = 'Account ' . $targetId . ' is now on <strong>' . htmlspecialchars($plan)
                    . '</strong> — recorded against the Whop membership already on file ('
                    . htmlspecialchars((string)$state['membership_id'] ?: 'none') . ').';
                whop_log('admin', 'payments: account ' . (int)$admin['id'] . ' granted ' . $plan
                    . ' to account ' . $targetId . ' by hand');
                _admin_log('INFO', 'payments: granted ' . $plan . ' to account ' . $targetId . ' by hand');
            } else {
                $problem = 'Nothing changed: ' . htmlspecialchars((string)($result['reason'] ?? 'unknown reason'));
            }
        }
    } elseif ($action === 'send_test_event') {
        /* A REAL DELIVERY, SIGNED THE SAME WAY WHOP SIGNS ONE.
         *
         * Whop's dashboard has a "send test event" button and this is not a
         * replacement for it — it is the check that answers "is the endpoint
         * reachable and does it accept a delivery", which is the one question the
         * offline probe above cannot answer. The body is an event for a plan we do
         * not sell, so the worst a mistake here can do is write one 'ignored' row. */
        $body = json_encode([
            'id'          => 'msg_selftest_' . bin2hex(random_bytes(6)),
            'type'        => 'payment.succeeded',
            'api_version' => 'v1',
            'timestamp'   => gmdate('Y-m-d\TH:i:s.000\Z'),
            'data'        => [
                'id'       => 'pay_selftest',
                'status'   => 'paid',
                'plan'     => ['id' => 'plan_selftest_not_sold'],
                'member'   => ['id' => 'mber_selftest'],
                'metadata' => ['utiligo_user_id' => (string)(int)$admin['id']],
            ],
        ], JSON_UNESCAPED_SLASHES);

        $id  = 'msg_selftest_' . bin2hex(random_bytes(6));
        $ts  = time();
        $keys = whop_signature_keys(whop_webhook_secret());
        $key = $keys[0] ?? '';

        $http = 0;
        $answer = '';
        if ($key === '') {
            $problem = 'There is no webhook secret to sign with, so there is nothing to send.';
        } elseif (!function_exists('curl_init')) {
            $problem = 'The curl extension is not available, so the delivery cannot be sent.';
        } else {
            $ch = curl_init(whop_webhook_url());
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'webhook-id: ' . $id,
                    'webhook-timestamp: ' . (string)$ts,
                    'webhook-signature: v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $ts . '.' . $body, $key, true)),
                ],
            ]);
            $raw  = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = (string)curl_error($ch);
            curl_close($ch);
            $answer = $raw === false ? $err : trim((string)$raw);

            _admin_log($http === 200 ? 'INFO' : 'ERROR', 'payments: self-test delivery answered HTTP ' . $http
                . ($answer !== '' ? ' — ' . substr($answer, 0, 200) : ''));

            if ($http === 200) {
                $notice = 'The endpoint answered <strong>200</strong> to a correctly signed delivery, and the row '
                    . 'below is the proof it went through the whole path. That is the last piece of the setup working.';
            } else {
                $problem = 'The endpoint answered <strong>' . $http . '</strong>'
                    . ($answer !== '' ? ' — ' . htmlspecialchars(substr($answer, 0, 300)) : '')
                    . ($http === 0
                        ? '. A timeout is not proof the delivery failed: some hosts serve one request at a time, so a
                           request from the server to itself can time out while still being handled. The ledger above is
                           the answer — a row written with the time of this click means the endpoint took it.'
                        : '')
                    . ($http === 401 ? '. A 401 means the secret this deployment signs with is not the secret the '
                        . 'endpoint verifies with, or the endpoint has none.' : '')
                    . ($http === 403 || $http === 404 ? '. A 403 or 404 here usually means the request never reached '
                        . 'PHP — a WAF, a blocking page, or a wrong path.' : '');
            }
        }
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * THE LEDGER, AND THE ACCOUNTS THAT NEED A HUMAN
 * ═══════════════════════════════════════════════════════════════════════════ */

$ledger = [];
$counts = ['applied' => 0, 'ignored' => 0, 'failed' => 0, 'received' => 0];
$ledgerError = '';

try {
    $stmt = get_user_db()->query(
        'SELECT id, webhook_id, event_type, event_at, user_id, plan, status, detail, created_at, applied_at '
        . 'FROM whop_events ORDER BY id DESC LIMIT 25'
    );
    $ledger = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach (get_user_db()->query('SELECT status, COUNT(*) AS n FROM whop_events GROUP BY status') as $row) {
        $status = strtolower((string)$row['status']);
        if (isset($counts[$status])) {
            $counts[$status] = (int)$row['n'];
        }
    }
} catch (\Throwable $e) {
    // The table arrives with migration 030, which runs automatically on the first
    // request after a deploy. Before it exists this page still has to render: an
    // operator looking at payment settings should never see a stack trace instead.
    $ledgerError = entitlement_is_schema_error($e)
        ? 'The whop_events table does not exist yet — migration 030 has not run on this database (it runs itself on the next page load).'
        : $e->getMessage();
}

/** Accounts with a Whop membership on file but no paid plan: someone is being charged for nothing. */
$stranded = [];
try {
    $stmt = get_user_db()->query(
        "SELECT id, email, full_name, plan, subscription_status, whop_member_id, whop_membership_id "
        . "FROM utiligo_users WHERE whop_member_id IS NOT NULL AND whop_member_id <> '' AND plan = 'free' "
        . "ORDER BY id DESC LIMIT 25"
    );
    $stranded = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    // Older schema: the columns are added by the same migration as the table above.
    $stranded = [];
}

$pageTitle = 'Payments — Admin — Utiligo';
$adminPage = 'payments';
require_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="mb-8 flex items-start justify-between flex-wrap gap-4">
  <div>
    <p class="text-slate-400 text-sm mb-0.5">Whop — the merchant of record</p>
    <h1 class="text-3xl font-bold tracking-tight">Payments</h1>
    <p class="text-slate-500 text-xs mt-1">
      What the money path is doing right now, and what is standing in its way.
    </p>
  </div>
  <div class="text-right space-y-1">
    <?php if ($report['can_accept_payments']): ?>
      <span class="inline-flex items-center gap-2 text-xs font-semibold bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 px-3 py-1.5 rounded-full">
        <i class="fa-solid fa-circle-check"></i> Selling is on
      </span>
    <?php else: ?>
      <span class="inline-flex items-center gap-2 text-xs font-semibold bg-red-500/10 border border-red-500/25 text-red-300 px-3 py-1.5 rounded-full">
        <i class="fa-solid fa-hand"></i> Selling is refused
      </span>
    <?php endif; ?>
    <p class="text-[11px] text-slate-600">
      <?php if ($report['can_accept_payments']): ?>
        A payment can be verified and applied.
      <?php else: ?>
        A payment here could not be applied, so checkout refuses it.
      <?php endif; ?>
    </p>
  </div>
</div>

<?php if ($notice): ?>
<div class="flex items-start gap-3 bg-emerald-500/[.07] border border-emerald-500/20 text-emerald-300 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-circle-check mt-0.5 shrink-0"></i><div><?= $notice ?></div>
</div>
<?php endif; ?>
<?php if ($problem): ?>
<div class="flex items-start gap-3 bg-red-500/[.07] border border-red-400/20 text-red-300 rounded-2xl px-5 py-4 mb-6 text-sm">
  <i class="fa-solid fa-triangle-exclamation mt-0.5 shrink-0"></i><div><?= $problem ?></div>
</div>
<?php endif; ?>

<!-- ══ The four facts ══════════════════════════════════════════════════════ -->
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
  <?php
  $facts = [
      [
          'Can we sell?',
          $report['can_accept_payments'],
          'can_accept_payments',
          $report['can_accept_payments']
              ? 'Checkout is open on the billing page.'
              : 'Checkout refuses and says why, so nobody is charged for a plan we cannot grant.',
      ],
      [
          'Can we verify?',
          $report['can_verify'],
          'webhook secret',
          $report['can_verify']
              ? 'Signed deliveries are accepted; everything else is refused before it is read.'
              : 'Every delivery is refused with a 401 — this is the one that must be fixed.',
      ],
      [
          'Can we identify?',
          $report['can_create_checkout'],
          'API key + account id',
          $report['can_create_checkout']
              ? 'The buyer\'s account id travels to Whop in the payment metadata.'
              : 'Purchases fall back to the shareable link and are matched by the payer\'s verified email.',
      ],
      [
          'Do we have plans?',
          $report['plans'] !== [],
          'plan ids',
          $report['plans'] !== []
              ? implode(', ', array_keys($report['plans'])) . ' mapped to Whop plan ids.'
              : 'No plan id is configured, so there is nothing to sell.',
      ],
  ];
  foreach ($facts as [$label, $ok, $hint, $note]): ?>
  <div class="glass rounded-2xl p-5 border <?= $ok ? 'border-white/5' : 'border-red-500/25' ?>">
    <div class="flex items-center gap-2 mb-3">
      <span class="w-6 h-6 rounded-lg flex items-center justify-center <?= $ok ? 'bg-emerald-500/12 text-emerald-400' : 'bg-red-500/12 text-red-400' ?>">
        <i class="fa-solid <?= $ok ? 'fa-check' : 'fa-xmark' ?> text-[11px]"></i>
      </span>
      <p class="text-sm font-semibold text-white"><?= htmlspecialchars($label) ?></p>
    </div>
    <p class="text-[11px] text-slate-500 mb-1 font-mono"><?= htmlspecialchars($hint) ?></p>
    <p class="text-xs text-slate-400 leading-relaxed"><?= htmlspecialchars($note) ?></p>
  </div>
  <?php endforeach; ?>
</div>

<?php
/* WHERE A PROBLEM WOULD BE REPORTED, SHOWN NEXT TO THE THING THAT CAN BREAK.
   Every failure this page lists is silent from the customer's side — they just
   paid, so they assume the plan is coming — which makes the alert address part of
   the payment configuration rather than a general setting. An empty ADMIN_EMAIL
   means the alert exists only as a log line, and that is worth saying out loud
   before anyone relies on it. */
$_alertTo = defined('ADMIN_EMAIL') ? trim((string)ADMIN_EMAIL) : '';
// An address is only half of it: with no mail API key, send_email() falls through
// to PHP's mail(), which is usually disabled on shared hosting — so the alert would
// be written and never delivered, which looks exactly like nothing being wrong.
$_alertMailer = defined('BREVO_API_KEY') && BREVO_API_KEY !== '' && BREVO_API_KEY !== 'YOUR_BREVO_API_KEY';
$_alertWorks  = $_alertTo !== '' && $_alertMailer;
?>
<div class="flex items-start gap-3 rounded-2xl px-5 py-3.5 mb-8 text-sm border <?= $_alertWorks ? 'bg-white/[.02] border-white/5' : 'bg-amber-500/[.06] border-amber-500/20' ?>">
  <i class="fa-solid <?= $_alertWorks ? 'fa-bell text-slate-400' : 'fa-bell-slash text-amber-400' ?> mt-0.5 shrink-0"></i>
  <div class="text-xs leading-relaxed <?= $_alertWorks ? 'text-slate-400' : 'text-amber-200/80' ?>">
    <?php if ($_alertWorks): ?>
      <span class="font-semibold text-slate-300">Alerts are emailed to <?= htmlspecialchars($_alertTo) ?>.</span>
      A payment that could not be attached to an account, a payment for a plan we do not sell, and a delivery we
      cannot verify each send one email — once per delivery, and at most
      <?= (int)(defined('WHOP_ALERT_MAX_PER_HOUR') ? WHOP_ALERT_MAX_PER_HOUR : 3) ?> of a kind per hour, with
      the line in <code>storage/php_errors.log</code> either way.
    <?php elseif ($_alertTo !== ''): ?>
      <span class="font-semibold text-amber-300">Nothing can be delivered.</span>
      <code>ADMIN_EMAIL</code> is set to <?= htmlspecialchars($_alertTo) ?>, but there is no mail API key, so
      <code>send_email()</code> falls back to PHP's own mail function, which shared hosting usually disables —
      the alert would be written to <code>storage/php_errors.log</code> and never arrive. Paste a Brevo key under
      <a href="/admin/settings.php#brevo" class="underline">Settings → Brevo</a>.
    <?php else: ?>
      <span class="font-semibold text-amber-300">Nothing is emailed.</span>
      <code>ADMIN_EMAIL</code> is empty, so a payment that could not be applied would reach
      <code>storage/php_errors.log</code> and nobody else. Set it under
      <a href="/admin/settings.php#alerts" class="underline">Settings → Alerts</a>.
    <?php endif; ?>
  </div>
</div>

<?php if ($report['blocking']): ?>
<div class="bg-amber-500/[.06] border border-amber-500/20 rounded-2xl p-6 mb-8">
  <div class="flex items-center gap-2 mb-3">
    <i class="fa-solid fa-list-check text-amber-400 text-sm"></i>
    <h2 class="font-semibold text-amber-300 text-sm">What is not finished</h2>
  </div>
  <ul class="space-y-2.5">
    <?php foreach ($report['blocking'] as $why): ?>
      <li class="flex items-start gap-2.5 text-xs text-amber-100/70 leading-relaxed">
        <i class="fa-solid fa-circle text-[4px] mt-1.5 text-amber-400/60 shrink-0"></i><?= htmlspecialchars($why) ?>
      </li>
    <?php endforeach; ?>
  </ul>
  <a href="/admin/settings.php#payments-whop" class="inline-flex items-center gap-2 bg-amber-500/15 hover:bg-amber-500/25 border border-amber-500/30 text-amber-200 px-4 py-2 rounded-xl font-semibold text-xs mt-4 transition">
    <i class="fa-solid fa-key"></i> Set the values in Settings
  </a>
</div>
<?php endif; ?>

<!-- ══ The configuration, value by value ═══════════════════════════════════ -->
<div class="glass rounded-2xl border border-white/5 overflow-hidden mb-8">
  <div class="px-6 py-4 border-b border-white/5 flex items-center gap-2">
    <i class="fa-solid fa-sliders text-slate-400 text-sm"></i>
    <h3 class="font-semibold text-sm">Configuration</h3>
    <span class="ml-auto text-[11px] text-slate-600">A secret is reported as set or missing, never printed</span>
  </div>
  <div class="divide-y divide-white/5">
    <?php
    $rows = [
        ['WHOP_API_KEY',        $report['api_key'],        'Creates the checkout and stamps the buyer\'s account id into it.'],
        ['WHOP_WEBHOOK_SECRET', $report['webhook_secret'], 'The only thing that proves a delivery is Whop\'s. Checkout refuses without it.'],
        ['WHOP_ACCOUNT_ID',     $report['account_id'],     'The business that owns the plans.'],
    ];
    foreach ($rows as [$name, $value, $why]): ?>
    <div class="px-6 py-3.5 flex items-center gap-4 flex-wrap">
      <div class="min-w-[190px]">
        <code class="text-xs font-mono <?= $value['set'] ? 'text-slate-200' : 'text-amber-300' ?>"><?= $name ?></code>
      </div>
      <div class="min-w-[150px] font-mono text-xs <?= $value['set'] ? 'text-slate-400' : 'text-amber-400' ?>">
        <?php if (!$value['set']): ?>
          not set
        <?php elseif (isset($value['masked'])): ?>
          <?= htmlspecialchars((string)$value['masked']) ?>
        <?php else: ?>
          set <span class="text-slate-600">— <?= (int)($value['length'] ?? 0) ?> characters</span>
        <?php endif; ?>
      </div>
      <p class="text-[11px] text-slate-500 flex-1"><?= htmlspecialchars($why) ?></p>
    </div>
    <?php endforeach; ?>
    <?php foreach ($report['plans'] as $plan => $id): ?>
    <div class="px-6 py-3.5 flex items-center gap-4 flex-wrap">
      <div class="min-w-[190px]"><code class="text-xs font-mono text-slate-200">WHOP_<?= strtoupper(substr($plan, 0, 3)) ?>_PLAN_ID</code></div>
      <div class="min-w-[150px] font-mono text-xs text-slate-400"><?= htmlspecialchars($id) ?></div>
      <p class="text-[11px] text-slate-500 flex-1"><?= htmlspecialchars(ucfirst($plan)) ?> is sold as this Whop plan — the price charged is the price on that plan in Whop's dashboard.</p>
    </div>
    <?php endforeach; ?>
    <div class="px-6 py-3.5 flex items-center gap-4 flex-wrap">
      <div class="min-w-[190px]"><code class="text-xs font-mono text-slate-200">WHOP_API_BASE</code></div>
      <div class="min-w-[150px] font-mono text-xs text-slate-400"><?= htmlspecialchars($report['api_base']) ?></div>
      <p class="text-[11px] text-slate-500 flex-1">Where the API calls go. Anything other than api.whop.com means this install is pointed at a stub.</p>
    </div>
  </div>
</div>

<!-- ══ Two checks, and what each one proves ════════════════════════════════ -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-8">

  <div class="glass rounded-2xl border border-white/5 p-6 flex flex-col">
    <div class="flex items-center gap-2 mb-2">
      <i class="fa-solid fa-plug-circle-check text-slate-400 text-sm"></i>
      <h3 class="font-semibold text-sm">Try the checkout call</h3>
    </div>
    <p class="text-xs text-slate-400 leading-relaxed flex-1">
      Sends the exact request the Subscribe button sends — the same endpoint, the same account id,
      the same plan id, with this admin account in the metadata — and reports what Whop answers.
      Nothing can be charged: a checkout session is just a page.
    </p>
    <form method="POST" action="/admin/payments.php" class="mt-4">
      <input type="hidden" name="csrf_token" value="<?= admin_csrf_token('payments.test_connection') ?>">
      <input type="hidden" name="action" value="test_connection">
      <button type="submit" class="inline-flex items-center gap-2 bg-white/[.07] hover:bg-white/[.12] border border-white/10 text-white px-4 py-2 rounded-xl font-semibold text-xs transition">
        <i class="fa-solid fa-credit-card"></i> Create a test checkout
      </button>
    </form>
  </div>

  <div class="glass rounded-2xl border border-white/5 p-6 flex flex-col">
    <div class="flex items-center gap-2 mb-2">
      <i class="fa-solid fa-fingerprint text-slate-400 text-sm"></i>
      <h3 class="font-semibold text-sm">Check the signature path</h3>
    </div>
    <p class="text-xs text-slate-400 leading-relaxed flex-1">
      Signs a sample delivery with the configured secret and runs it through the real verifier,
      parser and decision table. It proves everything on this side of the wire; it cannot prove
      Whop can reach the endpoint — only a real delivery can, and the ledger below is where one shows up.
    </p>
    <form method="POST" action="/admin/payments.php" class="mt-4">
      <input type="hidden" name="csrf_token" value="<?= admin_csrf_token('payments.check_signature') ?>">
      <input type="hidden" name="action" value="check_signature">
      <button type="submit" class="inline-flex items-center gap-2 bg-white/[.07] hover:bg-white/[.12] border border-white/10 text-white px-4 py-2 rounded-xl font-semibold text-xs transition">
        <i class="fa-solid fa-shield-halved"></i> Sign and verify a sample
      </button>
    </form>
    <form method="POST" action="/admin/payments.php" class="mt-2">
      <input type="hidden" name="csrf_token" value="<?= admin_csrf_token('payments.send_test_event') ?>">
      <input type="hidden" name="action" value="send_test_event">
      <button type="submit" class="inline-flex items-center gap-2 bg-white/[.07] hover:bg-white/[.12] border border-white/10 text-white px-4 py-2 rounded-xl font-semibold text-xs transition">
        <i class="fa-solid fa-paper-plane"></i> Deliver a signed test event to our own endpoint
      </button>
    </form>
  </div>
</div>

<?php if ($probe): ?>
<div class="glass rounded-2xl border <?= $probe['verified'] ? 'border-emerald-500/20' : 'border-red-500/25' ?> p-6 mb-8">
  <h3 class="font-semibold text-sm mb-4">The sample, step by step</h3>
  <div class="space-y-3 text-xs">
    <div class="flex items-start gap-3">
      <i class="fa-solid fa-key text-slate-500 mt-0.5 w-4"></i>
      <p class="text-slate-400">Signing key: <?= htmlspecialchars((string)$probe['signed_with']) ?> from the configured secret.</p>
    </div>
    <div class="flex items-start gap-3">
      <i class="fa-solid <?= $probe['verified'] ? 'fa-check text-emerald-400' : 'fa-xmark text-red-400' ?> mt-0.5 w-4"></i>
      <p class="<?= $probe['verified'] ? 'text-emerald-300' : 'text-red-300' ?>">
        Signature: <?= $probe['verified'] ? 'verified' : 'refused' ?>
        <span class="text-slate-500">— <?= htmlspecialchars((string)$probe['reason']) ?></span>
      </p>
    </div>
    <div class="flex items-start gap-3">
      <i class="fa-solid fa-diagram-project text-slate-500 mt-0.5 w-4"></i>
      <p class="text-slate-400">
        Decision: <code class="<?= $probe['action'] === 'ignore' ? 'text-slate-200' : 'text-amber-300' ?>"><?= htmlspecialchars((string)$probe['action']) ?></code>
        <span class="text-slate-500">— <?= htmlspecialchars((string)$probe['intent']) ?></span>
      </p>
    </div>
  </div>
  <p class="text-[11px] text-slate-600 mt-4 pt-3 border-t border-white/5">
    The sample is deliberately a payment for a plan we do not sell, so the expected answer is
    <strong>verified</strong> followed by <strong>ignore</strong>. Anything that granted a plan here
    would be a bug worth stopping for.
  </p>
</div>
<?php endif; ?>

<!-- ══ What to register in Whop ════════════════════════════════════════════ -->
<div class="glass rounded-2xl border border-white/5 overflow-hidden mb-8">
  <div class="px-6 py-4 border-b border-white/5 flex items-center gap-2">
    <i class="fa-solid fa-webhook text-slate-400 text-sm"></i>
    <h3 class="font-semibold text-sm">The webhook, as Whop needs it</h3>
    <span class="ml-auto text-[11px] text-slate-600">Whop dashboard → Developer → Webhooks</span>
  </div>
  <div class="px-6 py-5 space-y-4 text-xs">
    <div>
      <p class="text-slate-500 mb-1.5">Endpoint URL</p>
      <div class="flex items-center gap-2 flex-wrap">
        <code id="whopEndpoint" class="bg-white/[.04] border border-white/10 rounded-lg px-3 py-2 font-mono text-slate-200 select-all"><?= htmlspecialchars(whop_webhook_url()) ?></code>
        <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('whopEndpoint').textContent).then(()=>{this.innerHTML='<i class=\'fa-solid fa-check\'></i> Copied';})"
                class="inline-flex items-center gap-1.5 bg-white/[.07] hover:bg-white/[.12] border border-white/10 px-3 py-2 rounded-lg font-semibold text-slate-300 transition">
          <i class="fa-regular fa-copy"></i> Copy
        </button>
      </div>
    </div>
    <div>
      <p class="text-slate-500 mb-1.5">Events to subscribe to</p>
      <div class="flex flex-wrap gap-2">
        <code class="bg-white/[.04] border border-white/10 rounded-lg px-2.5 py-1.5 font-mono text-slate-200">payment.succeeded</code>
        <code class="bg-white/[.04] border border-white/10 rounded-lg px-2.5 py-1.5 font-mono text-slate-200">membership.deactivated</code>
      </div>
      <p class="text-[11px] text-slate-600 mt-2">
        Not <code>payment.created</code>, not <code>membership.activated</code>: the two above are the ones the
        handler acts on, and an event type it did not subscribe to is ignored and logged rather than guessed at.
      </p>
    </div>
    <div>
      <p class="text-slate-500 mb-1.5">API version</p>
      <code class="bg-white/[.04] border border-white/10 rounded-lg px-2.5 py-1.5 font-mono text-slate-200">v1</code>
      <p class="text-[11px] text-slate-600 mt-2">
        v1 is the envelope that is signed with the Standard-Webhooks scheme. A legacy v2 or v5 webhook is
        signed differently, and the handler refuses it rather than misreading a signature it cannot trust.
      </p>
    </div>
    <div>
      <p class="text-slate-500 mb-1.5">Signing secret</p>
      <p class="text-slate-400 leading-relaxed">
        Whop shows the secret once, when the webhook is created, and signs nothing without it. Paste it into
        <code class="text-slate-200">WHOP_WEBHOOK_SECRET</code> in
        <a href="/admin/settings.php" class="text-slate-200 underline">Settings → Payments (Whop)</a> — that writes
        <code class="text-slate-200">storage/config_overrides.php</code>, which is loaded before every other
        setting, so it does not need a redeploy and it survives one (config.php itself is excluded from the
        FTP deploy, which is why a value pasted there by hand is the value that goes missing).
      </p>
    </div>
  </div>
</div>

<!-- ══ Deliveries ═════════════════════════════════════════════════════════ -->
<div class="glass rounded-2xl border border-white/5 overflow-hidden mb-8">
  <div class="px-6 py-4 border-b border-white/5 flex items-center gap-2 flex-wrap">
    <i class="fa-solid fa-clock-rotate-left text-slate-400 text-sm"></i>
    <h3 class="font-semibold text-sm">Deliveries</h3>
    <div class="ml-auto flex items-center gap-2 text-[11px]">
      <span class="text-emerald-400"><?= (int)$counts['applied'] ?> applied</span>
      <span class="text-slate-600">·</span>
      <span class="text-slate-400"><?= (int)$counts['ignored'] ?> ignored</span>
      <span class="text-slate-600">·</span>
      <span class="<?= $counts['failed'] > 0 ? 'text-red-400' : 'text-slate-500' ?>"><?= (int)$counts['failed'] ?> failed</span>
      <span class="text-slate-600">·</span>
      <span class="<?= $counts['received'] > 0 ? 'text-amber-400' : 'text-slate-500' ?>"><?= (int)$counts['received'] ?> in flight</span>
    </div>
  </div>

  <?php if ($ledgerError): ?>
  <div class="px-6 py-6 text-xs text-slate-400"><i class="fa-solid fa-circle-info mr-2 text-slate-500"></i><?= htmlspecialchars($ledgerError) ?></div>
  <?php elseif (!$ledger): ?>
  <div class="px-6 py-10 text-center">
    <i class="fa-solid fa-inbox text-2xl text-slate-700 mb-3 block"></i>
    <p class="text-sm text-slate-400">Nothing has been delivered yet.</p>
    <p class="text-xs text-slate-600 mt-1">
      The first row here will be the first real webhook. Until then, "it works" is a claim rather than a fact —
      send a test delivery from Whop's dashboard and watch it appear.
    </p>
  </div>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="w-full text-xs">
      <thead><tr class="border-b border-white/5 text-slate-500 uppercase text-[10px]">
        <th class="px-6 py-3 text-left">When</th>
        <th class="px-3 py-3 text-left">Event</th>
        <th class="px-3 py-3 text-left">Account</th>
        <th class="px-3 py-3 text-left">Plan</th>
        <th class="px-3 py-3 text-left">State</th>
        <th class="px-6 py-3 text-left">What the handler decided</th>
      </tr></thead>
      <tbody class="divide-y divide-white/5">
      <?php foreach ($ledger as $row):
        $status = strtolower((string)$row['status']);
        $tone = $status === 'applied' ? 'text-emerald-300 bg-emerald-500/10 border-emerald-500/20'
              : ($status === 'failed' ? 'text-red-300 bg-red-500/10 border-red-500/20'
              : ($status === 'received' ? 'text-amber-300 bg-amber-500/10 border-amber-500/20'
              : 'text-slate-400 bg-white/5 border-white/10')); ?>
        <tr class="hover:bg-white/[.02]">
          <td class="px-6 py-3 text-slate-500 whitespace-nowrap"><?= htmlspecialchars((string)($row['created_at'] ?? '')) ?></td>
          <td class="px-3 py-3 font-mono text-slate-300 whitespace-nowrap"><?= htmlspecialchars((string)$row['event_type']) ?></td>
          <td class="px-3 py-3 text-slate-400"><?= (int)($row['user_id'] ?? 0) > 0 ? '#' . (int)$row['user_id'] : '<span class="text-slate-600">—</span>' ?></td>
          <td class="px-3 py-3 text-slate-400"><?= htmlspecialchars((string)($row['plan'] ?? '')) ?: '<span class="text-slate-600">—</span>' ?></td>
          <td class="px-3 py-3"><span class="px-2 py-0.5 rounded border <?= $tone ?> font-semibold"><?= htmlspecialchars($status) ?></span></td>
          <td class="px-6 py-3 text-slate-500 max-w-[420px]"><?= htmlspecialchars((string)($row['detail'] ?? '')) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- ══ Paying but not paid for ════════════════════════════════════════════ -->
<div class="glass rounded-2xl border <?= $stranded ? 'border-amber-500/25' : 'border-white/5' ?> overflow-hidden mb-8">
  <div class="px-6 py-4 border-b border-white/5 flex items-center gap-2">
    <i class="fa-solid fa-user-clock <?= $stranded ? 'text-amber-400' : 'text-slate-400' ?> text-sm"></i>
    <h3 class="font-semibold text-sm">Subscribed at Whop, still on Free here</h3>
    <span class="ml-auto text-[11px] text-slate-600"><?= count($stranded) ?> account(s)</span>
  </div>

  <?php if (!$stranded): ?>
  <div class="px-6 py-6 text-xs text-slate-500">
    <i class="fa-solid fa-circle-check mr-2 text-emerald-400"></i>
    None. Every account with a Whop membership on file also has a paid plan — which is the state this page is
    here to keep true.
  </div>
  <?php else: ?>
  <div class="px-6 py-4 text-xs text-amber-200/70 border-b border-white/5">
    Each row is somebody whose card Whop is charging while the product shows them the free tier. Granting here
    goes through the same writer the webhook uses, with the membership id already on file, so the ordering clock
    moves with the plan — a later real event will not undo it.
  </div>
  <div class="divide-y divide-white/5">
    <?php foreach ($stranded as $u): ?>
    <form method="POST" action="/admin/payments.php" class="px-6 py-4 flex items-center gap-3 flex-wrap">
      <?php /* Per account, for the same reason as above: one slot per form. */ ?>
      <input type="hidden" name="csrf_token" value="<?= admin_csrf_token('payments.reconcile.' . (int)$u['id']) ?>">
      <input type="hidden" name="action" value="reconcile">
      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
      <div class="min-w-[210px]">
        <p class="text-xs font-semibold text-white truncate"><?= htmlspecialchars((string)$u['full_name']) ?></p>
        <p class="text-[11px] text-slate-500 truncate"><?= htmlspecialchars((string)$u['email']) ?> · #<?= (int)$u['id'] ?></p>
      </div>
      <div class="font-mono text-[11px] text-slate-400 min-w-[170px]">
        <p><?= htmlspecialchars((string)$u['whop_member_id']) ?></p>
        <p class="text-slate-600"><?= htmlspecialchars((string)($u['whop_membership_id'] ?? '') ?: 'no membership id') ?></p>
      </div>
      <select name="plan" class="bg-slate-900/70 border border-white/10 text-white rounded-lg px-3 py-2 text-xs outline-none">
        <option value="pro">Grant Pro</option>
        <option value="entrepreneur">Grant Entrepreneur</option>
      </select>
      <button type="submit" class="inline-flex items-center gap-2 bg-white/[.07] hover:bg-white/[.14] border border-white/10 text-white px-3.5 py-2 rounded-lg font-semibold text-xs transition">
        <i class="fa-solid fa-hand-holding-dollar"></i> Grant
      </button>
    </form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<p class="text-[11px] text-slate-600">
  Every action on this page is written to <code>storage/admin_access.log</code> and to the application log
  (<code>storage/php_errors.log</code>), so "who granted that" has an answer. Whop's own dashboard is the
  authority on money received — this page reports what this deployment has applied, which is the half Whop
  cannot see.
</p>

<?php require_once __DIR__ . '/../includes/admin_layout_end.php'; ?>
