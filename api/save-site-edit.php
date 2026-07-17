<?php
/**
 * api/save-site-edit.php — Persists a single edit (or batch of edits) made
 * in the interactive site editor: text content, inline styles (bold,
 * italic, underline, color), background color, or a replaced image. Edits
 * are written directly into the static HTML file on disk (so the exported
 * ZIP and public share link always reflect the latest version) AND stored
 * as structured JSON in utiligo_generated_sites.builder_content so the
 * editor can rebuild its state without re-parsing HTML.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/error_logger.php';

api_bootstrap();

require_login();
header('Content-Type: application/json');

$user = current_user();
if ($user['plan'] !== 'pro') {
    json_response(['success' => false, 'error' => 'The site editor is a Pro feature.'], 403);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!csrf_verify($input['csrf_token'] ?? null)) {
    json_response(['success' => false, 'error' => 'Invalid session.'], 403);
}
if (!rate_limit_check('save_site_page', defined('RATE_LIMIT_SAVE_SITE_PAGE') ? RATE_LIMIT_SAVE_SITE_PAGE : 60)) {
    json_response(['success' => false, 'error' => 'Saving too fast, please slow down.'], 429);
}

$siteId = (int)($input['site_id'] ?? 0);
$page = preg_replace('/[^a-z\-]/', '', $input['page'] ?? 'index');
$edits = $input['edits'] ?? [];

if (!$siteId || !$page || !is_array($edits) || empty($edits)) {
    json_response(['success' => false, 'error' => 'Invalid save request.'], 400);
}

$pdo = get_platform_db();
$stmt = $pdo->prepare('SELECT * FROM utiligo_generated_sites WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$siteId, $user['id']]);
$site = $stmt->fetch();

if (!$site) {
    json_response(['success' => false, 'error' => 'Site not found.'], 404);
}

$slugDir = slugify($site['business_name']) . '-' . $site['id'];
$filePath = __DIR__ . '/../assets/uploads/generated_sites/' . $slugDir . '/' . $page . '.html';

if (!file_exists($filePath)) {
    json_response(['success' => false, 'error' => 'Page not found.'], 404);
}

$html = file_get_contents($filePath);
$dom = new DOMDocument();
$dom->encoding = 'UTF-8';
libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
libxml_clear_errors();
$xpath = new DOMXPath($dom);

$applied = [];
foreach ($edits as $edit) {
    $editId = $edit['edit_id'] ?? null;
    if (!$editId) continue;

    $nodes = $xpath->query("//*[@data-edit-id='" . addslashes($editId) . "']");
    if ($nodes->length === 0) continue;
    $node = $nodes->item(0);

    switch ($edit['type'] ?? '') {
        case 'text':
            // innerHTML replace to preserve simple inline formatting
            // (bold/italic/underline/color spans) applied by the editor toolbar.
            while ($node->firstChild) {
                $node->removeChild($node->firstChild);
            }
            $fragment = $dom->createDocumentFragment();
            @$fragment->appendXML($edit['html'] ?? '');
            if ($fragment->hasChildNodes()) {
                $node->appendChild($fragment);
            } else {
                $node->appendChild($dom->createTextNode($edit['text'] ?? ''));
            }
            $applied[] = $editId;
            break;

        case 'bg_color':
            $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', $edit['color'] ?? '') ? $edit['color'] : null;
            if ($color && $node instanceof DOMElement) {
                $existingStyle = $node->getAttribute('style');
                $existingStyle = preg_replace('/background(-color)?\s*:[^;]+;?/i', '', $existingStyle);
                $node->setAttribute('style', trim($existingStyle) . ';background:' . $color . ';');
                $applied[] = $editId;
            }
            break;

        case 'image':
            $url = $edit['url'] ?? '';
            if ($node instanceof DOMElement && strpos($url, '/assets/uploads/') === 0) {
                $node->setAttribute('src', $url);
                $applied[] = $editId;
            }
            break;
    }
}

$newHtml = $dom->saveHTML();
$newHtml = preg_replace('/^<\?xml[^>]*>\s*/', '', $newHtml);
file_put_contents($filePath, $newHtml);

// Store a lightweight record of applied edits for auditing/state restore.
$existingContent = $site['builder_content'] ? json_decode($site['builder_content'], true) : [];
if (!is_array($existingContent)) $existingContent = [];
$existingContent[$page] = array_merge($existingContent[$page] ?? [], $edits);
if (db_table_has_column($pdo, 'utiligo_generated_sites', 'builder_content')) {
    $pdo->prepare('UPDATE utiligo_generated_sites SET builder_content = ? WHERE id = ?')
        ->execute([json_encode($existingContent), $siteId]);
}

json_response(['success' => true, 'applied' => $applied, 'count' => count($applied)]);
