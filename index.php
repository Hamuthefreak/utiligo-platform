<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Plan limits and prices are admin-editable (Admin > Config Editor writes
// storage/config_overrides.php, loaded by config.php before these constants).
// Read them straight from the constants — the `defined() ? : default` guards
// that used to sit here drifted from the real values (Pro was advertised as
// 120 leads / 200 sites when plan_limits.php says 700 / 20).
$FREE_LEAD_LIMIT   = FREE_LEAD_LIMIT;
$FREE_SEARCH_LIMIT = FREE_SEARCH_DAILY_LIMIT;
$FREE_GEN_LIMIT    = FREE_GENERATE_DAILY_LIMIT;
$FREE_TMPL_LIMIT   = FREE_TEMPLATE_LIMIT;
$PRO_LEAD_LIMIT    = PRO_LEAD_LIMIT;
$PRO_SITE_LIMIT    = PRO_SITE_LIMIT;
$ENT_SITE_LIMIT    = ENT_SITE_LIMIT;
$PRO_PRICE         = PRO_PLAN_PRICE;
$ENT_PRICE         = ENTREPRENEUR_PLAN_PRICE;

// Count the templates we actually ship instead of trusting a constant that
// was never defined (this used to always print "25", we ship 86).
require_once __DIR__ . '/includes/site_templates.php';
$TMPL_COUNT = count(get_all_site_templates());

$faq_pro_leads  = $PRO_LEAD_LIMIT;
$faq_pro_sites  = $PRO_SITE_LIMIT;
$faq_ent_sites  = $ENT_SITE_LIMIT;
$faq_free_leads = $FREE_LEAD_LIMIT;
$faq_free_sites = $FREE_GEN_LIMIT;
$faq_pro_price  = $PRO_PRICE;
$faq_ent_price  = $ENT_PRICE;

$pageTitle = 'Utiligo — Find Clients. Build Websites. Get Paid.';
$seoTitle  = 'Utiligo | Lead Generation & Website Builder for Freelancers & Agencies';
$seoDescription = 'Utiligo helps freelancers and agencies find local businesses with no website, generate a professional website in 60 seconds, and close more clients. No coding or design skills required.';

// FAQ used both for the on-page accordion and the FAQPage structured data.
$faqs = [
    ['Do I need any coding or design experience?',
     'No. Utiligo generates the entire website for you — you just enter the business details and pick a template. You can also edit text, images, and colours live inside the dashboard.'],
    ['What happens to the websites I generate?',
     'Every site exports as a clean, standalone ZIP file. You can host it anywhere — there\'s no lock-in to our platform.'],
    ["What's the difference between Pro and Entrepreneur?",
     'Pro gives you '.$faq_pro_leads.' lead unlocks and '.$faq_pro_sites.' active websites per period — plenty for most freelancers. Entrepreneur unlocks unlimited leads and '.$faq_ent_sites.' active websites, plus team seats for agencies running at scale. Client reporting is on the roadmap and not yet available.'],
    ['Is the free plan actually usable, or just a teaser?',
     'The free plan lets you run searches, see '.$faq_free_leads.' leads per search, and generate '.$faq_free_sites.' site per day with 2 templates. ZIP export and all templates require a paid plan.'],
    ['How does billing work?',
     'Pro is $'.number_format($faq_pro_price,2).'/month and Entrepreneur is $'.number_format($faq_ent_price,2).'/month — cancel anytime. You keep access through the end of your current billing period after cancelling.'],
    ['How does Utiligo find businesses without a website?',
     'We search local business data in any city and industry, then check whether each business already has its own website. Businesses that already have one are filtered out, so the leads you see are genuine website opportunities.'],
    ['Is Utiligo a lead generation tool?',
     'Yes. It\'s built for freelancers, web designers, and agencies whose pipeline is simple: find local businesses without a website, then sell them one. Searching, saving leads, and building pitch-ready sites all happen in one place.'],
    ['Which cities and industries does Utiligo cover?',
     'Any city you type and any industry — from plumbers, roofers, and electricians to restaurants, salons, and cleaners. Big markets like Toronto, Montreal, and Vancouver work the same as smaller towns.'],
    ['Can I white-label the websites as my own?',
     'Yes — every generated site exports as a clean, unbranded ZIP you can host anywhere, including on the business\'s own domain. You can also set your own name and colours in brand settings, which is available on every plan.'],
];

$seoBase = rtrim(defined('APP_BASE_URL') ? APP_BASE_URL : 'https://utiligo.ca', '/');

$seo_json_ld = [
    ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => [
        '@type'          => 'Question',
        'name'           => $f[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => html_entity_decode(strip_tags($f[1]))],
    ], $faqs)],
    [
        '@type' => 'Product', 'name' => 'Utiligo Free', 'description' => 'Free lead searches and website generation for freelancers.',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'CAD', 'availability' => 'https://schema.org/InStock', 'url' => $seoBase . '/register.php?plan=free'],
    ],
    [
        '@type' => 'Product', 'name' => 'Utiligo Pro', 'description' => 'Unlock '.number_format($PRO_LEAD_LIMIT, 0).' leads and '.number_format($PRO_SITE_LIMIT, 0).' sites, ZIP exports, and the full revenue dashboard.',
        'offers' => ['@type' => 'Offer', 'price' => number_format($PRO_PRICE, 2), 'priceCurrency' => 'CAD', 'availability' => 'https://schema.org/InStock', 'url' => $seoBase . '/register.php?plan=pro'],
    ],
    [
        '@type' => 'Product', 'name' => 'Utiligo Entrepreneur', 'description' => 'Unlimited leads, '.number_format($ENT_SITE_LIMIT, 0).' active sites, team seats, and every Pro feature for agencies.',
        'offers' => ['@type' => 'Offer', 'price' => number_format($ENT_PRICE, 2), 'priceCurrency' => 'CAD', 'availability' => 'https://schema.org/InStock', 'url' => $seoBase . '/register.php?plan=entrepreneur'],
    ],
    [
        '@type' => 'SoftwareApplication', 'name' => 'Utiligo', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web',
        'description' => 'Lead generation and website builder for freelancers and agencies.',
        'offers' => ['@type' => 'Offer', 'price' => number_format($PRO_PRICE, 2), 'priceCurrency' => 'CAD', 'availability' => 'https://schema.org/InStock'],
    ],
];

