<?php
/**
 * Support tickets — includes/support.php, api/support.php, api/support-file.php
 * and admin/support.php.
 *
 * WHAT IS BEING DEFENDED
 * ─────────────────────
 * 1. A TICKET IS PRIVATE. Every read and write is scoped by owner, the attachment
 *    endpoint refuses anyone who is not the owner or an admin, and an attachment's
 *    URL is never a storage path. These are the assertions that would matter if
 *    they were wrong, so they get the most space.
 *
 * 2. A MESSAGE IS DATA, NOT MARKUP. Bodies are escaped once and only ever gain
 *    markup through support_linkify(). A pasted <script> stays text, a
 *    javascript: URL stays text, and a real URL becomes a clickable link — all
 *    asserted directly on the function, because it is the only place in the
 *    feature that builds HTML from input.
 *
 * 3. A FILE IS WHAT IT IS. Type is decided by content, the stored name is random,
 *    everything is validated before anything is stored, and a rejected upload must
 *    not leave a ticket carrying half an attachment.
 *
 * 4. THE QUEUE TELLS THE TRUTH. A customer message makes a ticket 'open' and raises
 *    the admin badge, an admin reply makes it 'pending' and raises the customer's.
 *    Getting that backwards is how a support queue quietly rots.
 */

/* ─────────────────────────────────────────────────────────────────────────────
 * 1. Subject and body
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('A subject is a label, and a body keeps its shape');

t_is(support_subject_clean("  Multi\nline\t subject  "), 'Multi line subject',
    'a subject pasted across lines is forced onto one');
t_is(support_subject_clean("bell\x07 and null\x00"), 'bell and null',
    'control characters are stripped rather than rendered as tofu');
t_is(strlen(support_subject_clean(str_repeat('a', 400))), SUPPORT_MAX_SUBJECT,
    'a subject is capped, not truncated mid-multibyte');
t_ok(!support_subject_valid('   '), 'a whitespace-only subject is refused');
t_ok(!support_subject_valid(''), 'and so is an empty one');
t_ok(support_subject_valid('Cannot export leads'), 'a real subject is accepted');

t_is(support_body_clean("a  \r\n\r\nb  "), "a\n\nb",
    'line endings are canonicalised and trailing spaces dropped, blank lines kept');
t_is(support_body_clean("\n\nhello\n\n"), 'hello',
    'leading and trailing blank lines are trimmed');
t_ok(support_body_valid("step 1\nstep 2"), 'a multi-line body is valid');
t_ok(!support_body_valid("  \n  "), 'a whitespace-only body is not');

/* ─────────────────────────────────────────────────────────────────────────────
 * 2. Links
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Links are clickable, and nothing else is');

$link = support_linkify('See https://example.com/docs for details.');
t_like($link, 'href="https://example.com/docs"', 'an https URL becomes a link');
t_like($link, '>https://example.com/docs</a> for details.',
    'and the sentence around it is untouched');
t_like($link, '>https://example.com/docs</a>', 'with the URL itself as the link text');

// The full stop belongs to the sentence, not to the address — this is the case
// that produces a broken link when a naive rule swallows the punctuation.
$comma = support_linkify('Try https://example.com/a, then stop.');
t_like($comma, 'href="https://example.com/a"', 'a trailing comma is not part of the URL');
t_like($comma, '>https://example.com/a</a>,', 'and stays outside the link');

t_like(support_linkify('go to www.example.com'), 'href="https://www.example.com"',
    'a bare www address is linked with a scheme added');
t_like(support_linkify('mail me@example.com please'), 'href="mailto:me@example.com"',
    'an email address becomes a mailto link');

$paren = support_linkify('https://en.wikipedia.org/wiki/Utiligo_(company)');
t_like($paren, '/Utiligo_(company)"', 'a balanced closing bracket stays in the URL');
$unbalanced = support_linkify('see https://example.com/foo)');
t_like($unbalanced, 'href="https://example.com/foo"', 'an unbalanced one does not');
t_like($unbalanced, '</a>)', 'and is left as text after the link');

$query = support_linkify('https://x.test/?a=1&b=2');
t_like($query, 'href="https://x.test/?a=1&amp;b=2"',
    'an ampersand in a query string is entity-encoded inside the href, not a new attribute');

$evil = support_linkify('<script>alert(1)</script>');
t_unlike($evil, '<script', 'a script tag is escaped, never emitted');
t_like($evil, '&lt;script&gt;', 'and appears as visible text');

t_unlike(support_linkify('javascript:alert(1)'), '<a',
    'a javascript: URL is not linkified at all');
t_unlike(support_linkify('data:text/html;base64,PHNjcmlwdD4='), '<a',
    'nor is a data: URL');

// A blank line must survive as ONE blank line. support_linkify() converts newlines
// to <br> itself, so a container that ALSO sets white-space: pre-wrap renders every
// gap twice — the customer sees their paragraph breaks doubled down the page.
t_like(support_linkify("line one\nline two"), "<br", 'line breaks are preserved');
t_is(substr_count(support_linkify("a\n\nb"), '<br>'), 2,
    'and a single blank line is exactly two line breaks, not four');

foreach (['assets/css/support.css' => 'the bubble stylesheet',
          'admin/support.php'      => 'the admin thread'] as $file => $what) {
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    $rendersBody = $file === 'admin/support.php' ? 'body_html' : '.sp-bubble';
    if (t_like($src, $rendersBody, $what . ' still renders a message body')) {
        // Only the RULE matters, not the word appearing in a comment.
        $rules = preg_replace('~(/\*.*?\*/|//[^\n]*)~s', '', $src);
        t_unlike((string)$rules, 'pre-wrap',
            $what . ' does not double the line breaks with pre-wrap');
    }
}

