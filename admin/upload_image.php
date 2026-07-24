<?php
/**
 * admin/upload_image.php
 * Accepts a POST'd image file, saves to /storage/email_uploads/, returns JSON {url}.
 * Only accessible to admins.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file    = $_FILES['file'];
$allowed = ['image/jpeg','image/png','image/gif','image/webp'];
if (!in_array($file['type'], $allowed)) {
    echo json_encode(['error' => 'Invalid file type']);
    exit;
}
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['error' => 'File too large (max 5 MB)']);
    exit;
}

$dir = __DIR__ . '/../storage/email_uploads';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$ext  = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
$name = bin2hex(random_bytes(8)) . '.' . strtolower($ext);
$dest = $dir . '/' . $name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['error' => 'Could not save file']);
    exit;
}

echo json_encode(['url' => '/storage/email_uploads/' . $name]);