require_once __DIR__ . '/includes/header.php';
?>

<?php /* ── HOW THIS PAGE IS STRUCTURED ──────────────────────────────────────
   Not one word, section or feature below changed in the theme pass — the
   page is the same page. What changed is what carries it:

     .utl-card   a glass panel with a hairline top edge and a real shadow
     .utl-lift   the card is a link: it rises 3px and its edge warms to the
                 accent, in 200ms, on one shared easing curve
     data-reveal sections arrive with a 16px rise as they enter the viewport
     data-reveal-group  the children of a grid arrive in sequence
     data-count  a figure counts up the first time it is scrolled into view

   The two exceptions, both deliberate: the revenue calculator is the one
   panel that is *framed* rather than lifted (it is the page's centrepiece and
   should sit still), and its annual figure is the only number on the page in
   the accent — because it is the number the visitor came for.
   ─────────────────────────────────────────────────────────────────────── */ ?>

<!-- HERO -->
<section class="utl-hero max-w-6xl mx-auto px-4 sm:px-6 pt-10 sm:pt-16">
  <div class="utl-card px-6 py-16 sm:px-12 sm:py-24 text-center">
    <span class="utl-eyebrow is-accent utl-reveal">For Freelancers &amp; Agencies</span>
    <h1 class="utl-display mt-7 mb-7 utl-reveal" style="--d:70ms">
      Find Clients. Build Websites. <span class="whitespace-nowrap"><span class="underline decoration-white/20">Get Paid.</span></span>
    </h1>
    <p class="utl-lede max-w-2xl mx-auto mb-5 utl-reveal" style="--d:140ms">Utiligo finds local businesses without a website, then generates a professional site for them in 60 seconds. No lock-in &mdash; export a clean ZIP anytime.</p>
    <p class="text-sm utl-body max-w-2xl mx-auto mb-11 utl-reveal" style="--d:180ms">Lead generation for freelancers &amp; agencies: search any city and industry for businesses with no website of their own &mdash; then pitch them a site you build in under a minute.</p>
    <div class="utl-reveal" style="--d:220ms">
      <a href="/register.php" class="utl-btn utl-btn--primary utl-btn--lg">Start Finding Clients Free &rarr;</a>
    </div>
  </div>
</section>