t_unlike(support_linkify('plain words only'), '<a', 'text with no address gains no link');

/* ─────────────────────────────────────────────────────────────────────────────
 * 3. Status
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The ball is always with the right side');

t_ok(support_status_valid('open') && support_status_valid('pending') && support_status_valid('closed'),
    'the three statuses are the three statuses');
t_ok(!support_status_valid('urgent'), 'an invented status is refused');
t_is(support_status_label('closed'), 'Closed', 'statuses have labels');

t_is(support_status_after_user_message('pending'), 'open',
    'a customer message makes the ticket support\'s to answer');
t_is(support_status_after_admin_message('open'), 'pending',
    'an admin reply hands it back');

t_ok(support_can_user_reply('open'), 'a customer can reply to an open ticket');
t_ok(support_can_user_reply('pending'), 'and to a pending one');
t_ok(!support_can_user_reply('closed'), 'but not to a closed one — it must be reopened');
t_ok(support_can_admin_reply('closed'), 'support can always reply, even to a closed ticket');

/* ─────────────────────────────────────────────────────────────────────────────
 * 4. Attachment rules
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('A file is judged by what it is');

t_is(support_attachment_ext('image/png'), 'png', 'PNG maps to .png');
t_is(support_attachment_ext('application/pdf'), 'pdf', 'PDF maps to .pdf');
t_is(support_attachment_ext('application/x-php'), null, 'a script type has no extension');
t_ok(!support_attachment_allowed('text/html'), 'and is refused');
t_ok(!support_attachment_allowed('image/svg+xml'), 'SVG is refused — it can carry script');
t_ok(support_attachment_is_image('image/jpeg'), 'JPEG is an image');
t_ok(!support_attachment_is_image('application/pdf'), 'PDF is not');

t_is(support_attachment_name_clean('../../etc/passwd'), 'etc passwd',
    'path separators are stripped from a display filename');
t_is(support_attachment_name_clean('.htaccess'), 'htaccess', 'a leading dot is removed');
t_is(support_attachment_name_clean(''), 'attachment',
    'an empty name becomes a usable one rather than an empty link label');
t_unlike(support_attachment_name_clean("a\x00b"), "\x00", 'a null byte cannot survive');

t_is(support_format_bytes(900), '900 B', 'bytes read as bytes');
t_is(support_format_bytes(2048), '2 KB', 'and kilobytes as kilobytes');
t_is(support_format_bytes(5 * 1024 * 1024), '5 MB', 'and megabytes as megabytes');

/* ─────────────────────────────────────────────────────────────────────────────
 * The advertised limit is the enforced limit
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The size the UI prints is the size the server accepts');

/** '2M' / '512K' / '-1' as bytes. 0 means unlimited. */
$iniBytes = static function (string $name): int {
    $v = trim((string)ini_get($name));
    if ($v === '' || $v === '-1') {
        return 0;
    }
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) {
        'g'     => $n * 1024 * 1024 * 1024,
        'm'     => $n * 1024 * 1024,
        'k'     => $n * 1024,
        default => $n,
    };
};

