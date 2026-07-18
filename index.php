<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Utiligo — Find Clients. Build Websites. Get Paid.';
require_once __DIR__ . '/includes/header.php';
?>

<section class="max-w-5xl mx-auto px-6 py-24 text-center">
  <span class="text-sm font-semibold uppercase tracking-wide text-slate-400">For Freelancers &amp; Agencies</span>
  <h1 class="text-4xl md:text-6xl font-extrabold mt-4 mb-6">Find Clients. Build Websites. <span class="text-white underline decoration-white/20">Get Paid.</span></h1>
  <p class="text-xl text-slate-400 max-w-2xl mx-auto mb-10">Utiligo finds local businesses without a website, then generates a professional site for them in 60 seconds. No lock-in &mdash; export a clean ZIP anytime.</p>
  <a href="/register.php" class="inline-block bg-white hover:bg-slate-200 text-black px-8 py-4 rounded-full font-semibold text-lg transition">Start Finding Clients Free &rarr;</a>
</section>

<section id="calculator" class="max-w-4xl mx-auto px-6 py-20">
  <div class="text-center mb-10">
    <span class="text-sm font-semibold uppercase tracking-wide text-slate-400">Unique to Utiligo</span>
    <h2 class="text-3xl md:text-4xl font-bold mt-2">See What You Could Earn</h2>
  </div>
  <div class="backdrop-blur-lg bg-white/5 border border-white/10 rounded-2xl p-8 md:p-10">
    <div class="mb-8">
      <label class="flex justify-between text-sm mb-2">
        <span>Websites sold per month</span>
        <span id="sitesValue" class="text-white font-semibold">5</span>
      </label>
      <input type="range" id="sitesSlider" min="1" max="50" value="5" class="w-full accent-white">
    </div>
    <div class="mb-8">
      <label class="flex justify-between text-sm mb-2">
        <span>Price per website</span>
        <span id="priceValue" class="text-white font-semibold">$500</span>
      </label>
      <input type="range" id="priceSlider" min="100" max="2000" step="50" value="500" class="w-full accent-white">
    </div>
    <div class="border-t border-white/10 pt-6 space-y-2 text-sm">
      <div class="flex justify-between text-slate-400">
        <span id="breakdownText">5 websites x $500 = $2,500</span>
      </div>
      <div class="flex justify-between text-slate-400">
        <span>Minus Utiligo subscription</span>
        <span>-$21.99/month</span>
      </div>
      <div class="flex justify-between items-baseline pt-4">
        <span class="text-lg font-semibold">Your net profit</span>
        <span id="netProfit" class="text-4xl font-extrabold text-white">$2,478</span>
      </div>
    </div>
    <p class="text-xs text-slate-500 mt-6 text-center">This is potential revenue. Your results depend on your hustle.</p>
    <div class="text-center mt-6">
      <a href="/register.php" class="inline-block bg-white hover:bg-slate-200 text-black px-8 py-3 rounded-full font-semibold transition">Start Finding Clients Free &rarr;</a>
    </div>
  </div>
</section>

<section id="how-it-works" class="max-w-6xl mx-auto px-6 py-20">
  <div class="text-center mb-14">
    <span class="text-sm font-semibold uppercase tracking-wide text-slate-400">The Process</span>
    <h2 class="text-3xl md:text-4xl font-bold mt-2">From Search to Sale in 3 Steps</h2>
  </div>
  <div class="grid md:grid-cols-3 gap-8">
    <div class="text-center">
      <div class="w-16 h-16 rounded-full bg-white/10 flex items-center justify-center mx-auto mb-5">
        <span class="text-2xl font-extrabold text-white">1</span>
      </div>
      <h3 class="font-semibold text-lg mb-2">Find the Gaps</h3>
      <p class="text-slate-400 text-sm max-w-xs mx-auto">Search any city and industry. We surface local businesses that don&rsquo;t have a website yet &mdash; your warmest possible leads.</p>
    </div>
    <div class="text-center">
      <div class="w-16 h-16 rounded-full bg-white/10 flex items-center justify-center mx-auto mb-5">
        <span class="text-2xl font-extrabold text-white">2</span>
      </div>
      <h3 class="font-semibold text-lg mb-2">Generate a Site</h3>
      <p class="text-slate-400 text-sm max-w-xs mx-auto">Plug in the business details and get a complete, professional website in about 60 seconds &mdash; no coding, no design skills.</p>
    </div>
    <div class="text-center">
      <div class="w-16 h-16 rounded-full bg-white/10 flex items-center justify-center mx-auto mb-5">
        <span class="text-2xl font-extrabold text-white">3</span>
      </div>
      <h3 class="font-semibold text-lg mb-2">Pitch &amp; Get Paid</h3>
      <p class="text-slate-400 text-sm max-w-xs mx-auto">Show the owner their new site, close the deal, and hand over a clean ZIP export. Track every dollar in your dashboard.</p>
    </div>
  </div>
</section>

