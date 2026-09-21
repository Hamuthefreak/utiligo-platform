<?php
/**
 * notfound/500.php — what .htaccess serves for ErrorDocument 500.
 *
 * STANDALONE ON PURPOSE. Every other error page loads config.php and renders
 * through errors/error_page.php, but a 500 can be the request where config is the
 * thing that is broken — so this file requires nothing at all and cannot fail for
 * a reason other than Apache being down.
 *
 * That is also why the palette is written out as literals here rather than read
 * from theme.css: there is no guarantee any asset on this request is reachable.
 * The values are the theme's canvas, ink and accent, copied, which is the one
 * place in the product where a copy is the correct answer.
 *
 * The number used to be an emerald-to-mint gradient with a -webkit-text-fill
 * gradient clip, floating over a blurred blob. It is now a flat off-white plate
 * with a hairline under it — quieter, and it reads as a page rather than a poster.
 */
http_response_code(500);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/svg+xml" href="/assets/images/icon.svg">
<link rel="apple-touch-icon" href="/assets/images/apple-touch-icon.png">
<link rel="mask-icon" href="/assets/images/logo-mark.svg" color="#0a0f1e">
<title>500 — Server Error — Utiligo</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{background:#0a0f1e;color:#e9edf5;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem;
       font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Inter,system-ui,sans-serif}
  .wrap{text-align:center;max-width:480px}
  .code{font-size:clamp(4.5rem,14vw,7rem);font-weight:800;line-height:.9;letter-spacing:-.05em;color:#e9edf5}
  .rule{width:42px;height:1px;margin:1.5rem auto 0;background:var(--accent-a34, rgba(127,227,168,.34))}
  h1{font-size:1.35rem;font-weight:700;letter-spacing:-.015em;margin:1.5rem 0 .55rem}
  p{color:rgba(233,237,245,.68);line-height:1.65;font-size:.9375rem;margin-bottom:1.9rem}
  .btns{display:flex;gap:.6rem;justify-content:center;flex-wrap:wrap}
  a,button{display:inline-flex;align-items:center;gap:.5rem;padding:.8rem 1.35rem;border-radius:10px;
           font-weight:600;font-size:.875rem;text-decoration:none;border:1px solid transparent;cursor:pointer;
           font-family:inherit;transition:background-color .2s,border-color .2s}
  .primary{background:#7fe3a8;color:#04150c;border-color:#7fe3a8}
  .primary:hover{background:#a2f3c4;border-color:#a2f3c4}
  .secondary{background:rgba(255,255,255,.04);color:#e9edf5;border-color:rgba(255,255,255,.1)}
  .secondary:hover{background:rgba(255,255,255,.08);border-color:var(--accent-a34, rgba(127,227,168,.34))}
  @media (prefers-reduced-motion: reduce){*{transition:none}}
</style>
</head>
<body>
<div class="wrap">
  <div class="code">500</div>
  <div class="rule"></div>
  <h1>Something went wrong</h1>
  <p>Our server hit an unexpected error. It has been logged and we&rsquo;re looking into it &mdash; please try again in a moment.</p>
  <div class="btns">
    <a href="/" class="primary">&#8962; Go Home</a>
    <button onclick="location.reload()" class="secondary">&#8635; Try Again</button>
  </div>
</div>
</body>
</html>