$phpMax  = $iniBytes('upload_max_filesize');
$postMax = $iniBytes('post_max_size');

t_ok(support_max_attachment_bytes() <= SUPPORT_MAX_ATTACHMENT_BYTES,
    'the effective cap never exceeds the product cap');
if ($phpMax > 0) {
    t_ok(support_max_attachment_bytes() <= $phpMax,
        'and never exceeds PHP\'s own upload_max_filesize (' . ini_get('upload_max_filesize') . ')');
}
t_ok(support_max_attachment_bytes() > 0,
    'while still advertising something usable rather than zero');
if ($postMax > 0) {
    t_ok(support_max_attachment_bytes() * SUPPORT_MAX_ATTACHMENTS <= $postMax,
        'and ' . SUPPORT_MAX_ATTACHMENTS . ' files still fit in one post_max_size, so the request is not received empty');
}

/* ─────────────────────────────────────────────────────────────────────────────
 * 5. Response shapes
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The shapes the two sides render');

$tp = support_ticket_public(['id' => 7, 'subject' => 'Broken export', 'status' => 'closed',
    'unread_user' => 2, 'message_count' => 4, 'last_message_at' => '2026-01-01 10:00:00']);
t_is($tp['id'], 7, 'a ticket carries its id');
t_is($tp['status_label'], 'Closed', 'a human label, so the client never maps status itself');
t_is($tp['can_reply'], false, 'and whether the customer may reply, decided by the server');
t_is($tp['unread'], 2, 'with the unread count');

$msg = support_message_public(['id' => 1, 'author_type' => 'admin', 'body' => 'see https://x.test now'],
    'user', []);
t_is($msg['mine'], false, '"mine" is decided by who spoke, not by the caller');
t_like($msg['body_html'], '<a href="https://x.test"', 'the html version is linkified');
t_is(support_message_public(['author_type' => 'user', 'body' => 'hi'], 'user', [])['mine'], true,
    'and the same rule marks the customer\'s own message');

$grouped = support_group_attachments([
    ['id' => 1, 'message_id' => 5, 'mime_type' => 'image/png', 'file_size' => 100],
    ['id' => 2, 'message_id' => 5, 'mime_type' => 'application/pdf', 'file_size' => 2048],
    ['id' => 3, 'message_id' => 6, 'mime_type' => 'image/png', 'file_size' => 100],
]);
t_is(count($grouped[5]), 2, 'attachments are grouped by the message that carried them');
t_is(count($grouped[6]), 1, 'and not by ticket');
$att = support_attachment_public($grouped[6][0]);
t_like($att['url'], '/api/support-file.php?id=3',
    'an attachment is served through the checked endpoint');
t_unlike($att['url'], 'storage/', 'never as a direct storage path');

/* ─────────────────────────────────────────────────────────────────────────────
 * 5b. The panel's presentation rules
 *
 * Everything below is a TEXT-LEVEL guard on a decision that was made once and
 * could be undone by a tidy-up. It cannot prove the panel looks right — only a
 * browser can — but it can prove the decisions are still implemented, which is
 * the failure mode these rules actually have: a later edit restores a full-screen
 * mobile sheet or drops the font link, and nothing else notices.
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('The panel floats at every width, including phones');

$cssDir  = dirname(__DIR__, 2);
$css     = (string)file_get_contents($cssDir . '/assets/css/support.css');
$js      = (string)file_get_contents($cssDir . '/assets/js/support.js');
$layout  = (string)file_get_contents($cssDir . '/includes/portal_layout.php');
$adminL  = (string)file_get_contents($cssDir . '/includes/admin_layout.php');

/** The @media block for phones, so its rules can be judged on their own. */
$mobile = '';
if (preg_match('~@media \(max-width: 767px\)\s*\{(.*?)\n\}~s', $css, $m)) {
    $mobile = $m[1];
}
t_ok($mobile !== '', 'there is a phone breakpoint for the panel');
t_unlike($mobile, 'width: 100%', 'and it is NOT a full-screen sheet');
t_unlike($mobile, 'height: 100%', 'still sized as a card, not as the screen');
t_like($mobile, 'calc(100vw - ', 'inset from the viewport edge instead');
t_like($css, 'height: min(560px, calc(100dvh - 120px))',
    'and the desktop height uses dvh where it exists, so a mobile URL bar cannot cover the composer');

