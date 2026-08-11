<?php
/**
 * leads.php — Programmatic local lead pages: /leads/{city}/{industry}
 * e.g. /leads/toronto/plumber. Serves keyword-rich local copy + real sample
 * records from utiligo_leads where available; 404 outside the curated set.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';

$base     = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : 'https://utiligo.ca', '/');
$index    = include __DIR__ . '/content/leads_index.php';
$cityKey  = isset($_GET['city'])     ? preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['city']))     : '';
$industryKey = isset($_GET['industry']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['industry'])) : '';

if (!isset($index['cities'][$cityKey]) || !isset($index['industries'][$industryKey])) {
    http_response_code(404);
    $pageTitle = 'Not Found — Utiligo';
    require_once __DIR__ . '/includes/header.php';
    echo '<div class="max-w-2xl mx-auto px-6 py-24 text-center">
            <h1 class="text-3xl font-bold mb-3">Page not found</h1>
            <p class="text-slate-400">That city or industry page doesn\'t exist yet.</p>
            <a href="/" class="inline-block mt-6 text-emerald-400 hover:underline">Back to Utiligo</a>
          </div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

[$cityName, $region]       = $index['cities'][$cityKey];
[$industryLabel, $industryHook] = $index['industries'][$industryKey];

// ── SEO ─────────────────────────────────────────────────────────────────────
$pageTitle   = "Find {$industryLabel} Leads in {$cityName} — Utiligo";
$seoTitle    = "{$industryLabel} Leads in {$cityName} | Businesses Without a Website";
$seoDescription = "Looking for {$industryHook} in {$cityName}, {$region}? Utiligo finds local {$industryLabel} businesses without a website — then helps you build them one and close the client.";
$seo_json_ld = [
    ['@type' => 'BreadcrumbList', 'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home',       'item' => $base . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Local Leads', 'item' => $base . '/leads/' . $cityKey . '/' . $industryKey],
    ]],
    ['@type' => 'FAQPage', 'mainEntity' => [
        ['@type' => 'Question', 'name' => "How do I find {$industryLabel} leads in {$cityName} without a website?",
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => "Utiligo searches local business data in {$cityName}, {$region} for {$industryLabel} businesses and filters out any that already have a website — leaving you a list of warm, no-website prospects."]],
        ['@type' => 'Question', 'name' => "How much does it cost to find {$industryLabel} leads in {$cityName}?",
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'The free plan includes lead searches with a limited number of leads; Pro and Entrepreneur plans unlock more leads, unlimited searches, and site generation.' ]],
        ['@type' => 'Question', 'name' => "What do I do once I have {$industryLabel} leads in {$cityName}?",
         'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Call or email the business, generate a professional website for them in about 60 seconds, send the live preview link, and close the sale. Every site exports as a clean ZIP with no lock-in.']],
    ]],
];

// Populate the page with real example records (best effort)
$examples = [];
try {
    $pdo = get_platform_db();
    $st  = $pdo->prepare(
        "SELECT business_name, business_city, business_category, business_phone
         FROM utiligo_leads
         WHERE business_city LIKE ?
           AND (business_category LIKE ? OR business_name LIKE ?)
         ORDER BY opportunity_score DESC, id DESC
         LIMIT 8"
    );
    $st->execute(['%' . $cityName . '%', '%' . $industryLabel . '%', '%' . $industryLabel . '%']);
    $examples = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $examples = [];
}

require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-4xl mx-auto px-6 py-16">
  <div class="text-center mb-12">
    <span class="text-sm font-semibold uppercase tracking-wide text-slate-400 underline decoration-white/20 decoration-2 underline-offset-8"><?= htmlspecialchars($cityName) ?>, <?= htmlspecialchars($region) ?></span>
    <h1 class="text-3xl md:text-5xl font-extrabold mt-4 mb-5 leading-tight">
      Find <?= htmlspecialchars($industryLabel) ?> Leads in <?= htmlspecialchars($cityName) ?>
    </h1>
    <p class="text-xl text-slate-400 max-w-2xl mx-auto">
      <?= htmlspecialchars($cityName) ?> has dozens of <?= htmlspecialchars(strtolower($industryLabel)) ?> businesses still running without a website. Utiligo finds them, filters out the ones that already have a site, and helps you build &amp; sell a website to the rest in one day.
    </p>
    <a href="/register.php" class="inline-block mt-8 bg-white hover:bg-slate-200 text-black px-8 py-4 rounded-full font-semibold text-lg transition">
      Find <?= htmlspecialchars($industryLabel) ?> Leads Free
    </a>
  </div>

  <?php if (!empty($examples)): ?>
  <div class="glass rounded-2xl p-6 mb-12">
    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-4">Sample <?= htmlspecialchars(strtolower($industryLabel)) ?> businesses without a website in <?= htmlspecialchars($cityName) ?></p>
    <div class="space-y-3">
      <?php foreach ($examples as $ex): ?>
      <div class="flex items-center justify-between gap-4 border border-white/5 rounded-xl px-4 py-3">
        <div>
          <p class="font-semibold"><?= htmlspecialchars($ex['business_name'] ?: $ex['business_category']) ?></p>
          <p class="text-xs text-slate-500"><?= htmlspecialchars($ex['business_city']) ?><?= !empty($ex['business_category']) ? ' · ' . htmlspecialchars($ex['business_category']) : '' ?></p>
        </div>
        <?php if (!empty($ex['business_phone'])): ?>
        <span class="text-sm text-slate-400 shrink-0"><?= htmlspecialchars($ex['business_phone']) ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="text-[11px] text-slate-600 mt-4">Examples shown are recent lead records. Run a live search on the <a href="/register.php" class="text-emerald-400 hover:underline">Utiligo dashboard</a> for the full list.</p>
  </div>
  <?php endif; ?>

  <div class="grid md:grid-cols-3 gap-5 mb-12">
    <div class="glass rounded-xl p-6">
      <div class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center mb-4 text-sm font-extrabold">1</div>
      <h3 class="font-semibold mb-2">Search <?= htmlspecialchars($cityName) ?></h3>
      <p class="text-slate-400 text-sm">Pick any neighbourhood and <?= htmlspecialchars(strtolower($industryLabel)) ?> &mdash; we surface local businesses with no website yet.</p>
    </div>
    <div class="glass rounded-xl p-6">
      <div class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center mb-4 text-sm font-extrabold">2</div>
      <h3 class="font-semibold mb-2">Build the pitch</h3>
      <p class="text-slate-400 text-sm">Generate a professional website for the business in about 60 seconds and grab a live preview link.</p>
    </div>
    <div class="glass rounded-xl p-6">
      <div class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center mb-4 text-sm font-extrabold">3</div>
      <h3 class="font-semibold mb-2">Close the client</h3>
      <p class="text-slate-400 text-sm">Show the owner their finished site, agree on a price, and hand over a clean ZIP they own forever.</p>
    </div>
  </div>

  <div class="glass rounded-2xl p-8 text-center mb-12">
    <h2 class="text-2xl md:text-3xl font-bold mb-3">Ready to win <?= htmlspecialchars($cityName) ?> clients?</h2>
    <p class="text-slate-400 mb-6 max-w-xl mx-auto">The <a href="/register.php" class="text-emerald-400 hover:underline">free plan</a> lets you run <?= htmlspecialchars(strtolower($industryLabel)) ?> searches in <?= htmlspecialchars($cityName) ?> today &mdash; no credit card required.</p>
    <a href="/#pricing" class="inline-block bg-white/10 hover:bg-white/20 border border-white/10 px-8 py-3 rounded-full font-semibold transition">See Pricing</a>
  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>