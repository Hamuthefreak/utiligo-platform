<?php
/**
 * blog.php — Blog listing (/blog) + single post (/blog/{slug}).
 * Posts are static files in /content/blog (see includes/blog_posts.php).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/blog_posts.php';

$slug = isset($_GET['slug']) ? preg_replace('/[^a-zA-Z0-9\-]/', '', $_GET['slug']) : '';
$base = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : 'https://utiligo.ca', '/');

// ── Single post ────────────────────────────────────────────────────────────
if ($slug !== '') {
    $post = blog_post_by_slug($slug);
    if (!$post) {
        http_response_code(404);
        $pageTitle = 'Post Not Found — Utiligo';
        require_once __DIR__ . '/includes/header.php';
        echo '<div class="max-w-2xl mx-auto px-6 py-24 text-center">
                <h1 class="text-3xl font-bold mb-3">Post not found</h1>
                <p class="text-slate-400">That article doesn\'t exist or was moved.</p>
                <a href="/blog" class="inline-block mt-6 text-emerald-400 hover:underline">Back to the blog</a>
              </div>';
        require_once __DIR__ . '/includes/footer.php';
        exit;
    }

    $pageTitle      = $post['title'] . ' — Utiligo Blog';
    $seoTitle       = $post['title'] . ' | Utiligo';
    $seoDescription = $post['excerpt'];
    $seoType        = 'article';
    $seo_json_ld    = [[
        '@type'           => 'BlogPosting',
        'headline'        => $post['title'],
        'datePublished'   => $post['date'],
        'description'     => $post['excerpt'],
        'url'             => $base . '/blog/' . urlencode($post['slug']),
        'author'          => ['@type' => 'Organization', 'name' => 'Utiligo'],
        'publisher'       => ['@id' => $base . '/#organization'],
        'mainEntityOfPage' => $base . '/blog/' . urlencode($post['slug']),
    ]];

    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="max-w-3xl mx-auto px-6 py-16">
      <div class="mb-10">
        <a href="/blog" class="text-xs text-emerald-400 hover:underline"><i class="fa-solid fa-arrow-left mr-1.5"></i>Blog</a>
        <h1 class="text-3xl md:text-4xl font-extrabold mt-4 mb-3 leading-tight"><?= htmlspecialchars($post['title']) ?></h1>
        <p class="text-xs text-slate-500 uppercase tracking-widest">
          <?= date('F j, Y', strtotime($post['date'])) ?> &middot; Utiligo Blog
        </p>
      </div>
      <?= $post['body'] ?>
      <div class="mt-12 pt-8 border-t border-white/10">
        <a href="/register.php" class="inline-block bg-white hover:bg-slate-200 text-black px-8 py-4 rounded-full font-semibold transition">
          Start Finding Clients Free
        </a>
      </div>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ── Listing ─────────────────────────────────────────────────────────────────
$posts = blog_posts_list();
$pageTitle      = 'Blog — Utiligo';
$seoTitle       = 'Utiligo Blog | Lead Generation Tips for Freelancers & Agencies';
$seoDescription = 'Guides on lead generation, finding local businesses without a website, website pricing for freelancers, and growing your agency.';
require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-5xl mx-auto px-6 py-16">
  <div class="text-center mb-12">
    <span class="text-sm font-semibold uppercase tracking-wide text-slate-400">The Utiligo Blog</span>
    <h1 class="text-3xl md:text-4xl font-extrabold mt-3 mb-4">Lead generation tips for freelancers &amp; agencies</h1>
    <p class="text-slate-400 max-w-2xl mx-auto">Practical guides to finding local businesses without a website, pricing your work, and building a client pipeline that never runs dry.</p>
  </div>

  <div class="grid md:grid-cols-2 gap-6">
    <?php foreach ($posts as $p): ?>
    <a href="/blog/<?= htmlspecialchars($p['slug']) ?>" class="glass rounded-2xl p-6 group hover:border-white/20 transition block">
      <p class="text-[11px] text-slate-500 uppercase tracking-widest mb-2"><?= date('F j, Y', strtotime($p['date'])) ?></p>
      <h2 class="text-xl font-bold mb-2 group-hover:text-emerald-300 transition"><?= htmlspecialchars($p['title']) ?></h2>
      <p class="text-sm text-slate-400 leading-relaxed"><?= htmlspecialchars($p['excerpt']) ?></p>
      <span class="inline-flex items-center gap-1.5 text-sm text-emerald-400 mt-4">Read more <i class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition"></i></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>