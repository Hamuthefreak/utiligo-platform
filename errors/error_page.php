<?php
/**
 * errors/error_page.php
 *
 * The ONE error page in the product. Every 4xx and 5xx in both error
 * directories renders through it, so the treatment is defined here and nowhere
 * else — there used to be seven hand-written copies of this markup, and they had
 * already drifted.
 *
 * Requires: $pageTitle, $_err_code, $_err_title, $_err_desc
 * Optional: $_err_extra    extra copy under the description (e.g. the bad path)
 *           $_err_links    false to suppress the "maybe you were looking for" row
 *           $_err_keep     keep the chrome (nav/footer) off entirely
 *
 * WHY THE STYLE BLOCK IS INLINE
 * -----------------------------
 * A 500 can be the request where config.php itself is broken — that is why
 * includes/global_error_handler.php and this file both work without it — so the
 * page must not depend on assets/css/theme.css having loaded. The rules below are
 * written against the theme's own custom properties with the literal value as a
 * fallback, which means they follow the palette when the stylesheet is there and
 * render the same canvas when it is not.
 *
 * WHAT IT NO LONGER DOES
 * ----------------------
 * The code used to be a six-stop emerald-to-mint gradient clipped to the glyphs,
 * floating over a blurred emerald blob three times its own width. That is the
 * loudest "a generator made this" signal in the whole product — and it is the
 * face a customer sees on their worst visit. An error page should be quiet,
 * legible and immediately actionable: the number, one sentence, two buttons.
 */
if (!isset($_err_code))  { $_err_code  = '???'; }
if (!isset($_err_title)) { $_err_title = 'Unexpected Error'; }
if (!isset($_err_desc))  { $_err_desc  = 'Something went wrong. Please try again.'; }

// Load header only when we safely can (500 may be called when config is broken)
$_header_ok = function_exists('is_logged_in');
// The glass filters, for the branch that draws its own <html>. When the header is
// available it has already emitted both the attribute and the filters, and
// emitting them twice would duplicate two element ids in one document. glass.php
// declares functions and nothing else — it reads no config, so it is safe on the
// page whose whole job is to survive a broken config — but it is still checked
// for existence rather than required outright, because this file must render
// even when an include path is the thing that broke.
$_err_glass = !$_header_ok && is_file(__DIR__ . '/../includes/glass.php');
if ($_err_glass) {
    require_once __DIR__ . '/../includes/glass.php';
}
if ($_header_ok) {
    $pageTitle = $pageTitle ?? ($_err_code . ' \u2014 Utiligo');
    require_once __DIR__ . '/../includes/header.php';
}
?>
<?php if (!$_header_ok): ?>
<!DOCTYPE html><html lang="en"<?= $_err_glass ? ' ' . glass_attr() : '' ?>><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="icon" type="image/svg+xml" href="/assets/images/icon.svg">
<link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png">
<link rel="mask-icon" href="/assets/images/logo-mark.svg" color="#0a0f1e">
<title><?= htmlspecialchars($pageTitle ?? $_err_code) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
</head><body>
<?= $_err_glass ? glass_defs() : '' ?>
<?php endif; ?>

