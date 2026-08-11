<?php
/**
 * includes/blog_posts.php
 * Loads static blog posts from /content/blog/*.php. Each file returns an
 * array: ['title', 'slug', 'date' (Y-m-d), 'excerpt', 'body' (HTML)].
 * Static-first approach — swap for a DB/admin editor later if needed.
 */

function blog_posts_list(): array
{
    $dir   = dirname(__DIR__) . '/content/blog';
    $posts = [];
    foreach (glob($dir . '/*.php') ?: [] as $file) {
        $p = include $file;
        if (is_array($p) && !empty($p['slug'])) {
            $p['file'] = $file;
            $posts[]   = $p;
        }
    }
    usort($posts, fn($a, $b) => (($b['date'] ?? '')) <=> (($a['date'] ?? '')));
    return $posts;
}

function blog_post_by_slug(string $slug): ?array
{
    foreach (blog_posts_list() as $p) {
        if ($p['slug'] === $slug) return $p;
    }
    return null;
}