<!-- LEAD GENERATION BENEFITS -->
<section id="leadgen" class="max-w-6xl mx-auto px-4 sm:px-6 py-10 md:py-16">
  <div class="grid md:grid-cols-3 gap-5" data-reveal-group>
    <div class="utl-card utl-lift p-7">
      <span class="utl-icon-tile text-xl mb-5"><i class="fa-solid fa-shop"></i></span>
      <h3 class="font-semibold mb-2">Local businesses without a website</h3>
      <p class="utl-body text-sm">Every search targets businesses that still don&rsquo;t have their own site &mdash; the exact gap a web designer or agency can fill.</p>
    </div>
    <div class="utl-card utl-lift p-7">
      <span class="utl-icon-tile text-xl mb-5"><i class="fa-solid fa-sack-dollar"></i></span>
      <h3 class="font-semibold mb-2">Your warmest possible leads</h3>
      <p class="utl-body text-sm">Businesses without a website are already missing out on customers. That&rsquo;s the easiest client conversation you&rsquo;ll ever start.</p>
    </div>
    <div class="utl-card utl-lift p-7">
      <span class="utl-icon-tile text-xl mb-5"><i class="fa-solid fa-bolt"></i></span>
      <h3 class="font-semibold mb-2">From lead to sale in one afternoon</h3>
      <p class="utl-body text-sm">Find them, build a complete site in 60 seconds, send the preview link, and close the deal &mdash; no cold calls or redesigns required.</p>
    </div>
  </div>
</section>

<!-- CALCULATOR -->
<?php /* The revenue slider.
         Two native range inputs drive it — no library, no canvas — because a
         range input is already keyboard-accessible, already announced by a
         screen reader as "slider, 5, minimum 1, maximum 50", and already works
         with a trackpad gesture. The styling in theme.css replaces the control's
         *paint*, not its behaviour.

         The figures tween rather than snap (see revenue_calc.js): dragging the
         handle makes the money move with your hand, and the annual figure is in
         the accent at display size because it is the answer to the question the
         section is asking. */ ?>
<section id="calculator" class="max-w-5xl mx-auto px-4 sm:px-6 py-16 md:py-24" data-sub-price="<?= number_format($PRO_PRICE, 2) ?>">
  <div class="text-center mb-12 utl-reveal">
    <span class="utl-eyebrow">Unique to Utiligo</span>
    <h2 class="utl-h2 mt-4">See What You Could Earn</h2>
  </div>
  <div class="utl-card p-7 sm:p-11 utl-reveal" style="--d:80ms">
    <div class="mb-9">
      <label class="flex justify-between items-baseline text-sm mb-3" for="sitesSlider">
        <span class="utl-body">Websites sold per month</span>
        <span id="sitesValue" class="utl-num text-white font-semibold">5</span>
      </label>
      <input type="range" id="sitesSlider" min="1" max="50" value="5" class="utl-range" aria-describedby="breakdownText">
    </div>
    <div class="mb-9">
      <label class="flex justify-between items-baseline text-sm mb-3" for="priceSlider">
        <span class="utl-body">Price per website</span>
        <span id="priceValue" class="utl-num text-white font-semibold">$500</span>
      </label>
      <input type="range" id="priceSlider" min="100" max="2000" step="50" value="500" class="utl-range" aria-describedby="breakdownText">
    </div>

    <div class="utl-rule mb-6"></div>

    <div class="space-y-2.5 text-sm">
      <div class="flex justify-between utl-body">
        <span id="breakdownText">5 websites x $500 = $2,500</span>
      </div>
      <div class="flex justify-between utl-body">
        <span>Minus Utiligo subscription</span>
        <span class="utl-num">-$<?= number_format($PRO_PRICE, 2) ?>/month</span>
      </div>
      <div class="flex justify-between items-baseline pt-5">
        <span class="text-lg font-semibold">Your net profit</span>
        <span id="netProfit" class="utl-figure text-4xl md:text-5xl" data-prefix="$">$2,478</span>
      </div>
    </div>

    <div class="pt-7 mt-7 border-t border-white/5 text-center">
      <p class="text-xs utl-body">That&rsquo;s roughly</p>
      <p id="annualProfit" class="utl-figure is-accent text-5xl md:text-6xl mt-2 mb-2" data-prefix="$">$29,736</p>
      <p class="text-xs utl-body">a year &mdash; on one repeatable service.</p>
    </div>

    <p class="text-xs utl-body mt-8 mb-6 text-center opacity-80">This is potential revenue. Your results depend on your hustle.</p>
    <div class="text-center">
      <a href="/register.php" class="utl-btn utl-btn--primary">Start Finding Clients Free &rarr;</a>
    </div>
  </div>