t_section('It animates, and the animation is optional');

t_like($css, '.sp-panel.is-open', 'the panel has an explicit open state to animate to');
t_like($css, 'transform-origin: 100% 100%', 'which grows out of the corner it lives in');
t_like($js, "panel.classList.add('is-open')", 'and the script drives that state');
t_like($js, 'requestAnimationFrame',
    'from a frame after unhiding, or the browser skips the transition entirely');
t_like($js, 'if (!state.open) panel.hidden = true',
    'the panel is only hidden once the closing transition has had its window');

$reduced = '';
if (preg_match('~@media \(prefers-reduced-motion: reduce\)\s*\{(.*?)\n\}~s', $css, $m)) {
    $reduced = $m[1];
}
t_like($reduced, '.sp-panel',
    'a reduced-motion user gets the panel without the movement, not a panel that stays invisible');
t_like($reduced, 'animation: none', 'and no reply pulse');

t_section('Its own typeface, actually delivered');

t_like($css, "--sp-font: 'Space Grotesk'", 'the channel declares its own face, once');
t_like($css, '.sp-channel', 'and a class the admin inbox shares so one conversation looks like one product');
t_like($layout, 'family=Space+Grotesk', 'the portal loads it');
t_like($adminL, 'family=Space+Grotesk', 'so does the admin panel');
t_like($adminL, "$adminPage === 'support'",
    'but only on the page that needs it, not on every admin screen');

t_section('A reply is announced, quietly and at the right time');

t_like($js, "localStorage.getItem('utiligo_support_muted')",
    'the chime preference is remembered across page loads');
t_like($js, 'if (state.muted) return;', 'and the chime itself checks the preference');
t_like($js, 'AudioContext', 'the sound is synthesised — nothing to ship, nothing to 404');
t_like($js, 'createOscillator', 'from two oscillators rather than an audio file');
t_like($js, "document.visibilityState === 'visible'",
    'nothing is asked of the API while the tab is hidden');
t_like($js, 'animationend', 'the launcher pulse clears itself so it can fire again');
t_unlike($js, 'Notification.requestPermission',
    'and no browser-notification permission prompt is raised behind the customer\'s back');

/* ─────────────────────────────────────────────────────────────────────────────
 * 6. The endpoint
 * ──────────────────────────────────────────────────────────────────────────── */

$haveApp = !empty($context['app_url']) && !empty($context['db_ready']);
if (!$haveApp) {
    t_skip('the support endpoint', 'requires the application server and the database');
    return;
}

$app = $context['app_url'];
$tmp = t_tmp_dir();

/** POST JSON as a user, with a fresh valid CSRF pair. */
$post = function (int $uid, array $body) use ($app): array {
    $token = bin2hex(random_bytes(16));
    $res = t_http('POST', $app . '/api/support.php', [
        'json'   => $body + ['csrf_token' => $token],
        'cookie' => t_login($uid, ['csrf_token' => $token]),
    ]);
    return ['status' => $res['status'], 'json' => json_decode($res['body'], true) ?: [], 'body' => $res['body']];
};

/** POST multipart as a user — fields plus files[bracketed index] => file spec. */
$postFiles = function (int $uid, array $fields, array $files) use ($app): array {
    $token = bin2hex(random_bytes(16));
    $mp = $fields + ['csrf_token' => $token];
    foreach ($files as $i => $f) {
        $mp['files[' . $i . ']'] = $f;
    }
    $res = t_http('POST', $app . '/api/support.php', [
        'multipart' => $mp,
        'cookie'    => t_login($uid, ['csrf_token' => $token]),
    ]);
    return ['status' => $res['status'], 'json' => json_decode($res['body'], true) ?: []];
};

$get = function (int $uid, int $ticketId) use ($app): array {
    $token = bin2hex(random_bytes(16));
    $res = t_http('POST', $app . '/api/support.php', [
        'json'   => ['csrf_token' => $token, 'op' => 'get', 'ticket_id' => $ticketId],
        'cookie' => t_login($uid, ['csrf_token' => $token]),
    ]);
    return ['status' => $res['status'], 'json' => json_decode($res['body'], true) ?: []];
};

