<?php
/**
 * s.php — Public shareable link for a generated website.
 * URL pattern: utiligo.ca/s/{slug}
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';

$slug = $_GET['slug'] ?? '';
$slug = preg_replace('/[^a-zA-Z0-9\-]/', '', $slug);

if (!$slug) {
    http_response_code(404);
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="max-w-xl mx-auto px-6 py-24 text-center"><h1 class="text-2xl font-bold mb-2">Link not found</h1><p class="text-slate-400">This link is invalid.</p></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$pdo  = get_platform_db();
$stmt = $pdo->prepare('SELECT * FROM utiligo_generated_sites WHERE public_slug = ? LIMIT 1');
$stmt->execute([$slug]);
$site = $stmt->fetch();

$expired  = false;
$notFound = !$site;

if ($site) {
    $isPastExpiry = $site['link_expires_at'] && strtotime($site['link_expires_at']) < time();
    $isInactive   = !$site['link_active'];
    $expired      = $isPastExpiry || $isInactive;
}

if ($notFound || $expired) {
    http_response_code(404);
    require_once __DIR__ . '/includes/header.php';
    if ($expired) {
        echo '<div class="max-w-xl mx-auto px-6 py-24 text-center">
            <div class="w-16 h-16 rounded-full bg-amber-500/20 flex items-center justify-center mx-auto mb-5">
                <i class="fa-solid fa-clock text-amber-400 text-2xl"></i>
            </div>
            <h1 class="text-2xl font-bold mb-2">This link has expired</h1>
            <p class="text-slate-400">The business owner\'s preview link is no longer active.</p>
        </div>';
    } else {
        echo '<div class="max-w-xl mx-auto px-6 py-24 text-center"><h1 class="text-2xl font-bold mb-2">Link not found</h1><p class="text-slate-400">This link doesn\'t exist or was removed.</p></div>';
    }
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Track the view (fire-and-forget via JS beacon below)
$siteId  = (int)$site['id'];
$slugDir = $site['public_slug'];

$page = $_GET['page'] ?? 'index';
$page = preg_replace('/[^a-zA-Z0-9\-]/', '', $page);
$filePath = __DIR__ . '/assets/uploads/generated_sites/' . $slugDir . '/' . $page . '.html';

if (!file_exists($filePath)) {
    $filePath = __DIR__ . '/assets/uploads/generated_sites/' . $slugDir . '/index.html';
}

if (!file_exists($filePath)) {
    http_response_code(404);
    echo 'Site files not found.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');

$html = file_get_contents($filePath);
$html = preg_replace_callback('/href="([a-z\-]+)\.html"/', function ($m) use ($slug) {
    $target = $m[1] === 'index' ? '' : ('?page=' . $m[1]);
    return 'href="/s/' . $slug . $target . '"';
}, $html);

// Inject view-tracking beacon just before </body>
$beacon = '<script>navigator.sendBeacon && navigator.sendBeacon("/api/track_view.php",new URLSearchParams({site_id:' . $siteId . '}));</script>';
$html   = str_replace('</body>', $beacon . '</body>', $html);

echo $html;