</section>

<!-- HOW IT WORKS -->
<section id="how-it-works" class="max-w-6xl mx-auto px-4 sm:px-6 py-16 md:py-24">
  <div class="text-center mb-14 utl-reveal">
    <span class="utl-eyebrow">The Process</span>
    <h2 class="utl-h2 mt-4">From Search to Sale in 3 Steps</h2>
  </div>
  <div class="grid md:grid-cols-3 gap-10 md:gap-8" data-reveal-group>
    <div class="text-center">
      <div class="utl-step mb-5">1</div>
      <h3 class="font-semibold text-lg mb-2">Find the Gaps</h3>
      <p class="utl-body text-sm max-w-xs mx-auto">Search any city and industry. We surface local businesses that don&rsquo;t have a website yet &mdash; your warmest possible leads.</p>
    </div>
    <div class="text-center">
      <div class="utl-step mb-5">2</div>
      <h3 class="font-semibold text-lg mb-2">Generate a Site</h3>
      <p class="utl-body text-sm max-w-xs mx-auto">Plug in the business details and get a complete, professional website in about 60 seconds &mdash; no coding, no design skills.</p>
    </div>
    <div class="text-center">
      <div class="utl-step mb-5">3</div>
      <h3 class="font-semibold text-lg mb-2">Pitch &amp; Get Paid</h3>
      <p class="utl-body text-sm max-w-xs mx-auto">Show the owner their new site, close the deal, and hand over a clean ZIP export. Track every dollar in your dashboard.</p>
    </div>
  </div>
</section>

<!-- TESTIMONIALS -->
<section id="testimonials" class="max-w-6xl mx-auto px-4 sm:px-6 py-16 md:py-24">
  <div class="text-center mb-14 utl-reveal">
    <span class="utl-eyebrow">Real Results</span>
    <h2 class="utl-h2 mt-4">People Are Already Winning With This</h2>
  </div>
  <div class="grid md:grid-cols-3 gap-5" data-reveal-group>
    <?php foreach ([
      ['J','Jordan M.','Freelance Web Designer','Found 12 leads in my first search, closed 2 within a week. This basically does the prospecting for you.'],
      ['P','Priya S.','Digital Agency Owner','The site generation is insanely fast. I close deals same day now — show the preview, they say yes, done.'],
      ['D','Devon R.','Side-Hustle Developer','I was skeptical about the 60-second claim but it\'s real. Generated a site, tweaked the copy, sent it same day.'],
    ] as [$init,$name,$role,$quote]): ?>
    <div class="utl-card p-7">
      <div class="flex gap-1 text-slate-300 mb-5 text-xs"><?= str_repeat('<i class="fa-solid fa-star"></i>',5) ?></div>
      <p class="text-slate-300 text-sm mb-6 leading-relaxed">&ldquo;<?= htmlspecialchars($quote) ?>&rdquo;</p>
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center text-white font-bold text-sm"><?= $init ?></div>
        <div><p class="text-sm font-semibold"><?= $name ?></p><p class="text-xs text-slate-500"><?= $role ?></p></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- FEATURES -->
