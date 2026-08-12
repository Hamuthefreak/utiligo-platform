<?php
/**
 * api/download-export.php
 *
 * Phase 4 signed-token download endpoint for lead exports.
 *
 * GET ?token=<64-char hex token>
 *
 * Verifies:
 *   - token belongs to an existing, status='ready' row in lead_exports
 *   - created_at > expires_at? skip — we use expires_at as a strict cutoff
 *   - download_count < MAX_DOWNLOADS (5)
 *   - caller is the user_id the export was issued for (IDOR guard)
 *
 * All storage/* HTTP requests are forbidden by .htaccess, so direct
 * file URLs cannot serve the export even if leaked.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

api_bootstrap();

if (!is_logged_in()) { _dl_fail('Not logged in', 401); }
$user = current_user();
$uid  = (int)$user['id'];

$token = isset($_GET['token']) ? preg_replace('/[^a-f0-9]/', '', strtolower((string)$_GET['token'])) : '';
if (strlen($token) !== 64) { _dl_fail('Invalid token'); }

$pdo = get_platform_db();
try {
    $stmt = $pdo->prepare(
        'SELECT * FROM lead_exports WHERE token = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$token, $uid]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    log_error('download_export_lookup', $e, ['uid'=>$uid]);
    _dl_fail('Export not found');
}

if (!$job) { _dl_fail('Export not found', 404); }
if ($job['status'] !== 'ready') { _dl_fail('Export not ready yet', 409); }
if (!empty($job['expires_at']) && strtotime($job['expires_at']) < time()) {
    _dl_fail('Export link has expired (link valid for 1 hour)', 410);
}
$dlcount = (int)$job['download_count'];
if ($dlcount >= 5) { _dl_fail('Max downloads for this link reached (5/5)', 410); }

$file = $job['file_path'] ?? '';
if (!is_string($file) || $file === '' || !is_file($file)) {
    _dl_fail('Export file missing on server', 410);
}

$format = (string)$job['format'];
// Determine content-type & filename.
switch ($format) {
    case 'csv':
        $ct = 'text/csv; charset=utf-8';       $ext = 'csv';   break;
    case 'json':
        $ct = 'application/json; charset=utf-8';$ext = 'json';  break;
    case 'vcard':
        $ct = 'text/vcard; charset=utf-8';     $ext = 'vcf';   break;
    case 'xlsx':
        $ct = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $ext = 'xlsx'; break;
    case 'pdf':
        // PDF can be HTML-in-disguise when DOMPDF isn't installed.
        $real_pdf = strncasecmp(file_get_contents($file, false, null, 0, 4), '%PDF', 4) === 0;
        $ct = $real_pdf ? 'application/pdf' : 'text/html; charset=utf-8';
        $ext = $real_pdf ? 'pdf' : 'html';
        // Filename hint: real .pdf vs print-friendly .html, but always ends .pdf
        // so downloaded URLs look consistent. Browsers will render the HTML.
        $ext = 'pdf';
        break;
    default:
        $ct = 'application/octet-stream';       $ext = 'bin';
}
$base = 'utiligo_leads_' . date('Ymd_His', strtotime($job['created_at']));
$dispname = $base . '.' . $ext;

// Increment download count BEFORE we send headers, so a client retry after
// partial failure still counts as a download.
try {
    $pdo->prepare('UPDATE lead_exports SET download_count = download_count + 1, last_download_at = NOW() WHERE id = ?')
        ->execute([(int)$job['id']]);
} catch (\Throwable $e) { log_error('download_export_inc', $e); }

header('Content-Type: ' . $ct);
header('Content-Length: ' . filesize($file));
header('Content-Disposition: attachment; filename="' . $dispname . '"');
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex');

if ($format === 'pdf' && (stripos($ct, 'text/html') !== false)) {
    // Print-friendly HTML body; .pdf file extension is for display only.
    readfile($file);
} else {
    readfile($file);
}
exit;

function _dl_fail(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}
