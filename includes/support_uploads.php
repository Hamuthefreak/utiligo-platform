<?php
/**
 * includes/support_uploads.php
 *
 * The attachment half of support, shared by the customer endpoint and the admin
 * page so the two can never disagree about what is allowed.
 *
 * WHY IT IS SEPARATE FROM includes/support.php
 * ───────────────────────────────────────────
 * The model file is deliberately pure — no filesystem, no PDO — so its rules can be
 * asserted without a database. These functions touch $_FILES and the disk, which is
 * a different kind of thing, so they live here rather than being mixed in.
 *
 * THE ORDER THAT MATTERS
 * ─────────────────────
 * VALIDATE EVERYTHING, THEN STORE ANYTHING. A rejection mid-way through storing
 * would leave a ticket carrying two of the three screenshots someone attached,
 * which is worse than carrying none — the thread would look complete and be wrong.
 * Storing only after every file has passed is what makes the failure atomic from
 * the caller's point of view.
 *
 * A FILE IS WHAT IT IS, NOT WHAT IT IS CALLED. The MIME type is read from the
 * bytes with finfo, the stored extension is derived from that type, and the stored
 * name is random. A `.png` that is really a script is refused, and nothing a
 * customer sends can be fetched or guessed by name.
 */

require_once __DIR__ . '/support.php';

/**
 * $_FILES['files'] flattened into one entry per file.
 *
 * PHP's multi-file shape is parallel arrays, which is easy to get subtly wrong, so
 * it is normalised once here rather than indexed inline at each call site.
 */
function support_collect_files(): array
{
    if (!isset($_FILES['files']) || !is_array($_FILES['files']['name'] ?? null)) {
        return [];
    }
    $out = [];
    foreach ($_FILES['files']['name'] as $i => $name) {
        $out[] = [
            'name'     => (string)$name,
            'tmp_name' => (string)($_FILES['files']['tmp_name'][$i] ?? ''),
            'error'    => (int)($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int)($_FILES['files']['size'][$i] ?? 0),
        ];
    }
    // An empty file input submits one entry with error 4, which is not a file.
    return array_values(array_filter($out, static fn(array $f): bool => $f['error'] !== UPLOAD_ERR_NO_FILE));
}

/** The MIME type of an uploaded temp file, by content. '' when it cannot be read. */
function support_detect_mime(string $tmpPath): string
{
    if ($tmpPath === '' || !is_file($tmpPath)) {
        return '';
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) {
        return '';
    }
    $mime = (string)finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    return $mime;
}

/**
 * Validate every file before any is stored.
 *
 * @return array{ok: bool, error: string, files: array}
 */
function support_validate_files(array $files): array
{
    if (count($files) > SUPPORT_MAX_ATTACHMENTS) {
        return ['ok' => false, 'error' => 'too_many_files', 'files' => []];
    }
    foreach ($files as $f) {
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            return ['ok' => false, 'error' => 'file_too_large', 'files' => []];
        }
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] <= 0) {
            return ['ok' => false, 'error' => 'upload_failed', 'files' => []];
        }
        // The EFFECTIVE cap, not the product's aspirational one: on a shared host
        // PHP's upload_max_filesize is lower, and checking the larger number here
        // would accept a file the server has already refused to receive.
        if ($f['size'] > support_max_attachment_bytes()) {
            return ['ok' => false, 'error' => 'file_too_large', 'files' => []];
        }
        if (!support_attachment_allowed(support_detect_mime($f['tmp_name']))) {
            return ['ok' => false, 'error' => 'file_type_not_allowed', 'files' => []];
        }
    }
    return ['ok' => true, 'error' => '', 'files' => $files];
}

/**
 * Move validated files under storage/ and return the rows to insert.
 *
 * Throws on any failure so the caller can roll the message back with it, and
 * reports rows through $moved so already-moved files can be unlinked.
 *
 * @param array<int, array> $moved filled by reference with what reached the disk
 * @return array<int, array> rows ready for support_attachments
 */
function support_store_files(array $files, int $ticketId, int $messageId, int $uid, array &$moved = []): array
{
    if (!$files) {
        return [];
    }

    $dir = dirname(__DIR__) . '/' . SUPPORT_UPLOAD_DIR_REL . '/' . $uid;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('support upload dir not creatable');
    }

    $rows = [];
    foreach ($files as $f) {
        $mime = support_detect_mime($f['tmp_name']);
        $ext  = support_attachment_ext($mime);
        if ($ext === null) {
            throw new RuntimeException('mime changed between validate and store');
        }

        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $path   = $dir . '/' . $stored;
        if (!move_uploaded_file($f['tmp_name'], $path)) {
            throw new RuntimeException('move_uploaded_file failed');
        }
        $moved[] = ['disk_path' => $path];

        $width = $height = null;
        if (support_attachment_is_image($mime)) {
            $info = @getimagesize($path);
            if (is_array($info)) {
                $width  = (int)$info[0];
                $height = (int)$info[1];
            }
        }

        $rows[] = [
            'ticket_id'     => $ticketId,
            'message_id'    => $messageId,
            'user_id'       => $uid,
            'original_name' => support_attachment_name_clean($f['name']),
            'stored_path'   => SUPPORT_UPLOAD_DIR_REL . '/' . $uid . '/' . $stored,
            'mime_type'     => $mime,
            'file_size'     => (int)$f['size'],
            'width'         => $width,
            'height'        => $height,
        ];
    }
    return $rows;
}

/** Insert the attachment rows and return them with ids, ready for the response. */
function support_insert_attachments(PDO $pdo, array $rows): array
{
    if (!$rows) {
        return [];
    }
    $stmt = $pdo->prepare(
        'INSERT INTO support_attachments
           (ticket_id, message_id, user_id, original_name, stored_path, mime_type, file_size, width, height, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $out = [];
    foreach ($rows as $r) {
        $stmt->execute([
            $r['ticket_id'], $r['message_id'], $r['user_id'], $r['original_name'],
            $r['stored_path'], $r['mime_type'], $r['file_size'], $r['width'], $r['height'],
        ]);
        $out[] = $r + ['id' => (int)$pdo->lastInsertId()];
    }
    return $out;
}

/** Best-effort removal of the files a failed request had already moved. */
function support_unlink_moved(array $moved): void
{
    foreach ($moved as $m) {
        if (!empty($m['disk_path'])) {
            @unlink($m['disk_path']);
        }
    }
}