<section id="features" class="max-w-6xl mx-auto px-4 sm:px-6 py-16 md:py-24">
  <div class="text-center mb-14 utl-reveal">
    <h2 class="utl-h2">Everything You Need. Nothing You Don&rsquo;t.</h2>
  </div>
  <div class="grid md:grid-cols-3 gap-5" data-reveal-group>
    <?php foreach ([
      ['fa-magnifying-glass', 'AI Lead Finder',        'Search any city, any industry. Find businesses with no website in seconds.'],
      ['fa-bolt',             '60-Second Site Builder', 'Enter business info, get a complete, deployable website instantly.'],
      ['fa-paintbrush',       'Site Designer',          'Edit text, images, colours and sections live — right inside the dashboard.'],
      ['fa-file-zipper',      'No Lock-In',             'Every site exports as a clean ZIP. Deploy it anywhere, forever.'],
      ['fa-chart-line',       'Revenue Dashboard',      'Track leads, sites generated, and money earned in one place.'],
      ['fa-shield-halved',    'Secure by Default',      'CSRF protection, rate limiting, and 2FA on every account.'],
    ] as [$icon,$title,$desc]): ?>
    <div class="utl-card utl-lift p-7">
      <span class="utl-icon-tile text-xl mb-5"><i class="fa-solid <?= $icon ?>"></i></span>
      <h3 class="font-semibold mb-2"><?= $title ?></h3>
      <p class="utl-body text-sm"><?= $desc ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <?php /* The call dock gets its own full-width card rather than a seventh tile:
           a 3-column grid of 7 leaves an orphan, and this is the one feature
           where "it is always there while you dial" is the whole pitch — which
           needs a sentence, not a bullet. */ ?>
  <div class="utl-card utl-lift p-7 md:p-9 mt-5 flex flex-col md:flex-row md:items-center gap-7 utl-reveal">
    <div class="flex items-center gap-4 md:w-1/3 shrink-0">
      <span class="utl-icon-tile text-xl shrink-0"><i class="fa-solid fa-phone-volume"></i></span>
      <h3 class="font-semibold text-lg">Call Scripts That Follow You<span class="block text-[10px] font-bold uppercase tracking-widest text-slate-500 mt-1">On Pro and Entrepreneur</span></h3>
    </div>
    <p class="utl-body text-sm md:flex-1">
      Your pitch, open in a floating panel that stays with you as you move through
      the dashboard &mdash; dimmed until you look at it, collapsible mid-sentence, and
      on every page you land on. Put a lead's name, city and number straight into a
      script with <code class="px-1.5 py-0.5 rounded bg-white/10 text-xs text-slate-300">{{business_name}}</code>,
      switch scripts with one key while the phone is ringing, or pop the panel into
      its own window and leave it on a second monitor.
    </p>
  </div>
</section>