<section id="testimonials" class="max-w-6xl mx-auto px-6 py-20">
  <div class="text-center mb-14">
    <span class="text-sm font-semibold uppercase tracking-wide text-slate-400">Real Results</span>
    <h2 class="text-3xl md:text-4xl font-bold mt-2">People Are Already Winning With This</h2>
  </div>
  <div class="grid md:grid-cols-3 gap-6">
    <div class="glass rounded-xl p-6">
      <div class="flex text-white mb-4"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i></div>
      <p class="text-slate-300 text-sm mb-5">&ldquo;Found 12 leads in my first search, closed 2 within a week. This basically does the prospecting for you.&rdquo;</p>
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center text-white font-bold text-sm">J</div>
        <div>
          <p class="text-sm font-semibold">Jordan M.</p>
          <p class="text-xs text-slate-500">Freelance Web Designer</p>
        </div>
      </div>
    </div>
    <div class="glass rounded-xl p-6">
      <div class="flex text-white mb-4"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i></div>
      <p class="text-slate-300 text-sm mb-5">&ldquo;The site generation is insanely fast. I close deals same day now &mdash; show the preview, they say yes, done.&rdquo;</p>
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center text-white font-bold text-sm">P</div>
        <div>
          <p class="text-sm font-semibold">Priya S.</p>
          <p class="text-xs text-slate-500">Digital Agency Owner</p>
        </div>
      </div>
    </div>
    <div class="glass rounded-xl p-6">
      <div class="flex text-white mb-4"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i></div>
      <p class="text-slate-300 text-sm mb-5">&ldquo;I was skeptical about the 60-second claim but it&rsquo;s real. Generated a site, tweaked the copy, sent it same day.&rdquo;</p>
      <div class="flex items-center gap-3">
        <div class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center text-white font-bold text-sm">D</div>
        <div>
          <p class="text-sm font-semibold">Devon R.</p>
          <p class="text-xs text-slate-500">Side-Hustle Developer</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="features" class="max-w-6xl mx-auto px-6 py-20">
  <div class="text-center mb-14">
    <h2 class="text-3xl md:text-4xl font-bold">Everything You Need. Nothing You Don&rsquo;t.</h2>
  </div>
  <div class="grid md:grid-cols-3 gap-6">
    <?php
    $features = [
      ['fa-magnifying-glass', 'AI Lead Finder',        'Search any city, any industry. Find businesses with no website in seconds.'],
      ['fa-bolt',             '60-Second Site Builder', 'Enter business info, get a complete, deployable website instantly.'],
      ['fa-paintbrush',       'Site Designer',          'Edit text, images, colours and sections live — right inside the dashboard.'],
      ['fa-file-zipper',      'No Lock-In',             'Every site exports as a clean ZIP. Deploy it anywhere, forever.'],
      ['fa-chart-line',       'Revenue Dashboard',      'Track leads, sites generated, and money earned in one place.'],
      ['fa-shield-halved',    'Secure by Default',      'CSRF protection, rate limiting, and 2FA on every account.'],
    ];
    foreach ($features as [$icon, $title, $desc]): ?>
      <div class="glass rounded-xl p-6">
        <i class="fa-solid <?= $icon ?> text-2xl text-white mb-4"></i>
        <h3 class="font-semibold mb-2"><?= $title ?></h3>
        <p class="text-slate-400 text-sm"><?= $desc ?></p>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ===== PRICING ===== -->
