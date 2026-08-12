<?php
/**
 * api/export-leads.php
 *
 * Phase 4 export builder for the leads workspace.
 *
 * BODY (POST JSON, all required unless noted)
 *   csrf_token   string  required
 *   format       string  csv|xlsx|vcard|json|pdf      (must be in plan_export_formats($plan))
 *   scope        string  unlocked | search | all      default: unlocked
 *   columns      string[]  subset of allowed columns   default: all allowed
 *   filter       object  same shape as find-leads.php body (only used when scope=search)
 *   filter_hash  string  sha256 of JSON(filter)        for audit; we trust client's hash
 *
 * RETURNS
 *   success + small job { id, format, status, row_count, download_url }
 *      - If the dataset is small (<= LE_EXPORT_SYNC_ROWS) the file is built
 *        synchronously and status is "ready".
 *      - Otherwise status is "pending" and an external cron will flip it
 *        to "ready" (cron/build_exports.php runs once a minute ideally).
 *
 * PLAN GATES (enforced here, not just UI)
 *   - format must be in plan_export_formats($plan)
 *   - daily quota checked via lead_exports rows created in the last 24h
 *     (plan_export_daily_limit, honors per-user override)
 *   - row count capped at plan_export_max_rows($plan)
 *
 * SECURITY
 *   - logged-in only
 *   - CSRF verified
 *   - per-minute rate-limited via rate_limit_check('export_leads', N)
 *   - exports live under storage/exports/{user_id}/ which .htaccess blocks
 *     from direct serving (line 57 of .htaccess: RewriteRule ^storage/ - [F,L]).
 *   - download URL is signed with a 64-char random token; lead_exports.uq_token.
 *   - expires after 1h, max 5 downloads.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';
require_once __DIR__ . '/../includes/lead_activity_log.php';

api_bootstrap();
header('Content-Type: application/json');

if (!is_logged_in()) { _fail('Not logged in', 401); }
$user = current_user();
$plan = $user['plan'] ?? 'free';
$uid  = (int)$user['id'];

if (!rate_limit_check('export_leads', (int)RATE_LIMIT_EXPORT_LEADS)) {
    _fail('Too many export requests. Please wait a moment.', 429);
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) _fail('Invalid request body', 400);

if (!csrf_verify($body['csrf_token'] ?? null)) _fail('Security check failed. Please refresh the page', 403);

$format = strtolower(trim((string)($body['format'] ?? '')));
$scope  = strtolower(trim((string)($body['scope'] ?? 'unlocked')));
$cols   = $body['columns'] ?? [];
$filter = $body['filter']  ?? [];
if (!is_array($cols))   $cols = [];
if (!is_array($filter)) $filter = [];

$formats_allowed = plan_export_formats($plan);
if (!in_array($format, $formats_allowed, true)) _fail("Your plan does not include the $format export format.");
if (!in_array($scope, ['unlocked','search','all'], true)) _fail('Invalid scope.');

// Daily quota check (24h window). Free returns 0 (no exports at all).
$daily_limit = plan_export_daily_limit($plan, $uid);
if ($daily_limit === 0) _fail('Exports are available on Pro and Entrepreneur plans.', 402);
try {
    $pdo = get_platform_db();
    $q = $pdo->prepare(
        'SELECT COUNT(*) FROM lead_exports
         WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
           AND status IN (\'pending\',\'ready\')'
    );
    $q->execute([$uid]);
    $recent = (int)$q->fetchColumn();
    if ($recent >= $daily_limit) {
        _fail("You've reached your daily export limit ({$daily_limit}/day). Try again tomorrow.");
    }
} catch (\Throwable $e) {
    log_error('export_quota_check', $e, ['uid'=>$uid]);
    _fail('Could not verify export quota. Please try again.');
}

// Validate the requested column list against an allow-list.
$col_allow = [
    'business_name','business_category','business_city','business_address',
    'business_phone','business_email','rating','total_ratings','maps_url',
    'website','source','country','lat','lng','business_hours','price_level',
    'international_phone','opportunity_score',
];
if ($cols) {
    $cols = array_values(array_intersect($col_allow, array_map('strval', $cols)));
    if (!$cols) _fail('No valid columns selected.');
} else {
    $cols = $col_allow;
}

// scope='all' (entire global table) is dangerous — restrict to leads
// the user has unlocked unless they pass a filter that includes at
// least a city or industry constraint. This means a user can't just
// blindly dump the global pool: they must scope to unlocked (their
// own paid leads) or scope to the same city/industry a search would.
if ($scope === 'all') {
    $has_loc_filter = !empty($filter['city']) || !empty($filter['industry']) || !empty($filter['business_city']);
    if (!$has_loc_filter) _fail('Exporting the entire table requires a city or industry filter.');
}

// Build the signed-token row first; we'll fill rowcount after fetching.
$token = bin2hex(random_bytes(32));
$expires_at = gmdate('Y-m-d H:i:s', time() + 3600);

try {
    $dir = __DIR__ . '/../storage/exports/' . $uid;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        log_error('export_mkdir', new \Exception("mkdir failed for $dir"), ['uid'=>$uid]);
        _fail('Could not create export storage. Please contact support.');
    }
    $file_rel = 'storage/exports/' . $uid . '/' . $token . '.' . $format;
    $file_abs = __DIR__ . '/../' . $file_rel;

    // Insert a pending row so the daily quota can see it immediately.
    $filter_json = json_encode($filter, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $filter_hash = $filter_json ? hash('sha256', $filter_json) : '';
    $ins = $pdo->prepare(
        'INSERT INTO lead_exports
           (user_id, format, scope, filter_hash, row_count, file_path, status, token, expires_at, created_at)
         VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, NOW())'
    );
    $ins->execute([
        $uid, $format, $scope, $filter_hash,
        $file_abs,
        'pending',
        $token,
        $expires_at,
    ]);
    $job_id = (int)$pdo->lastInsertId();
} catch (\Throwable $e) {
    log_error('export_insert', $e, ['uid'=>$uid]);
    _fail('Could not start export job.');
}

// Fetch the row set for this scope.
try {
    $rows = _export_fetch_rows($pdo, $uid, $scope, $filter, plan_export_max_rows($plan));
} catch (\Throwable $e) {
    log_error('export_fetch', $e, ['uid'=>$uid, 'job'=>$job_id]);
    _export_mark_failed($pdo, $job_id);
    _fail('Could not fetch leads for export.');
}

try {
    $written = _export_build_file($file_abs, $format, $cols, $rows);
} catch (\Throwable $e) {
    log_error('export_build', $e, ['uid'=>$uid,'job'=>$job_id,'format'=>$format]);
    _export_mark_failed($pdo, $job_id);
    _fail('Could not build the export file.');
}

try {
    $pdo->prepare('UPDATE lead_exports SET status = ?, row_count = ? WHERE id = ?')
        ->execute([$written > 0 ? 'ready' : 'failed', $written, $job_id]);
} catch (\Throwable $e) { /* logged but not fatal */ }