<!-- PRICING -->
<section id="pricing" class="max-w-6xl mx-auto px-4 sm:px-6 py-16 md:py-24">
  <div class="text-center mb-14 utl-reveal">
    <h2 class="utl-h2">Simple Pricing. Real Value.</h2>
    <p class="utl-body mt-3 text-sm">Start free. Upgrade when you&rsquo;re ready to scale.</p>
  </div>
  <?php /* Cards stretch (no items-start) so the three CTAs land on one
           baseline; the feature lists grow to absorb the difference.

           Notes on the passes this section has had:
             - the accent marks the recommended card and nothing else, so
               "which one am I supposed to pick" is answered at a glance;
             - the two not-yet-built features carry a hairline chip rather
               than an amber badge — a warning colour on a pricing table reads
               as "something is wrong", and nothing is;
             - focus-visible is preserved exactly: the CTA takes a ring, and
               the card takes a ring on focus-within, so tabbing through the
               page shows you where you are. */ ?>
  <div class="grid md:grid-cols-3 gap-5 items-stretch" data-reveal-group>

    <!-- FREE -->
    <div class="relative flex flex-col utl-card utl-lift p-8 focus-within:border-white/40">
      <h3 class="text-xl font-bold mb-1">Free</h3>
      <p class="utl-body text-sm mb-6">Explore Utiligo with no commitment.</p>
      <p class="utl-figure text-4xl mb-7">$0</p>
      <ul class="space-y-3 text-sm text-slate-300 mb-8 flex-1">
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i><?= $FREE_LEAD_LIMIT ?> leads per search</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i><?= $FREE_GEN_LIMIT ?> site generation / day</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i><?= $FREE_TMPL_LIMIT ?> free templates</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>Basic dashboard</li>
        <li><i class="fa-solid fa-xmark text-slate-600 mr-2"></i>ZIP export locked</li>
        <li><i class="fa-solid fa-xmark text-slate-600 mr-2"></i>No priority support</li>
      </ul>
      <a href="/register.php" class="utl-btn utl-btn--ghost w-full mt-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950">Start Free</a>
    </div>

    <!-- PRO -->
    <?php /* Pro's hairline is already the accent, so its keyboard affordance is
             the ring on the CTA plus the card's own focus-within step. */ ?>
    <div class="relative flex flex-col utl-card utl-lift is-recommended p-8 focus-within:border-white/50">
      <span class="absolute -top-3 right-8 utl-tag is-accent">Most Popular</span>
      <h3 class="text-xl font-bold mb-1">Pro</h3>
      <p class="utl-body text-sm mb-6">For freelancers ready to land real clients.</p>
      <p class="utl-figure text-4xl mb-7"><span data-count="<?= number_format($PRO_PRICE, 2, '.', '') ?>" data-decimals="2" data-prefix="$" data-count-duration="900">$<?= number_format($PRO_PRICE, 2) ?></span><span class="text-base font-medium text-slate-400">/mo</span></p>
      <ul class="space-y-3 text-sm text-slate-200 mb-8 flex-1">
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i><?= $PRO_LEAD_LIMIT ?> leads unlocked / period</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i><?= $PRO_SITE_LIMIT ?> active websites</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>All <?= $TMPL_COUNT ?> templates</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>ZIP export</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>Full revenue dashboard</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>Call scripts on every page</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>Priority support</li>
      </ul>
      <a href="/register.php?plan=pro" class="utl-btn utl-btn--primary w-full mt-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950">Go Pro</a>
    </div>

    <!-- ENTREPRENEUR -->
    <div class="relative flex flex-col utl-card utl-lift p-8 focus-within:border-white/40">
      <span class="absolute -top-3 right-8 utl-tag">Best for Agencies</span>
      <h3 class="text-xl font-bold mb-1">Entrepreneur</h3>
      <p class="utl-body text-sm mb-6">Scale with a full agency operation.</p>
      <p class="utl-figure text-4xl mb-7"><span data-count="<?= number_format($ENT_PRICE, 2, '.', '') ?>" data-decimals="2" data-prefix="$" data-count-duration="900">$<?= number_format($ENT_PRICE, 2) ?></span><span class="text-base font-medium text-slate-400">/mo</span></p>
      <ul class="space-y-3 text-sm text-slate-200 mb-8 flex-1">
        <li><i class="fa-solid fa-infinity text-slate-400 mr-2"></i>Unlimited leads</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i><?= $ENT_SITE_LIMIT ?> active websites</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>All <?= $TMPL_COUNT ?> templates</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>ZIP export</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>Full revenue dashboard</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>Call scripts on every page</li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>Priority support</li>
        <li class="flex items-center gap-2">
          <i class="fa-solid fa-clock text-slate-600 mr-2"></i>
          <span class="line-through text-slate-600">Custom domains</span>
          <span class="utl-tag ml-1">Coming Soon</span>
        </li>
        <li class="flex items-center gap-2">
          <i class="fa-solid fa-clock text-slate-600 mr-2"></i>
          <span class="line-through text-slate-600">Client reports</span>
          <span class="utl-tag ml-1">Coming Soon</span>
        </li>
        <li><i class="fa-solid fa-check text-slate-400 mr-2"></i>Team seats</li>
      </ul>
      <a href="/register.php?plan=entrepreneur" class="utl-btn utl-btn--ghost w-full mt-auto focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-slate-950">Go Entrepreneur</a>
    </div>

  </div>
</section>

<!-- FAQ -->
<section id="faq" class="max-w-3xl mx-auto px-4 sm:px-6 py-16 md:py-24">
  <div class="text-center mb-12 utl-reveal">
    <span class="utl-eyebrow">Questions</span>
    <h2 class="utl-h2 mt-4">Frequently Asked Questions</h2>
  </div>
  <div class="space-y-3" data-reveal-group>
    <?php foreach ($faqs as [$q,$a]): ?>
    <details class="utl-card p-5 group">
      <summary class="cursor-pointer font-semibold text-sm flex justify-between items-center list-none">
        <?= htmlspecialchars($q) ?>
        <i class="fa-solid fa-chevron-down text-slate-400 text-xs group-open:rotate-180 transition-transform"></i>
      </summary>
      <p class="utl-body text-sm mt-3"><?= $a ?></p>
    </details>
    <?php endforeach; ?>
  </div>
</section>

<!-- CTA -->
<section class="max-w-4xl mx-auto px-4 sm:px-6 py-16 md:py-24 text-center">
  <div class="utl-card p-10 md:p-14 utl-reveal">
    <h2 class="utl-h2 mb-4">Ready to Find Your First Client?</h2>
    <p class="utl-body mb-9 max-w-xl mx-auto">It takes less than 2 minutes to sign up and run your first lead search. No credit card required to start.</p>
    <a href="/register.php" class="utl-btn utl-btn--primary utl-btn--lg">Start Finding Clients Free &rarr;</a>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="<?= asset_url('/assets/js/revenue_calc.js') ?>"></script>