<section id="pricing" class="max-w-6xl mx-auto px-6 py-20">
  <div class="text-center mb-14">
    <h2 class="text-3xl md:text-4xl font-bold">Simple Pricing. Real Value.</h2>
    <p class="text-slate-400 mt-3 text-sm">Start free. Upgrade when you&rsquo;re ready to scale.</p>
  </div>
  <div class="grid md:grid-cols-3 gap-6 items-start">

    <!-- FREE -->
    <div class="backdrop-blur-lg bg-white/5 border border-white/10 rounded-2xl p-8">
      <h3 class="text-xl font-bold mb-1">Free</h3>
      <p class="text-slate-400 text-sm mb-6">Explore Utiligo with no commitment.</p>
      <p class="text-4xl font-extrabold mb-6">$0</p>
      <ul class="space-y-3 text-sm text-slate-300 mb-8">
        <li><i class="fa-solid fa-check text-white mr-2"></i>2&ndash;3 leads per search</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>1 site generation / day</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>2 free templates</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>Basic dashboard</li>
        <li><i class="fa-solid fa-xmark text-slate-600 mr-2"></i>ZIP export locked</li>
        <li><i class="fa-solid fa-xmark text-slate-600 mr-2"></i>No priority support</li>
      </ul>
      <a href="/register.php" class="block text-center bg-white/10 hover:bg-white/20 py-3 rounded-full font-semibold transition">Start Free</a>
    </div>

    <!-- PRO -->
    <div class="relative backdrop-blur-lg bg-white/8 border-2 border-white rounded-2xl p-8">
      <span class="absolute -top-3 right-8 bg-white text-black text-xs font-bold px-3 py-1 rounded-full">Most Popular</span>
      <h3 class="text-xl font-bold mb-1">Pro</h3>
      <p class="text-slate-400 text-sm mb-6">For freelancers ready to land real clients.</p>
      <p class="text-4xl font-extrabold mb-6">$21.99<span class="text-base font-medium text-slate-400">/mo</span></p>
      <ul class="space-y-3 text-sm text-slate-200 mb-8">
        <li><i class="fa-solid fa-check text-white mr-2"></i>120 leads unlocked / period</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>200 active websites</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>All <?= defined('TEMPLATE_COUNT') ? TEMPLATE_COUNT : '25' ?> templates</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>ZIP export</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>Full revenue dashboard</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>Priority support</li>
      </ul>
      <a href="/register.php?plan=pro" class="block text-center bg-white hover:bg-slate-200 text-black py-3 rounded-full font-semibold transition">Go Pro</a>
    </div>

    <!-- ENTREPRENEUR -->
    <div class="relative backdrop-blur-lg bg-white/5 border border-white/10 rounded-2xl p-8">
      <span class="absolute -top-3 right-8 bg-slate-700 text-white text-xs font-bold px-3 py-1 rounded-full">Best for Agencies</span>
      <h3 class="text-xl font-bold mb-1">Entrepreneur</h3>
      <p class="text-slate-400 text-sm mb-6">Scale with a full agency operation.</p>
      <p class="text-4xl font-extrabold mb-6">$49.99<span class="text-base font-medium text-slate-400">/mo</span></p>
      <ul class="space-y-3 text-sm text-slate-200 mb-8">
        <li><i class="fa-solid fa-infinity text-white mr-2"></i>Unlimited leads</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>500 active websites</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>All <?= defined('TEMPLATE_COUNT') ? TEMPLATE_COUNT : '25' ?> templates</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>ZIP export</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>Full revenue dashboard</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>Priority support</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>Custom domains</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>Client reports</li>
        <li><i class="fa-solid fa-check text-white mr-2"></i>Team seats</li>
      </ul>
      <a href="/register.php?plan=entrepreneur" class="block text-center bg-white/10 hover:bg-white/20 py-3 rounded-full font-semibold transition">Go Entrepreneur</a>
    </div>

  </div>
</section>

<section id="faq" class="max-w-3xl mx-auto px-6 py-20">
  <div class="text-center mb-12">
    <span class="text-sm font-semibold uppercase tracking-wide text-slate-400">Questions</span>
    <h2 class="text-3xl md:text-4xl font-bold mt-2">Frequently Asked Questions</h2>
  </div>
  <div class="space-y-4">
    <?php
    $faqs = [
      ['Do I need any coding or design experience?', 'No. Utiligo generates the entire website for you — you just enter the business details and pick a template. You can also edit text, images, and colours live inside the dashboard.'],
      ['What happens to the websites I generate?', 'Every site exports as a clean, standalone ZIP file. You can host it anywhere — there\'s no lock-in to our platform.'],
      ['What\'s the difference between Pro and Entrepreneur?', 'Pro gives you 120 lead unlocks and 200 active websites per period — plenty for most freelancers. Entrepreneur unlocks unlimited leads and 500 active websites, plus custom domains, client reports, and team seats for agencies running at scale.'],
      ['Is the free plan actually usable, or just a teaser?', 'The free plan lets you run searches, see a limited lead preview, and generate 1 site per day with 2 templates. ZIP export and all templates require a paid plan.'],
      ['How does billing work?', 'Pro is $21.99/month and Entrepreneur is $49.99/month — cancel anytime. You keep access through the end of your current billing period after cancelling.'],
    ];
    foreach ($faqs as $i => [$q, $a]): ?>
      <details class="glass rounded-xl p-5 group">
        <summary class="cursor-pointer font-semibold text-sm flex justify-between items-center list-none">
          <?= htmlspecialchars($q) ?>
          <i class="fa-solid fa-chevron-down text-white text-xs group-open:rotate-180 transition-transform"></i>
        </summary>
        <p class="text-slate-400 text-sm mt-3"><?= htmlspecialchars($a) ?></p>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<section class="max-w-4xl mx-auto px-6 py-20 text-center">
  <div class="backdrop-blur-lg bg-white/5 border border-white/10 rounded-2xl p-10 md:p-14">
    <h2 class="text-3xl md:text-4xl font-bold mb-4">Ready to Find Your First Client?</h2>
    <p class="text-slate-400 mb-8 max-w-xl mx-auto">It takes less than 2 minutes to sign up and run your first lead search. No credit card required to start.</p>
    <a href="/register.php" class="inline-block bg-white hover:bg-slate-200 text-black px-8 py-4 rounded-full font-semibold text-lg transition">Start Finding Clients Free &rarr;</a>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="/assets/js/revenue_calc.js?v=v300"></script>