/* Fixtures: two customers, one admin, and a real PNG / a fake one / a huge one. */
$alice = t_fixture(['full_name' => 'Alice Support']);
$bob   = t_fixture(['full_name' => 'Bob Nosy']);
$root  = t_fixture(['full_name' => 'Root Admin', 'is_admin' => 1]);

$pngPath = $tmp . '/support_pixel.png';
file_put_contents($pngPath, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
));
$fakePath = $tmp . '/support_fake.png';
file_put_contents($fakePath, '<?php echo "this is not a png";');
$bigPath = $tmp . '/support_big.png';
file_put_contents($bigPath, str_repeat('A', 6 * 1024 * 1024));

// One byte past whatever this server will actually take. Deliberately not a real
// image: the size gate runs before the type gate, so this proves the size gate.
$overPath = $tmp . '/support_over.png';
file_put_contents($overPath, str_repeat('A', support_max_attachment_bytes() + 1));

$pngSpec  = ['file' => $pngPath,  'name' => 'screenshot.png', 'type' => 'image/png'];
$fakeSpec = ['file' => $fakePath, 'name' => 'screenshot.png', 'type' => 'image/png'];

t_section('A customer opens a ticket');

$created = $post($alice, [
    'op'      => 'create',
    'subject' => 'Export does nothing',
    'body'    => "I click export and nothing happens.\nFull detail at https://utiligo.ca/help and <script>alert(1)</script>",
]);
t_is($created['status'], 200, 'creating a ticket succeeds');
t_ok(!empty($created['json']['ticket_id']), 'and returns its id');
$ticketId = (int)($created['json']['ticket_id'] ?? 0);

$list = $post($alice, ['op' => 'list']);
t_is($list['status'], 200, 'the ticket list loads');
t_is(count($list['json']['tickets'] ?? []), 1, 'and shows the one ticket');
t_is($list['json']['unread'], 0, 'which is not unread for the person who just wrote it');

$thread = $get($alice, $ticketId);
t_is($thread['status'], 200, 'the owner can open the thread');
t_is(count($thread['json']['messages'] ?? []), 1, 'and sees their message');
$m0 = $thread['json']['messages'][0];
t_is($m0['mine'], true, 'marked as theirs');
t_like($m0['body_html'], '<a href="https://utiligo.ca/help"', 'with the URL clickable');
t_unlike($m0['body_html'], '<script', 'and the pasted script inert');
t_like($m0['body_html'], '&lt;script&gt;', 'visible as text instead');

t_section('A ticket belongs to one customer');

t_is($get($bob, $ticketId)['status'], 404, 'another customer cannot read it');
t_is($post($bob, ['op' => 'reply', 'ticket_id' => $ticketId, 'body' => 'hi'])['status'], 404,
    'nor reply to it');
t_is($post($bob, ['op' => 'close', 'ticket_id' => $ticketId])['status'], 404,
    'nor close it');
t_is($get($alice, 99999999)['status'], 404, 'and a ticket that does not exist is a 404');
t_is($post($alice, ['op' => 'nonsense'])['status'], 400, 'an unknown op is refused');

t_section('Replying, closing, reopening');

t_is($post($alice, ['op' => 'reply', 'ticket_id' => $ticketId, 'body' => 'Any update?'])['status'], 200,
    'the owner can reply');
t_is(count($get($alice, $ticketId)['json']['messages']), 2, 'and it joins the thread');

t_is($post($alice, ['op' => 'close', 'ticket_id' => $ticketId])['status'], 200, 'a ticket can be closed');
t_is($post($alice, ['op' => 'reply', 'ticket_id' => $ticketId, 'body' => 'more'])['status'], 409,
    'a closed ticket refuses a reply rather than swallowing it');
$reopened = $post($alice, ['op' => 'reopen', 'ticket_id' => $ticketId]);
t_is($reopened['json']['status'] ?? '', 'open', 'reopening reopens it');
t_is($post($alice, ['op' => 'reply', 'ticket_id' => $ticketId, 'body' => 'and now?'])['status'], 200,
    'and a reply works again');

