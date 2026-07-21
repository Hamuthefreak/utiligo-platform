<footer class="bg-slate-900/60 border-t border-white/10 mt-20">
  <div class="max-w-6xl mx-auto px-6 py-14 grid md:grid-cols-4 gap-10">
    <div>
      <a href="/" class="text-lg font-bold flex items-center gap-2 mb-3"><i class="fa-solid fa-bolt text-emerald-400"></i>Utiligo</a>
      <p class="text-slate-400 text-sm leading-relaxed">Find clients without a website. Build them one in 60 seconds. Get paid.</p>
      <!-- Replace each [INSERT LINK HERE] below with your actual social profile URL. -->
      <div class="flex gap-3 mt-5">
        <a href="[INSERT LINK HERE]" class="w-9 h-9 flex items-center justify-center rounded-full bg-white/5 hover:bg-white/10 transition text-slate-400 hover:text-white" title="X / Twitter — add link"><i class="fa-brands fa-x-twitter"></i></a>
        <a href="[INSERT LINK HERE]" class="w-9 h-9 flex items-center justify-center rounded-full bg-white/5 hover:bg-white/10 transition text-slate-400 hover:text-white" title="Instagram — add link"><i class="fa-brands fa-instagram"></i></a>
        <a href="[INSERT LINK HERE]" class="w-9 h-9 flex items-center justify-center rounded-full bg-white/5 hover:bg-white/10 transition text-slate-400 hover:text-white" title="LinkedIn — add link"><i class="fa-brands fa-linkedin-in"></i></a>
        <a href="[INSERT LINK HERE]" class="w-9 h-9 flex items-center justify-center rounded-full bg-white/5 hover:bg-white/10 transition text-slate-400 hover:text-white" title="YouTube — add link"><i class="fa-brands fa-youtube"></i></a>
      </div>
    </div>

    <div>
      <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-300 mb-4">Product</h4>
      <ul class="space-y-2.5 text-sm text-slate-400">
        <li><a href="/#features" class="hover:text-emerald-400 transition">Features</a></li>
        <li><a href="/#pricing" class="hover:text-emerald-400 transition">Pricing</a></li>
        <li><a href="/#calculator" class="hover:text-emerald-400 transition">Revenue Calculator</a></li>
        <li><a href="/#how-it-works" class="hover:text-emerald-400 transition">How It Works</a></li>
        <li><a href="/#faq" class="hover:text-emerald-400 transition">FAQ</a></li>
        <li><a href="/register.php" class="hover:text-emerald-400 transition">Start Free</a></li>
      </ul>
    </div>

    <div>
      <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-300 mb-4">Company</h4>
      <ul class="space-y-2.5 text-sm text-slate-400">
        <li><a href="/about.php" class="hover:text-emerald-400 transition">About Us</a></li>
        <li><a href="/blog.php" class="hover:text-emerald-400 transition">Blog</a></li>
        <li><a href="/careers.php" class="hover:text-emerald-400 transition">Careers</a></li>
        <li><a href="/contact.php" class="hover:text-emerald-400 transition">Contact</a></li>
        <li><a href="/login.php" class="hover:text-emerald-400 transition">Log In</a></li>
      </ul>
    </div>

    <div>
      <h4 class="text-sm font-semibold uppercase tracking-wide text-slate-300 mb-4">Legal</h4>
      <ul class="space-y-2.5 text-sm text-slate-400">
        <li><a href="/privacy.php" class="hover:text-emerald-400 transition">Privacy Policy</a></li>
        <li><a href="/terms.php" class="hover:text-emerald-400 transition">Terms of Service</a></li>
        <li><a href="/refund-policy.php" class="hover:text-emerald-400 transition">Refund Policy</a></li>
      </ul>
    </div>
  </div>

  <div class="border-t border-white/10">
    <div class="max-w-6xl mx-auto px-6 py-6 flex flex-col md:flex-row justify-between items-center gap-4">
      <p class="text-slate-500 text-xs">&copy; <?= date('Y') ?> Utiligo.ca — All rights reserved.</p>
      <span class="inline-flex items-center gap-1 bg-white/5 border border-white/10 rounded-full px-3 py-1 text-xs text-slate-400">
        <i class="fa-solid fa-bolt text-emerald-400"></i> Powered by Utiligo
      </span>
    </div>
  </div>
</footer>

<script src="/assets/js/main.js?v=v163"></script>

<script>
// ─── Utiligo Page Transition System ───────────────────────────────────────────
(function () {
  const loader   = document.getElementById('utl-loader');
  const bar      = document.getElementById('utl-progress-bar');
  if (!loader || !bar) return;

  // ── Helpers ──
  function showLoader() {
    bar.style.width = '0%';
    loader.classList.add('visible');
    // Animate bar: quick jump to 70%, then stall waiting for page load
    requestAnimationFrame(() => {
      bar.style.transition = 'width 0.35s cubic-bezier(0.4,0,0.2,1)';
      bar.style.width = '72%';
    });
  }

  function hideLoader() {
    bar.style.transition = 'width 0.15s ease';
    bar.style.width = '100%';
    setTimeout(() => {
      loader.classList.remove('visible');
      document.body.classList.add('page-ready');
    }, 160);
  }

  // ── On page arrival: brief loader then fade in ──
  showLoader();
  // Use 'load' for full page, but cap at 600ms so it never feels slow
  let done = false;
  function finish() {
    if (done) return;
    done = true;
    hideLoader();
  }
  window.addEventListener('load', finish);
  setTimeout(finish, 600); // safety cap

  // ── On link click: show loader before navigating ──
  document.addEventListener('click', function (e) {
    const anchor = e.target.closest('a');
    if (!anchor) return;

    const href = anchor.getAttribute('href');
    if (!href) return;

    // Skip: new tab, external, anchor-only, JS, mailto, tel, download
    if (
      anchor.target === '_blank' ||
      anchor.hasAttribute('download') ||
      href.startsWith('#') ||
      href.startsWith('javascript') ||
      href.startsWith('mailto') ||
      href.startsWith('tel') ||
      (href.startsWith('http') && !href.includes(location.hostname))
    ) return;

    e.preventDefault();
    showLoader();
    // Small delay so the loader renders before browser unloads
    setTimeout(() => { location.href = href; }, 220);
  });

  // ── On form submit: show loader ──
  document.addEventListener('submit', function (e) {
    // Don't trigger on resend/inline forms that POST to the same page (they handle their own state)
    const form = e.target;
    if (form.dataset.noLoader) return;
    showLoader();
  });

  // ── Browser back/forward: show loader ──
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) {
      // Came from bfcache — immediately hide loader
      hideLoader();
    }
  });
})();
</script>

</body>
</html>
