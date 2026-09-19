<?php
/**
 * includes/support.php
 *
 * Support tickets: the bubble in the corner, and the transcript behind it.
 *
 * WHY THIS EXISTS
 * ──────────────
 * A customer who hits a wall had nowhere in the product to say so. The only route
 * was to find an email address and describe the problem from memory, with no
 * record on either side. A ticket is that route, inside the product, next to the
 * page it happened on.
 *
 * WHAT IS PURE AND WHAT IS NOT
 * ───────────────────────────
 * Everything about the RULES is pure — validation, status transitions, link
 * building, attachment caps — so the parts worth arguing about are asserted
 * directly instead of through an HTTP round-trip. Only the two unread counters at
 * the very bottom touch a database, and both swallow their own errors, because a
 * badge is not worth a 500.
 *
 * THE DECISIONS THAT SHAPE THE REST
 * ────────────────────────────────
 * 1. A message body is stored VERBATIM apart from line-ending and trailing-space
 *    normalisation, and rendered by escaping FIRST and only ever adding markup to
 *    text that is already inert. support_linkify() is the one place that adds
 *    markup, which is why it is the one place worth reading twice.
 *
 * 2. A closed ticket is closed to the CUSTOMER, not to support. Support can always
 *    reply (which puts the ticket back in front of the customer), and a customer
 *    reply reopens it — a customer who writes again is not shouting into a void.
 *
 * 3. Attachments are validated by CONTENT, never by extension: a .png that is
 *    actually a PHP file is refused, and the stored filename is random, so nothing
 *    a customer sends can ever be executed or guessed.
 */

/** Longest a ticket subject may be. One line — it is drawn in a list row. */
if (!defined('SUPPORT_MAX_SUBJECT')) define('SUPPORT_MAX_SUBJECT', 160);

/** Longest a single message may be. A screenshot and a paragraph fit comfortably. */
if (!defined('SUPPORT_MAX_BODY')) define('SUPPORT_MAX_BODY', 8000);

/** Files per message. Three covers "here is the page, here is the error, here is the URL". */
if (!defined('SUPPORT_MAX_ATTACHMENTS')) define('SUPPORT_MAX_ATTACHMENTS', 3);

/** Per-file ceiling, matching api/upload-image.php so the limits are one story. */
if (!defined('SUPPORT_MAX_ATTACHMENT_BYTES')) define('SUPPORT_MAX_ATTACHMENT_BYTES', 5 * 1024 * 1024);

/**
 * Open tickets one account may have at once. This is an anti-spam backstop, not a
 * product limit — nobody legitimately has twenty open conversations — and it is
 * deliberately generous so it never becomes the thing that stops a real report.
 */
if (!defined('SUPPORT_MAX_OPEN_TICKETS')) define('SUPPORT_MAX_OPEN_TICKETS', 20);

/** Where uploaded files live, relative to the app root. Denied by storage/.htaccess. */
if (!defined('SUPPORT_UPLOAD_DIR_REL')) define('SUPPORT_UPLOAD_DIR_REL', 'storage/support');

/* ─────────────────────────────────────────────────────────────────────────────
 * Status
 * ──────────────────────────────────────────────────────────────────────────── */

function support_statuses(): array
{
    return ['open', 'pending', 'closed'];
}

function support_status_valid(string $status): bool
{
    return in_array($status, support_statuses(), true);
}

function support_status_label(string $status): string
{
    return [
        'open'    => 'Awaiting support',
        'pending' => 'Awaiting you',
        'closed'  => 'Closed',
    ][$status] ?? 'Awaiting support';
}

/**
 * May the customer add a message to a ticket in this state?
 *
 * Only a closed ticket says no, and even then the UI offers Reopen rather than a
 * dead end.
 */
function support_can_user_reply(string $status): bool
{
    return $status !== 'closed';
}

/** Support can reply to anything, including a closed ticket. */
function support_can_admin_reply(string $status): bool
{
    return true;
}

/**
 * The status a ticket moves to once each side speaks.
 *
 * A customer message always means "support owes me an answer", so it is 'open'. An
 * admin message hands the ball back, so it is 'pending'. Getting this backwards is
 * how a support queue silently fills with tickets nobody knows they own.
 */
function support_status_after_user_message(string $status): string
{
    return 'open';
}

function support_status_after_admin_message(string $status): string
{
    return 'pending';
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Validation
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * A subject forced onto one line, trimmed and capped.
 *
 * Returns '' when nothing printable is left, which callers treat as invalid rather
 * than inventing a title — a ticket named "" is unusable in a list.
 */
function support_subject_clean(string $subject): string
{
    // Strip control characters first: a subject pasted out of a terminal otherwise
    // carries escapes that render as tofu in the list.
    $subject = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $subject) ?? '';
    $subject = preg_replace('/\s+/u', ' ', $subject) ?? '';
    return mb_substr(trim($subject), 0, SUPPORT_MAX_SUBJECT);
}

function support_subject_valid(string $subject): bool
{
    return support_subject_clean($subject) !== '';
}

