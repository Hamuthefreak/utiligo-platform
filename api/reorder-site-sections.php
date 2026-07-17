<?php
/**
 * api/reorder-site-sections.php — Persists a new top-level section order
 * for a single page of a generated site, driven by drag-and-drop in the
 * interactive editor. Reorders the actual DOM nodes in the static HTML
 * file on disk so downloads/share links always reflect the new layout.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';

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
if (!rate_limit_check('save_site_page', RATE_LIMIT_SAVE_SITE_PAGE)) {
    json_response(['success' => false, 'error' => 'Saving too fast, please slow down.'], 429);
}

$siteId = (int)($input['site_id'] ?? 0);
$page = preg_replace('/[^a-z\-]/', '', $input['page'] ?? 'index');
$order = $input['order'] ?? [];

if (!$siteId || !$page || !is_array($order) || empty($order)) {
    json_response(['success' => false, 'error' => 'Invalid reorder request.'], 400);
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

$body = $xpath->query('//body')->item(0);
if (!$body) {
    json_response(['success' => false, 'error' => 'Malformed page.'], 500);
}

// Collect the actual section nodes by their data-edit-id, in the order
// requested by the client, then re-insert each one right before the
// closing </body> marker element (the editor script placeholder comment
// or the footer) so nav/footer stay fixed and only the sections move.
$sectionsById = [];
foreach ($xpath->query('//*[@data-sortable-section="1"]') as $sectionNode) {
    $editId = $sectionNode->getAttribute('data-edit-id');
    if ($editId) $sectionsById[$editId] = $sectionNode;
}

$validOrder = array_values(array_filter($order, fn($id) => isset($sectionsById[$id])));
if (count($validOrder) !== count($sectionsById)) {
    json_response(['success' => false, 'error' => 'Order does not match page sections.'], 400);
}

// Find the anchor to insert before (the footer element, which always
// follows the sections in our generated markup).
$footerNodes = $xpath->query('//footer');
$anchor = $footerNodes->length > 0 ? $footerNodes->item(0) : null;

foreach ($validOrder as $editId) {
    $node = $sectionsById[$editId];
    $body->removeChild($node);
}
foreach ($validOrder as $editId) {
    $node = $sectionsById[$editId];
    if ($anchor) {
        $body->insertBefore($node, $anchor);
    } else {
        $body->appendChild($node);
    }
}

$newHtml = $dom->saveHTML();
$newHtml = preg_replace('/^<\?xml[^>]*>\s*/', '', $newHtml);
file_put_contents($filePath, $newHtml);

$existingContent = $site['builder_content'] ? json_decode($site['builder_content'], true) : [];
if (!is_array($existingContent)) $existingContent = [];
$existingContent[$page]['_section_order'] = $validOrder;
if (db_table_has_column($pdo, 'utiligo_generated_sites', 'builder_content')) {
    $pdo->prepare('UPDATE utiligo_generated_sites SET builder_content = ? WHERE id = ?')
        ->execute([json_encode($existingContent), $siteId]);
}

json_response(['success' => true, 'order' => $validOrder]);