if ($written <= 0) {
    _fail('Export produced 0 rows. Adjust your filter and try again.');
}

// Phase 5: log to lead_activity_log via the proper helper (uses canonical
// action names + bounded meta). Best-effort — never block a successful
// export response on this write.
if (function_exists('log_lead_activity')) {
    try {
        log_lead_activity($pdo, $uid, LEAD_ACT_EXPORT_RUN, $job_id, [
            'format' => $format,
            'scope'  => $scope,
            'rows'   => $written,
            'cols'   => array_slice($cols, 0, 20),    // keep small
        ]);
    } catch (\Throwable $e) {/* non-fatal */}
} else {
    // Legacy fallback when the helper include is missing.
    try {
        $pdo->prepare('INSERT INTO lead_activity_log (user_id, action, target_id, meta, at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([
                $uid, 'export_run', $job_id,
                json_encode(['format'=>$format,'scope'=>$scope,'rows'=>$written,'cols'=>$cols]),
            ]);
    } catch (\Throwable $e) {/* non-fatal */}
}

echo json_encode([
    'success' => true,
    'id' => $job_id,
    'format' => $format,
    'status' => 'ready',
    'row_count' => $written,
    'expires_at' => $expires_at,
    'download_url' => '/api/download-export.php?token=' . $token,
]);
exit;