/**
 * A body with canonical line endings and no trailing spaces on any line, but with
 * every meaningful line break kept. Interior blank lines are how someone separates
 * "what I did" from "what happened", so they are preserved exactly.
 */
function support_body_clean(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body) ?? $body;

    $lines = array_map(static fn(string $l): string => rtrim($l), explode("\n", $body));

    return mb_substr(trim(implode("\n", $lines)), 0, SUPPORT_MAX_BODY);
}

function support_body_valid(string $body): bool
{
    return support_body_clean($body) !== '';
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Links
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * Escape a message body and turn the URLs and email addresses in it into links.
 *
 * This is the only place in the feature that creates markup from user input, so it
 * is written to be safe by construction: the text is escaped ONCE, first, and every
 * later step only ever adds markup to text that can no longer contain a tag. A
 * `javascript:` URL is not linkified, because only http(s), www. and bare email
 * addresses are matched at all — the scheme never reaches the attribute.
 *
 * URLs and emails are matched in a SINGLE pass. Two passes would let the second
 * find an address inside the href the first had just built.
 */
function support_linkify(string $text): string
{
    $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $safe = preg_replace_callback(
        '~((?:https?://|www\.)[^\s<>"\']+|[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})~u',
        static function (array $m): string {
            $raw = $m[1];

            // Sentence punctuation after a URL is not part of it. A closing bracket
            // goes only when it is unbalanced, so `..._(disambiguation)` survives.
            $trail = '';
            while ($raw !== '' && preg_match('/[.,;:!?]$/', $raw)) {
                $trail = substr($raw, -1) . $trail;
                $raw   = substr($raw, 0, -1);
            }
            if (substr($raw, -1) === ')' && substr_count($raw, '(') < substr_count($raw, ')')) {
                $trail = ')' . $trail;
                $raw   = substr($raw, 0, -1);
            }
            if ($raw === '') {
                return $m[1];
            }

            $isEmail = str_contains($raw, '@')
                && !preg_match('~^https?://~i', $raw)
                && !preg_match('~^www\.~i', $raw);

            if ($isEmail) {
                return '<a href="mailto:' . $raw . '" class="sp-link">' . $raw . '</a>' . $trail;
            }

            $href = preg_match('~^https?://~i', $raw) ? $raw : 'https://' . $raw;

            return '<a href="' . $href . '" class="sp-link" target="_blank" rel="noopener noreferrer nofollow">'
                 . $raw . '</a>' . $trail;
        },
        $safe
    ) ?? $safe;

    return nl2br($safe, false);
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Attachments
 * ──────────────────────────────────────────────────────────────────────────── */

/**
 * MIME type => stored extension. Images plus PDF: a support report is a screenshot
 * or a document export, and both are things a person can produce from a browser.
 *
 * The extension is derived from the DETECTED type, never from the uploaded
 * filename, so a file's name cannot decide what it is stored as.
 */
function support_allowed_mimes(): array
{
    return [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        'application/pdf' => 'pdf',
    ];
}

function support_attachment_ext(string $mime): ?string
{
    return support_allowed_mimes()[$mime] ?? null;
}

/**
 * The largest file this SERVER will accept — which is not always the largest file
 * the product would like to offer.
 *
 * SUPPORT_MAX_ATTACHMENT_BYTES is a product decision; upload_max_filesize and
 * post_max_size are facts about the machine, and on shared hosting they are small
 * (2 MB is the common default, and it is what this project runs on). Printing "5 MB
 * each" on such a host means a customer picks a screenshot, waits for the upload,
 * and is refused by a limit nobody mentioned — and the refusal they see is PHP's,
 * not ours, so the message has nothing to do with what they attached.
 *
 * So the number the UI prints and the number validation enforces are derived here,
 * once, from the smallest limit that actually applies. post_max_size is divided by
 * the attachment count because all three files travel in ONE request: three files
 * that each fit under upload_max_filesize still fail the request if the total
 * overflows post_max_size, and that failure arrives as empty $_POST, which looks
 * like a CSRF problem rather than a size one.
 */
function support_max_attachment_bytes(): int
{
    $cap = SUPPORT_MAX_ATTACHMENT_BYTES;

    $bytes = static function (string $iniName): int {
        $value = trim((string)ini_get($iniName));
        if ($value === '' || $value === '-1') {
            return 0; // 0 means unlimited.
        }
        $number = (int)$value;
        switch (strtolower(substr($value, -1))) {
            case 'g': return $number * 1024 * 1024 * 1024;
            case 'm': return $number * 1024 * 1024;
            case 'k': return $number * 1024;
            default:  return $number;
        }
    };

    $perFile = $bytes('upload_max_filesize');
    if ($perFile > 0) {
        $cap = min($cap, $perFile);
    }

    $perRequest = $bytes('post_max_size');
    if ($perRequest > 0) {
        // Leave a little room for the fields, the boundary and the CSRF token.
        $share = (int)floor(($perRequest - 65536) / max(1, SUPPORT_MAX_ATTACHMENTS));
        if ($share > 0) {
            $cap = min($cap, $share);
        }
    }

    // Never advertise an unusable zero, even on a machine configured absurdly.
    return max($cap, 64 * 1024);
}

function support_attachment_allowed(string $mime): bool
{
    return support_attachment_ext($mime) !== null;
}

function support_attachment_is_image(string $mime): bool
{
    return str_starts_with($mime, 'image/');
}

/** A human byte count. Bytes and KB with no decimal are not worth a decimal point. */
function support_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1), '0'), '.') . ' MB';
}

