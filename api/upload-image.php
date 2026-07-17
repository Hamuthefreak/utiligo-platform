<?php
/**
 * api/upload-image.php — Handles drag-and-drop image uploads used to
 * replace stock photos in a generated site (hero, about, gallery images).
 * Pro feature. Accepts multipart/form-data with a single 'image' file plus
 * 'slot' (hero|about|gallery) and optional 'site_id' to attach the upload
 * to a specific generated site record for later reference/cleanup.
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
    json_response(['success' => false, 'error' => 'Image uploads are a Pro feature.'], 403);
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    json_response(['success' => false, 'error' => 'Invalid session.'], 403);
}

if (!rate_limit_check('upload_image', defined('RATE_LIMIT_UPLOAD_IMAGE') ? RATE_LIMIT_UPLOAD_IMAGE : 30)) {
    json_response(['success' => false, 'error' => 'Too many uploads. Please wait a moment.'], 429);
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = $errCode === UPLOAD_ERR_INI_SIZE ? 'Image is too large.' : 'No image uploaded or upload failed.';
    json_response(['success' => false, 'error' => $msg], 400);
}

$file = $_FILES['image'];
$maxBytes = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $maxBytes) {
    json_response(['success' => false, 'error' => 'Image must be under 5MB.'], 400);
}

$allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!isset($allowedMimes[$detectedMime])) {
    json_response(['success' => false, 'error' => 'Only JPG, PNG, WEBP, and GIF images are allowed.'], 400);
}

$slot = preg_replace('/[^a-z_]/', '', $_POST['slot'] ?? 'custom');
$siteId = !empty($_POST['site_id']) ? (int)$_POST['site_id'] : null;

$uploadDir = __DIR__ . '/../assets/uploads/user_images/' . $user['id'];
if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
    json_response(['success' => false, 'error' => 'Could not create upload directory.'], 500);
}

$ext = $allowedMimes[$detectedMime];
$filename = $slot . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$destPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    json_response(['success' => false, 'error' => 'Failed to save uploaded image.'], 500);
}

$relativePath = '/assets/uploads/user_images/' . $user['id'] . '/' . $filename;

if ($siteId) {
    $pdo = get_platform_db();
    $stmt = $pdo->prepare('SELECT id FROM utiligo_generated_sites WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$siteId, $user['id']]);
    if ($stmt->fetch()) {
        $pdo->prepare(
            'INSERT INTO utiligo_site_uploads (site_id, user_id, file_path, original_name, mime_type, file_size) VALUES (?,?,?,?,?,?)'
        )->execute([$siteId, $user['id'], $relativePath, $file['name'], $detectedMime, $file['size']]);
    }
}

json_response([
    'success' => true,
    'slot' => $slot,
    'url' => $relativePath,
]);