<style>
  /* Scoped to this page: an error page is the last surface that should be
     loading a third stylesheet, and these four rules are the whole design. */
  .err-wrap { min-height: 78vh; display: flex; align-items: center; justify-content: center;
              padding: 6rem 1.5rem; background: var(--canvas, #0a0f1e); }
  .err-inner { max-width: 32rem; margin: 0 auto; text-align: center; }
  .err-code {
    font-family: 'Space Grotesk', 'Inter', system-ui, sans-serif;
    font-size: clamp(4.5rem, 14vw, 7rem);
    font-weight: 800;
    line-height: .9;
    letter-spacing: -.05em;
    color: var(--ink, #e9edf5);
    /* No glow, no gradient fill: one flat plate of off-white. */
  }
  .err-rule { width: 42px; height: 1px; margin: 1.5rem auto 0;
              background: var(--accent-line, var(--accent-a34, rgba(127,227,168,.34))); }
  .err-title { font-size: 1.35rem; font-weight: 700; margin: 1.5rem 0 .55rem;
               color: var(--ink, #e9edf5); letter-spacing: -.015em; }
  .err-desc { color: var(--ink-2, rgba(233,237,245,.68)); line-height: 1.65; font-size: .9375rem; }
  .err-path { display: inline-block; margin-top: .85rem; padding: .3rem .6rem;
              border: 1px solid var(--hair, rgba(255,255,255,.1)); border-radius: 6px;
              font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
              font-size: .7rem; color: var(--ink-3, rgba(233,237,245,.46));
              word-break: break-all; }
  .err-actions { display: flex; flex-wrap: wrap; gap: .6rem; justify-content: center; margin-top: 1.9rem; }
  .err-btn {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .8rem 1.35rem; border-radius: 10px;
    font-size: .875rem; font-weight: 600; text-decoration: none; cursor: pointer;
    border: 1px solid transparent; font-family: inherit;
    transition: background-color 200ms var(--ease, cubic-bezier(.22,.61,.36,1)),
                border-color 200ms var(--ease, cubic-bezier(.22,.61,.36,1));
  }
  /* The accent is spent on the one action you would take; everything else is a
     hairline. On an error page that hierarchy matters more than anywhere else. */
  .err-btn.is-primary {
    background: var(--accent, #7fe3a8); color: var(--accent-ink, #04150c); border-color: var(--accent, #7fe3a8);
  }
  .err-btn.is-primary:hover { background: var(--accent-hi, #a2f3c4); border-color: var(--accent-hi, #a2f3c4); }
  .err-btn.is-ghost {
    background: rgba(255,255,255,.04); color: var(--ink, #e9edf5);
    border-color: var(--hair, rgba(255,255,255,.1));
  }
  .err-btn.is-ghost:hover { background: rgba(255,255,255,.08); border-color: var(--accent-line, var(--accent-a34, rgba(127,227,168,.34))); }
  .err-suggest { margin-top: 2.75rem; padding-top: 2rem; border-top: 1px solid var(--hair, rgba(255,255,255,.1)); }
  .err-suggest p { font-size: .65rem; font-weight: 700; letter-spacing: .16em; text-transform: uppercase;
                   color: var(--ink-3, rgba(233,237,245,.46)); margin-bottom: .9rem; }
  .err-links { display: flex; flex-wrap: wrap; gap: .4rem; justify-content: center; }
  .err-links a {
    font-size: .75rem; font-weight: 500; text-decoration: none;
    padding: .35rem .7rem; border-radius: 6px;
    border: 1px solid var(--hair, rgba(255,255,255,.1));
    color: var(--ink-2, rgba(233,237,245,.68));
    transition: color 200ms var(--ease, cubic-bezier(.22,.61,.36,1)),
                border-color 200ms var(--ease, cubic-bezier(.22,.61,.36,1));
  }
  .err-links a:hover { color: var(--ink, #e9edf5); border-color: var(--accent-line, var(--accent-a34, rgba(127,227,168,.34))); }
</style>

<section class="err-wrap">
  <div class="err-inner">

    <p class="err-code"><?= htmlspecialchars($_err_code) ?></p>
    <div class="err-rule"></div>

    <h1 class="err-title"><?= htmlspecialchars($_err_title) ?></h1>
    <p class="err-desc"><?= htmlspecialchars($_err_desc) ?></p>
    <?php if (!empty($_err_extra)): ?>
      <p class="err-path"><?= htmlspecialchars($_err_extra) ?></p>
    <?php endif; ?>

    <div class="err-actions">
      <?php $home = (function_exists('is_logged_in') && is_logged_in()) ? '/portal/index.php' : '/'; ?>
      <a href="<?= $home ?>" class="err-btn is-primary">
        <i class="fa-solid fa-house" aria-hidden="true"></i>
        <?= $home === '/' ? 'Go Home' : 'Go to Dashboard' ?>
      </a>
      <button type="button" onclick="history.back()" class="err-btn is-ghost">
        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Go Back
      </button>
    </div>

    <?php if (!isset($_err_links) || $_err_links): ?>
    <div class="err-suggest">
      <p>Maybe you were looking for</p>
      <div class="err-links">
        <a href="/">Home</a>
        <?php if (function_exists('is_logged_in') && is_logged_in()): ?>
        <a href="/portal/index.php">Dashboard</a>
        <a href="/portal/leads.php">Find Leads</a>
        <a href="/portal/generate.php">Generate Site</a>
        <a href="/portal/my_sites.php">My Sites</a>
        <a href="/portal/settings.php">Settings</a>
        <?php else: ?>
        <a href="/#pricing">Pricing</a>
        <a href="/login.php">Log In</a>
        <a href="/register.php">Register</a>
        <?php endif; ?>
        <a href="/contact.php">Contact</a>
      </div>
    </div>
    <?php endif; ?>

  </div>
</section>

<?php
if ($_header_ok) {
    require_once __DIR__ . '/../includes/footer.php';
} else {
    echo '</body></html>';
}
?>
