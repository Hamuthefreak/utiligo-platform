<?php
/**
 * includes/upload_validator.php
 * Server-side image upload validation:
 *   1. File size
 *   2. Magic bytes (real file type, not just MIME header)
 *   3. No double extensions (e.g. shell.php.jpg)
 *
 * Usage:
 *   require_once __DIR__ . '/upload_validator.php';
 *   $err = validate_image_upload($_FILES['image']);
 *   if ($err) { json_response(['success'=>false,'error'=>$err], 400); }
 */
require_once __DIR__ . '/../config.php';

function validate_image_upload(array $file, int $maxBytes = MAX_LOGO_UPLOAD_BYTES): ?string
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return 'Upload failed (error code: ' . ($file['error'] ?? '?') . ').';
    }

    // 1. Size check
    if ($file['size'] > $maxBytes) {
        $mb = round($maxBytes / 1024 / 1024, 1);
        return "File too large. Maximum size is {$mb} MB.";
    }

    // 2. Double-extension check — reject filenames like shell.php.jpg
    $name = $file['name'] ?? '';
    $parts = explode('.', strtolower($name));
    $dangerousExts = ['php','php3','php4','php5','phtml','phar','asp','aspx','cgi','pl','py','rb','sh','exe','js','jsx','ts'];
    // All parts except the last are intermediate extensions
    foreach (array_slice($parts, 1, -1) as $ext) {
        if (in_array($ext, $dangerousExts, true)) {
            return 'Invalid file name.';
        }
    }
    // Final extension must be an allowed image type
    $finalExt = end($parts);
    if (!in_array($finalExt, ['jpg','jpeg','png','gif','webp'], true)) {
        return 'Only JPG, PNG, GIF, and WebP images are allowed.';
    }

    // 3. Magic bytes check — read first 12 bytes of actual file
    $fp    = fopen($file['tmp_name'], 'rb');
    $magic = fread($fp, 12);
    fclose($fp);

    $allowed = [
        "\xFF\xD8\xFF",  // JPEG
        "\x89PNG",        // PNG
        'GIF8',           // GIF
        'RIFF',           // WebP (RIFF????WEBP)
    ];

    $matched = false;
    foreach ($allowed as $sig) {
        if (strncmp($magic, $sig, strlen($sig)) === 0) {
            // Extra WebP check: bytes 8-11 must be 'WEBP'
            if ($sig === 'RIFF' && substr($magic, 8, 4) !== 'WEBP') continue;
            $matched = true;
            break;
        }
    }

    if (!$matched) {
        return 'File does not appear to be a valid image.';
    }

    return null; // all good
}