/**
 * A filename safe to echo and safe to store beside.
 *
 * Path separators, control characters and leading dots are the whole point: this
 * string is displayed back to both sides and used as the download filename, so it
 * must not be able to carry a path, a null, or a hidden-file prefix.
 */
function support_attachment_name_clean(string $name): string
{
    $name = str_replace(['\\', '/'], ' ', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    // All leading dots and spaces go, not just the first one. Stripping a single
    // dot would leave ".. etc passwd" from "../../etc/passwd" — harmless without a
    // separator, but it reads as a path and is not what a filename looks like. A
    // dot-prefixed hidden file (`.htaccess`) loses it for the same reason: the name
    // is echoed to two people and used as a download filename, so it must be plain.
    $name = trim(preg_replace('/^[\s.]+/', '', $name) ?? '');
    if ($name === '') {
        $name = 'attachment';
    }
    return mb_substr($name, 0, 160);
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Response shapes
 * ──────────────────────────────────────────────────────────────────────────── */

/** Explicit rather than SELECT *-shaped, so a new column cannot leak by accident. */
function support_ticket_public(array $row): array
{
    $status = (string)($row['status'] ?? 'open');

    return [
        'id'              => (int)($row['id'] ?? 0),
        'subject'         => (string)($row['subject'] ?? ''),
        'status'          => $status,
        'status_label'    => support_status_label($status),
        'message_count'   => (int)($row['message_count'] ?? 0),
        'unread'          => (int)($row['unread_user'] ?? 0),
        'last_message_at' => $row['last_message_at'] ?? null,
        'created_at'      => $row['created_at'] ?? null,
        'can_reply'       => support_can_user_reply($status),
    ];
}

function support_attachment_public(array $row): array
{
    $mime = (string)($row['mime_type'] ?? '');
    $size = (int)($row['file_size'] ?? 0);

    return [
        'id'         => (int)($row['id'] ?? 0),
        'name'       => (string)($row['original_name'] ?? 'attachment'),
        'mime'       => $mime,
        'size'       => $size,
        'size_label' => support_format_bytes($size),
        'is_image'   => support_attachment_is_image($mime),
        'width'      => isset($row['width']) ? (int)$row['width'] : null,
        'height'     => isset($row['height']) ? (int)$row['height'] : null,
        // Served through the ownership-checked endpoint, never a direct path.
        'url'        => '/api/support-file.php?id=' . (int)($row['id'] ?? 0),
    ];
}

/**
 * One message, ready to render.
 *
 * `mine` is what the bubble uses to align the two sides, and it is decided from the
 * stored author_type rather than from anything the caller supplies.
 *
 * @param array $attachments rows from support_attachments, already filtered to this message
 */
function support_message_public(array $row, string $viewer, array $attachments = []): array
{
    $authorType = (string)($row['author_type'] ?? 'user');
    $body       = (string)($row['body'] ?? '');

    return [
        'id'          => (int)($row['id'] ?? 0),
        'author_type' => $authorType,
        'mine'        => $authorType === $viewer,
        'body'        => $body,
        'body_html'   => support_linkify($body),
        'created_at'  => $row['created_at'] ?? null,
        'attachments' => array_values(array_map('support_attachment_public', $attachments)),
    ];
}

/** Group attachment rows by the message they belong to. */
function support_group_attachments(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $out[(int)($row['message_id'] ?? 0)][] = $row;
    }
    return $out;
}

/** The customer-facing label for who spoke. */
function support_author_label(string $authorType, string $customerFirstName = ''): string
{
    if ($authorType === 'admin') {
        return 'Utiligo Support';
    }
    return $customerFirstName !== '' ? $customerFirstName : 'You';
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Unread counters
 *
 * The only two functions here that touch a database. Both swallow every error and
 * answer 0, because they feed a badge: an unreachable database should make the
 * badge under-report, never break the page that asked.
 * ──────────────────────────────────────────────────────────────────────────── */

function support_unread_admin(?PDO $pdo = null): int
{
    try {
        if ($pdo === null) {
            if (!function_exists('get_platform_db')) {
                require_once __DIR__ . '/../db.php';
            }
            $pdo = get_platform_db();
        }
        return (int)$pdo->query('SELECT COALESCE(SUM(unread_admin), 0) FROM support_tickets')->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}

function support_unread_user(int $userId, ?PDO $pdo = null): int
{
    if ($userId <= 0) {
        return 0;
    }
    try {
        if ($pdo === null) {
            if (!function_exists('get_platform_db')) {
                require_once __DIR__ . '/../db.php';
            }
            $pdo = get_platform_db();
        }
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(unread_user), 0) FROM support_tickets WHERE user_id = ?');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        return 0;
    }
}