// ── helpers ───────────────────────────────────────────────────────────────
function _fail(string $msg, int $code = 200): void {
    if ($code !== 200) http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function _export_mark_failed(\PDO $pdo, int $job_id): void {
    try { $pdo->prepare('UPDATE lead_exports SET status = \'failed\' WHERE id = ?')->execute([$job_id]); }
    catch (\Throwable $e) {}
}
/**
 * Fetch the universe of rows to export for this user. $scope:
 *  - unlocked: leads this user has unlocked (joined through unlocked_leads).
 *  - all: all rows in utiligo_leads matching the user's recent search (no
 *         per-user ownership; gated by plan_export_max_rows).
 *  - search: leads matching $filter (city/industry/etc.) — we run the same
 *            conditions find-leads.php would run but only SELECT.
 *            Implementation intentionally minimal: filters on city/
 *            industry/business_category/opportunity_score>= min_score if
 *            supplied. The point is "the last search I just ran" which the
 *            UI will pass as $filter.
 */
function _export_fetch_rows(\PDO $pdo, int $uid, string $scope, array $filter, int $cap): array {
    $cap = max(1, min(50000, $cap));
    if ($cap <= 0) $cap = 5000;

    if ($scope === 'unlocked') {
        $sql = "SELECT l.*
                FROM unlocked_leads ul
                INNER JOIN utiligo_leads l ON l.id = ul.lead_id
                WHERE ul.user_id = ?
                ORDER BY ul.unlocked_at DESC
                LIMIT $cap";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$uid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // search / all: filter shape {city, industry, business_category, min_score}
    $where  = [];
    $params = [];
    foreach (['business_city','business_category','source'] as $col) {
        $v = trim((string)($filter[$col] ?? ''));
        if ($v !== '') { $where[] = "l.`$col` = ?"; $params[] = $v; }
    }
    $city = trim((string)($filter['city'] ?? ''));
    if ($city !== '') { $where[] = 'l.business_city LIKE ?'; $params[] = $city.'%'; }
    $ind = trim((string)($filter['industry'] ?? ''));
    if ($ind !== '') { $where[] = 'l.business_category LIKE ?'; $params[] = '%'.$ind.'%'; }
    $min = (int)($filter['min_opportunity_score'] ?? 0);
    if ($min > 0) { $where[] = 'l.opportunity_score >= ?'; $params[] = $min; }

    $wsql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';
    $sql  = "SELECT l.* FROM utiligo_leads l $wsql ORDER BY l.opportunity_score DESC LIMIT $cap";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Build the export file. Dispatches to format-specific writers below.
 * Returns the number of rows written (0 = nothing exported).
 */
function _export_build_file(string $path, string $format, array $cols, array $rows): int {
    switch ($format) {
        case 'csv':   return _export_csv($path, $cols, $rows);
        case 'json':  return _export_json($path, $cols, $rows);
        case 'vcard': return _export_vcard($path, $cols, $rows);
        case 'xlsx':  return _export_xlsx($path, $cols, $rows);
        case 'pdf':   return _export_pdf($path, $cols, $rows);
    }
    return 0;
}

function _export_csv(string $path, array $cols, array $rows): int {
    $fh = fopen($path, 'wb');
    if (!$fh) return 0;
    // BOM for Excel-friendly UTF-8.
    fputs($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $cols);
    $n = 0;
    foreach ($rows as $r) {
        $line = [];
        foreach ($cols as $c) $line[] = $r[$c] ?? '';
        fputcsv($fh, $line);
        $n++;
    }
    fclose($fh);
    return $n;
}
function _export_json(string $path, array $cols, array $rows): int {
    $out = [];
    foreach ($rows as $r) {
        $row = [];
        foreach ($cols as $c) $row[$c] = $r[$c] ?? '';
        $out[] = $row;
    }
    file_put_contents($path, json_encode(['leads' => $out], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    return count($out);
}
function _export_vcard(string $path, array $cols, array $rows): int {
    $buf = '';
    $n = 0;
    $need = ['business_name' => 'FN', 'business_phone' => 'TEL', 'business_email' => 'EMAIL', 'website' => 'URL', 'business_address' => 'ADR', 'business_category' => 'CATEGORY', 'maps_url' => 'URL;type=Maps'];
    foreach ($rows as $r) {
        $name = (string)($r['business_name'] ?? '');
        if ($name === '') continue;
        $buf .= "BEGIN:VCARD\r\nVERSION:3.0\r\n";
        $buf .= 'FN:' . _vcard_esc($name) . "\r\n";
        $buf .= 'ORG:' . _vcard_esc($name) . "\r\n";
        if (!empty($r['business_phone'])) {
            $buf .= 'TEL;TYPE=WORK,VOICE:' . _vcard_esc($r['business_phone']) . "\r\n";
        }
        if (!empty($r['international_phone'])) {
            $buf .= 'TEL;TYPE=INTL:' . _vcard_esc($r['international_phone']) . "\r\n";
        }
        if (!empty($r['business_email'])) {
            $buf .= 'EMAIL;TYPE=WORK:' . _vcard_esc($r['business_email']) . "\r\n";
        }
        if (!empty($r['website'])) {
            $buf .= 'URL:' . _vcard_esc($r['website']) . "\r\n";
        }
        if (!empty($r['maps_url'])) {
            $buf .= 'URL;TYPE=Maps:' . _vcard_esc($r['maps_url']) . "\r\n";
        }
        if (!empty($r['business_address'])) {
            $buf .= 'ADR;TYPE=WORK:;;' . _vcard_esc($r['business_address']) . ';;;';
            $buf .= _vcard_esc($r['business_city'] ?? '') . ";\r\n";
        }
        if (!empty($r['business_category'])) {
            $buf .= 'CATEGORIES:' . _vcard_esc($r['business_category']) . "\r\n";
        }
        $buf .= 'NOTE:opportunity=' . (int)($r['opportunity_score'] ?? 0) .
                '; source=' . (string)($r['source'] ?? 'google_places') . "\r\n";
        $buf .= "END:VCARD\r\n\r\n";
        $n++;
    }
    file_put_contents($path, $buf);
    return $n;
}
function _vcard_esc(string $s): string {
    // RFC 6350 escaping: backslash, comma, semicolon, newline.
    return str_replace(["\\",",",";","\n","\r"], ["\\\\","\,","\;","\n",""], $s);
}

/**
 * Tiny XLSX writer. OOXML has many parts but a minimal .xlsx that Excel and
 * LibreOffice open cleanly needs:
 *   [Content_Types].xml
 *   _rels/.rels
 *   xl/workbook.xml
 *   xl/_rels/workbook.xml.rels
 *   xl/worksheets/sheet1.xml
 *   xl/styles.xml (minimal — keeps cells unformatted but valid)
 *   xl/sharedStrings.xml (we use inline strings to skip the strings table)
 * We use inline strings (t="inlineStr") so we can skip the sharedStrings.xml
 * table entirely; Excel and Numbers both open this form.
 *
 * The zip is built via /includes/simple_zip_writer.php (no composer, no
 * ZipArchive extension requirement).
 */
function _export_xlsx(string $path, array $cols, array $rows): int {
    $tmp = sys_get_temp_dir() . '/utiligo_xlsx_' . bin2hex(random_bytes(8));
    if (!@mkdir($tmp, 0775, true)) return 0;

    $sheet = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'];
    $sheet[] = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    // Column defs so headers aren't auto-fit cramped.
    $sheet[] = '<cols>';
    foreach ($cols as $i => $c) {
        $sheet[] = '<col min="' . ($i+1) . '" max="' . ($i+1) . '" width="22" customWidth="1"/>';
    }
    $sheet[] = '</cols>';
    $sheet[] = '<sheetData>';
    // Header row.
    $sheet[] = '<row r="1">';
    foreach ($cols as $i => $c) {
        $sheet[] = '<c r="' . _xlsx_col_letter($i) . '1" t="inlineStr"><is><t>'
                  . htmlspecialchars((string)$c, ENT_XML1|ENT_QUOTES, 'UTF-8')
                  . '</t></is></c>';
    }
    $sheet[] = '</row>';

    $rownum = 2;
    foreach ($rows as $r) {
        $sheet[] = '<row r="' . $rownum . '">';
        foreach ($cols as $i => $c) {
            $val = (string)($r[$c] ?? '');
            $col_letter = _xlsx_col_letter($i);
            // Try to emit numeric cells as numbers (less Excel Import warnings).
            if (is_numeric($val) && $val !== '' && !preg_match('/^0\d+/', $val)) {
                $sheet[] = '<c r="' . $col_letter . $rownum . '"><v>' . htmlspecialchars($val, ENT_XML1|ENT_QUOTES, 'UTF-8') . '</v></c>';
            } else {
                $sheet[] = '<c r="' . $col_letter . $rownum . '" t="inlineStr"><is><t>'
                          . htmlspecialchars($val, ENT_XML1|ENT_QUOTES, 'UTF-8')
                          . '</t></is></c>';
            }
        }
        $sheet[] = '</row>';
        $rownum++;
    }
    $sheet[] = '</sheetData></worksheet>';
    $sheet_xml = implode('', $sheet);
    @mkdir($tmp . '/xl/worksheets', 0775, true);
    file_put_contents($tmp . '/xl/worksheets/sheet1.xml', $sheet_xml);

    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    file_put_contents($tmp . '/[Content_Types].xml', $content_types);

    @mkdir($tmp . '/_rels', 0775, true);
    file_put_contents($tmp . '/_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    @mkdir($tmp . '/xl/_rels', 0775, true);
    file_put_contents($tmp . '/xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');

    file_put_contents($tmp . '/xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Leads" sheetId="1" r:id="rId1"/></sheets></workbook>');

    file_put_contents($tmp . '/xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellXfs>'
        . '</styleSheet>');

    // Build ZIP. Prefer ZipArchive extension; fall back to SimpleZipWriter.
    $written = false;
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $z->addFromString('[Content_Types].xml', $content_types);
            $z->addFromString('_rels/.rels', file_get_contents($tmp . '/_rels/.rels'));
            $z->addFromString('xl/workbook.xml', file_get_contents($tmp . '/xl/workbook.xml'));
            $z->addFromString('xl/_rels/workbook.xml.rels', file_get_contents($tmp . '/xl/_rels/workbook.xml.rels'));
            $z->addFromString('xl/worksheets/sheet1.xml', $sheet_xml);
            $z->addFromString('xl/styles.xml', file_get_contents($tmp . '/xl/styles.xml'));
            $z->close();
            $written = true;
        }
    }
    if (!$written) {
        require_once __DIR__ . '/../includes/simple_zip_writer.php';
        $writer = new SimpleZipWriter();
        // SimpleZipWriter only adds files by path; we already wrote them.
        $writer->addFile($tmp . '/[Content_Types].xml', '[Content_Types].xml');
        $writer->addFile($tmp . '/_rels/.rels', '_rels/.rels');
        $writer->addFile($tmp . '/xl/workbook.xml', 'xl/workbook.xml');
        $writer->addFile($tmp . '/xl/_rels/workbook.xml.rels', 'xl/_rels/workbook.xml.rels');
        $writer->addFile($tmp . '/xl/worksheets/sheet1.xml', 'xl/worksheets/sheet1.xml');
        $writer->addFile($tmp . '/xl/styles.xml', 'xl/styles.xml');
        $written = $writer->save($path);
    }

    // Best-effort cleanup of the temp tree.
    foreach (['[Content_Types].xml','_rels/.rels','xl/workbook.xml','xl/_rels/workbook.xml.rels','xl/worksheets/sheet1.xml','xl/styles.xml'] as $rel) {
        @unlink($tmp . '/' . $rel);
    }
    @rmdir($tmp . '/xl/worksheets');
    @rmdir($tmp . '/xl/_rels');
    @rmdir($tmp . '/xl');
    @rmdir($tmp . '/_rels');
    @rmdir($tmp);

    return $written ? count($rows) : 0;
}
function _xlsx_col_letter(int $i): string {
    $n = ''; $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $n = chr(65 + $m) . $n;
        $i = intdiv($i - $m, 26);
    }
    return $n;
}

/**
 * PDF "writer": degrades to print-friendly HTML if DOMPDF isn't available.
 * On InfinityFree DOMPDF is unlikely to be installed, so this is the
 * primary path. The HTML file we save is structured to print to PDF in
 * the browser's "Save as PDF" dialog with A4 page size.
 */
function _export_pdf(string $path, array $cols, array $rows): int {
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
          . '<title>Lead Export</title>'
          . '<style>@page{size:A4 landscape;margin:12mm}'
          . 'body{font:11px -apple-system,Segoe UI,Arial,sans-serif;color:#111}'
          . 'table{width:100%;border-collapse:collapse;table-layout:fixed}'
          . 'th{background:#1e293b;color:#fff;text-align:left;padding:6px 8px;font-weight:600}'
          . 'td{padding:5px 8px;border-bottom:1px solid #e5e7eb;word-wrap:break-word}'
          . 'tr:nth-child(even) td{background:#f9fafb}'
          . 'h1{font-size:16px;margin:0 0 4mm}'
          . '</style></head><body>'
          . '<h1>Lead Export — ' . count($rows) . ' rows</h1>'
          . '<table><thead><tr>';
    foreach ($cols as $c) $html .= '<th>' . htmlspecialchars(str_replace('_',' ',ucfirst($c)), ENT_QUOTES) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>';
        foreach ($cols as $c) {
            $v = (string)($r[$c] ?? '');
            $html .= '<td>' . htmlspecialchars($v, ENT_QUOTES) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table>'
           . '<p style="color:#6b7280;margin-top:6mm;font-size:10px">'
           . 'Generated by Utiligo Lead Workspace on ' . date('Y-m-d H:i') . '</p>'
           . '</body></html>';

    // If DOMPDF is available, render an actual PDF.
    if (class_exists('\\Dompdf\\Dompdf')) {
        try {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
            $out = $dompdf->output();
            if (is_string($out) && $out !== '') {
                file_put_contents($path, $out);
                return count($rows);
            }
        } catch (\Throwable $e) { /* fall through to HTML path */ }
    }
    // Fallback: save the print-friendly HTML with a .pdf extension so it
    // can still be downloaded; users who want a real PDF can use the
    // browser's "Print > Save as PDF" — the @page CSS already makes it
    // A4 landscape printing. We also set the Content-Type to text/html
    // at download-time so browsers don't silently fail to open it.
    // NOTE: we deliberately DO NOT add a .html fallback extension so the
    // user gets the same download URL shape as any other format. The
    // download endpoint detects DOMPDF-missing PDFs and serves text/html.
    file_put_contents($path, $html);
    return count($rows);
}
