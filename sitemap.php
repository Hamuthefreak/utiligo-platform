<?php
/**
 * sitemap.php — Dynamic XML sitemap.
 * Routed from /sitemap.xml via .htaccess. Auto-includes:
 *   - core public pages
 *   - blog posts (/content/blog/*)
 *   - local lead pages (/content/leads_index.php city x industry)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/blog_posts.php';

$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : 'https://utiligo.ca', '/');

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex'); // sitemaps don't need to be indexed

function _sm_url(string $loc, string $freq = 'monthly', string $pri = '0.5', string $lastmod = ''): string
{
    $o = "\n  <url>\n    <loc>{$loc}</loc>";
    if ($lastmod !== '') $o .= "\n    <lastmod>{$lastmod}</lastmod>";
    return $o . "\n    <changefreq>{$freq}</changefreq>\n    <priority>{$pri}</priority>\n  </url>";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

$core = [
    ['/',        'weekly', '1.0'],
    ['/register', 'monthly', '0.8'],
    ['/login',    'monthly', '0.6'],
    ['/blog',     'weekly', '0.8'],
    ['/about',    'monthly', '0.5'],
    ['/careers',  'monthly', '0.4'],
    ['/contact',  'monthly', '0.5'],
    ['/terms',    'yearly',  '0.2'],
    ['/privacy',  'yearly',  '0.2'],
    ['/refund-policy', 'yearly', '0.2'],
];
foreach ($core as [$path, $f, $p]) {
    echo _sm_url($base . $path, $f, $p);
}

// Blog posts
foreach (blog_posts_list() as $post) {
    $lastmod = '';
    if (isset($post['file']) && is_file($post['file'])) {
        $lastmod = date('Y-m-d', (int)filemtime($post['file']));
    } elseif (!empty($post['date'])) {
        $lastmod = date('Y-m-d', strtotime($post['date']));
    }
    echo _sm_url($base . '/blog/' . urlencode($post['slug']), 'monthly', '0.7', $lastmod);
}

// Local lead pages (city x industry)
$index = include __DIR__ . '/content/leads_index.php';
$lastmodLead = date('Y-m-d', (int)filemtime(__DIR__ . '/content/leads_index.php') ?: (int)filemtime(__DIR__ . '/leads.php'));
foreach ($index['cities'] as $citySlug => [$cityName]) {
    foreach ($index['industries'] as $indSlug => [$indLabel]) {
        echo _sm_url($base . '/leads/' . $citySlug . '/' . $indSlug, 'monthly', '0.6', $lastmodLead);
    }
}

echo "\n</urlset>\n";