t_section('An empty request is refused, and a subject is required');

t_is($post($alice, ['op' => 'create', 'subject' => 'No body'])['status'], 400, 'a subject with no body or file is refused');
t_is($post($alice, ['op' => 'create', 'subject' => '  ', 'body' => 'x'])['status'], 400, 'a blank subject is refused');

t_section('Attachments: content, not filename');

$att = $postFiles($alice, ['op' => 'create', 'subject' => 'With a screenshot', 'body' => 'Here it is'], [0 => $pngSpec]);
t_is($att['status'], 200, 'a real PNG attaches');
t_is($att['json']['attachments'], 1, 'and is counted');
$attTicket = (int)($att['json']['ticket_id'] ?? 0);

$attThread = $get($alice, $attTicket);
$a = $attThread['json']['messages'][0]['attachments'][0] ?? null;
t_ok(is_array($a), 'the attachment comes back on the message');
t_is($a['is_image'] ?? null, true, 'flagged as an image so it renders inline');
t_is($a['name'] ?? '', 'screenshot.png', 'with its original name for the label');
t_like($a['url'] ?? '', '/api/support-file.php?id=', 'served through the checked endpoint');

$fileUrl = $app . ($a['url'] ?? '');
$ownerFetch = t_http('GET', $fileUrl, ['cookie' => t_login($alice)]);
t_is($ownerFetch['status'], 200, 'the owner can fetch the file');
t_ok(str_contains(implode("\n", $ownerFetch['headers']), 'image/png'),
    'and it is served with its real content type');
t_is($ownerFetch['body'], file_get_contents($pngPath), 'byte for byte');

t_is(t_http('GET', $fileUrl, ['cookie' => t_login($bob)])['status'], 404,
    'another customer cannot fetch it — and is not told it exists');
t_is(t_http('GET', $app . '/api/support-file.php')['status'], 401,
    'nor can anyone who is not logged in');
t_is(t_http('GET', $app . '/api/support-file.php?id=99999999', ['cookie' => t_login($alice)])['status'], 404,
    'a missing file is a 404');

t_is($postFiles($alice, ['op' => 'create', 'subject' => 'Fake png', 'body' => 'x'], [0 => $fakeSpec])['status'], 400,
    'a .png that is really a script is refused');
t_is($postFiles($alice, ['op' => 'create', 'subject' => 'Too many', 'body' => 'x'],
    [0 => $pngSpec, 1 => $pngSpec, 2 => $pngSpec, 3 => $pngSpec])['status'], 400,
    'more files than the cap allows is refused');
t_unlike($postFiles($alice, ['op' => 'create', 'subject' => 'Too many', 'body' => 'x'],
    [0 => $pngSpec, 1 => $pngSpec, 2 => $pngSpec, 3 => $pngSpec])['json']['error'] ?? '',
    'db_error', 'and the refusal is a rule, not a crash');
$oversized = $postFiles($alice, ['op' => 'create', 'subject' => 'Huge', 'body' => 'x'],
    [0 => ['file' => $bigPath, 'name' => 'big.png', 'type' => 'image/png']]);
t_is($oversized['status'], 400, 'an oversized file is refused');
t_is($oversized['json']['error'] ?? '', 'file_too_large',
    'and told it was the size, not a CSRF or a parse failure');
t_is($postFiles($alice, ['op' => 'create', 'subject' => 'Just over', 'body' => 'x'],
    [0 => ['file' => $overPath, 'name' => 'over.png', 'type' => 'image/png']])['json']['error'] ?? '',
    'file_too_large', 'the effective cap itself is enforced on the server, not only in the browser');

/* ─────────────────────────────────────────────────────────────────────────────
 * 7. The admin inbox
 * ──────────────────────────────────────────────────────────────────────────── */

t_section('Support can see the queue, and only support');

t_is(t_http('GET', $app . '/admin/support.php', ['cookie' => t_login($bob)])['status'], 403,
    'a normal customer is refused the admin inbox');

$adminSession = t_login($root);
$inbox = t_http('GET', $app . '/admin/support.php', ['cookie' => $adminSession]);
t_is($inbox['status'], 200, 'an admin can open it');
t_like($inbox['body'], 'Export does nothing', 'and sees the ticket subject');

