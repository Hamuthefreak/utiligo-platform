<?php
/**
 * api/generate-site.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/plans.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/site_templates.php';
require_once __DIR__ . '/../includes/site_builder.php';

api_bootstrap();
require_login();
header('Content-Type: application/json');

if (!defined('FREE_GENERATE_DAILY_LIMIT')) define('FREE_GENERATE_DAILY_LIMIT', 1);
if (!defined('FREE_TEMPLATE_LIMIT'))       define('FREE_TEMPLATE_LIMIT', 2);

$user   = current_user();
$is_pro = ($user['plan'] ?? 'free') === 'pro';

$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (!csrf_verify($input['csrf_token'] ?? null)) {
    json_response(['success' => false, 'error' => 'Invalid session.'], 403);
}
if (!rate_limit_check('generate_site', RATE_LIMIT_GENERATE_SITE)) {
    json_response(['success' => false, 'error' => 'Too many generations. Please wait a moment.'], 429);
}

// Free user daily quota check
if (!$is_pro) {
    $pdo    = get_platform_db();
    $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
    $qstmt  = $pdo->prepare('SELECT COUNT(*) FROM utiligo_generated_sites WHERE user_id = ? AND created_at > ?');
    $qstmt->execute([$user['id'], $cutoff]);
    $usedToday = (int)$qstmt->fetchColumn();
    if ($usedToday >= FREE_GENERATE_DAILY_LIMIT) {
        json_response(['success' => false, 'error' => 'Daily generation limit reached. Upgrade to Pro for unlimited generations.'], 403);
    }
}

$businessName      = sanitize($input['business_name'] ?? '');
$category          = sanitize($input['business_category'] ?? '');
$city              = sanitize($input['business_city'] ?? '');
$phone             = sanitize($input['business_phone'] ?? '');
$email             = sanitize($input['business_email'] ?? '');
$requestedTemplate = $input['template_name'] ?? 'modern';
$allTemplates      = get_all_site_templates();

// Free users restricted to first N templates
if (!$is_pro) {
    $freeKeys = array_slice(array_keys($allTemplates), 0, FREE_TEMPLATE_LIMIT);
    if (!in_array($requestedTemplate, $freeKeys, true)) {
        $requestedTemplate = $freeKeys[0];
    }
}
$template = array_key_exists($requestedTemplate, $allTemplates) ? $requestedTemplate : 'modern';
$leadId   = !empty($input['lead_id']) ? (int)$input['lead_id'] : null;

function validate_upload_path(?string $path): ?string {
    if (!$path) return null;
    return (strpos($path, '/assets/uploads/user_images/') === 0) ? $path : null;
}

$customImages = [];
$heroImg = validate_upload_path($input['custom_image_hero'] ?? null);
if ($heroImg) $customImages['hero'] = $heroImg;
$aboutImg = validate_upload_path($input['custom_image_about'] ?? null);
if ($aboutImg) $customImages['about'] = $aboutImg;
if (!empty($input['custom_images_gallery'])) {
    $galleryRaw = is_string($input['custom_images_gallery'])
        ? json_decode($input['custom_images_gallery'], true)
        : $input['custom_images_gallery'];
    if (is_array($galleryRaw)) {
        $validGallery = array_values(array_filter(array_map('validate_upload_path', $galleryRaw)));
        if (!empty($validGallery)) $customImages['gallery'] = $validGallery;
    }
}

if (!$businessName || !$category || !$city) {
    json_response(['success' => false, 'error' => 'Business name, category, and city are required.'], 400);
}

if (!isset($pdo)) $pdo = get_platform_db();

// Use db_table_has_column so this works even if migration hasn't been run yet
$hasCategoryCol = db_table_has_column($pdo, 'utiligo_generated_sites', 'business_category');
$hasCityCol     = db_table_has_column($pdo, 'utiligo_generated_sites', 'business_city');
$hasPhoneCol    = db_table_has_column($pdo, 'utiligo_generated_sites', 'business_phone');
$hasEmailCol    = db_table_has_column($pdo, 'utiligo_generated_sites', 'business_email');

if ($hasCategoryCol && $hasCityCol && $hasPhoneCol && $hasEmailCol) {
    $stmt = $pdo->prepare('INSERT INTO utiligo_generated_sites (user_id, lead_id, business_name, business_category, business_city, business_phone, business_email, template_name, status) VALUES (?,?,?,?,?,?,?,?,"pending")');
    $stmt->execute([$user['id'], $leadId, $businessName, $category, $city, $phone, $email, $template]);
} else {
    $stmt = $pdo->prepare('INSERT INTO utiligo_generated_sites (user_id, lead_id, business_name, template_name, status) VALUES (?,?,?,?,"pending")');
    $stmt->execute([$user['id'], $leadId, $businessName, $template]);
}
$siteId = (int)$pdo->lastInsertId();

$slug    = slugify($businessName) . '-' . $siteId;
$siteDir = __DIR__ . '/../assets/uploads/generated_sites/' . $slug;
@mkdir($siteDir, 0755, true);

$business = ['name'=>$businessName,'category'=>$category,'city'=>$city,'phone'=>$phone,'email'=>$email];
$pages    = build_site_pages($business, $template, $customImages);
foreach ($pages as $filename => $html) {
    file_put_contents($siteDir . '/' . $filename, $html);
}

$zipFilename = $slug . '.zip';
$zipPath     = __DIR__ . '/../exports/' . $zipFilename;
$zipped      = generate_zip($siteDir, $zipPath);

if (!$zipped) {
    $pdo->prepare('UPDATE utiligo_generated_sites SET status="failed" WHERE id=?')->execute([$siteId]);
    json_response(['success' => false, 'error' => 'Failed to package website ZIP.'], 500);
}

$relativeZipPath = '/exports/' . $zipFilename;
$previewUrl      = '/assets/uploads/generated_sites/' . $slug . '/index.html';
$hasShareCols    = db_table_has_column($pdo, 'utiligo_generated_sites', 'public_slug');

$publicSlug = $publicUrl = $linkExpiresAt = null;
if ($hasShareCols) {
    $publicSlug    = $slug;
    $linkExpiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    $publicUrl     = '/s/' . $publicSlug;
    $pdo->prepare('UPDATE utiligo_generated_sites SET status="completed",zip_file_path=?,public_slug=?,link_expires_at=?,link_active=1 WHERE id=?')
        ->execute([$relativeZipPath, $publicSlug, $linkExpiresAt, $siteId]);
} else {
    $pdo->prepare('UPDATE utiligo_generated_sites SET status="completed",zip_file_path=? WHERE id=?')
        ->execute([$relativeZipPath, $siteId]);
}

if ($leadId) {
    $pdo->prepare('UPDATE utiligo_leads SET status="contacted" WHERE id=?')->execute([$leadId]);
}

json_response(['success'=>true,'zip_url'=>$relativeZipPath,'preview_url'=>$previewUrl,'public_url'=>$publicUrl,'link_expires_at'=>$linkExpiresAt,'site_id'=>$siteId,'page_count'=>count($pages),'share_links_enabled'=>$hasShareCols]);