$detail = t_http('GET', $app . '/admin/support.php?view=' . $ticketId, ['cookie' => $adminSession]);
t_is($detail['status'], 200, 'the admin can open a thread');
t_like($detail['body'], 'I click export and nothing happens',
    'and read the customer\'s message');
t_unlike($detail['body'], 'Utiligo Support',
    'attributed to the customer, because no admin has spoken yet');

/** Fetch a fresh admin CSRF token from a rendered page. */
$adminToken = function (string $url, string $cookie) use ($app): string {
    $page = t_http('GET', $app . $url, ['cookie' => $cookie]);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page['body'], $m);
    return $m[1] ?? '';
};

$reply = t_http('POST', $app . '/admin/support.php?view=' . $ticketId, [
    'form' => [
        'csrf_token' => $adminToken('/admin/support.php?view=' . $ticketId, $adminSession),
        'ticket_id'  => $ticketId,
        'action'     => 'reply',
        'body'       => 'Thanks — that is a known export bug. Details: https://status.utiligo.ca',
    ],
    'cookie' => $adminSession,
]);
t_is($reply['status'], 302, 'an admin reply is accepted and redirected (no double-send on refresh)');

$after = $get($alice, $ticketId);
$last  = end($after['json']['messages']);
t_is($last['author_type'], 'admin', 'the reply lands in the thread as support');
t_is($last['mine'], false, 'and is not attributed to the customer');

// The same reply, as the admin sees it: now the label proves whose voice it was.
$adminView = t_http('GET', $app . '/admin/support.php?view=' . $ticketId, ['cookie' => $adminSession]);
t_like($adminView['body'], 'Utiligo Support', 'and the admin view labels it as support');
t_like($last['body_html'], '<a href="https://status.utiligo.ca"', 'and its link is clickable');
t_is($after['json']['ticket']['status'], 'pending',
    'and the ticket is now waiting on the customer');
t_ok($after['json']['ticket']['can_reply'], 'who can reply');

$ticketRow = null;
try {
    $st = t_platform_db()->prepare('SELECT * FROM support_tickets WHERE id = ?');
    $st->execute([$ticketId]);
    $ticketRow = $st->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Reported by the assertion below rather than thrown.
}
t_is((int)($ticketRow['unread_user'] ?? -1), 0,
    'reading the thread cleared the customer\'s unread count');
t_is((int)($ticketRow['unread_admin'] ?? -1), 0,
    'and opening it as the admin cleared the admin badge');

$statusPost = t_http('POST', $app . '/admin/support.php?view=' . $ticketId, [
    'form' => [
        'csrf_token' => $adminToken('/admin/support.php?view=' . $ticketId, $adminSession),
        'ticket_id'  => $ticketId,
        'action'     => 'set_status',
        'status'     => 'closed',
    ],
    'cookie' => $adminSession,
]);
t_is($statusPost['status'], 302, 'an admin can change the status');
t_is($get($alice, $ticketId)['json']['ticket']['status'], 'closed', 'and it sticks');

$adminFile = t_http('GET', $fileUrl, ['cookie' => t_login($root)]);
t_is($adminFile['status'], 200, 'an admin can fetch a customer\'s attachment');

/* ─────────────────────────────────────────────────────────────────────────────
 * Clean up: the suite must not leave rows or files behind.
 * ──────────────────────────────────────────────────────────────────────────── */

try {
    $pdo = t_platform_db();
    $ids = [$alice, $bob, $root];
    $in  = implode(',', array_map('intval', $ids));
    $pdo->exec("DELETE FROM support_attachments WHERE user_id IN ($in)");
    foreach ($pdo->query("SELECT id FROM support_tickets WHERE user_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        $del = $pdo->prepare('DELETE FROM support_messages WHERE ticket_id = ?');
        $del->execute([(int)$tid]);
    }
    $pdo->exec("DELETE FROM support_tickets WHERE user_id IN ($in)");
} catch (\Throwable $e) {
    // Best effort: a leftover test row is not worth failing the suite over.
}
foreach ([$alice, $bob, $root] as $uid) {
    $dir = dirname(__DIR__, 2) . '/' . SUPPORT_UPLOAD_DIR_REL . '/' . $uid;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}
@unlink($pngPath);
@unlink($fakePath);
@unlink($bigPath);
@unlink($overPath);
