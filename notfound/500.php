<?php http_response_code(500); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="/assets/images/newsitelogo.png">
<link rel="apple-touch-icon" href="/assets/images/newsitelogo.png">
<title>500 — Server Error — Utiligo</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{background:#020617;color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:2rem}
  .wrap{text-align:center;max-width:480px}
  .code{font-size:7rem;font-weight:900;line-height:1;background:linear-gradient(135deg,#10b981,#34d399);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
  h1{font-size:1.5rem;font-weight:700;margin:.75rem 0}
  p{color:#94a3b8;line-height:1.6;margin-bottom:2rem}
  .btns{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap}
  a,button{display:inline-flex;align-items:center;gap:.4rem;padding:.75rem 1.5rem;border-radius:.75rem;font-weight:600;font-size:.9rem;text-decoration:none;border:none;cursor:pointer;transition:.15s}
  .primary{background:#10b981;color:#020617}
  .primary:hover{background:#34d399}
  .secondary{background:rgba(255,255,255,.08);color:#f1f5f9}
  .secondary:hover{background:rgba(255,255,255,.14)}
</style>
</head>
<body>
<div class="wrap">
  <div class="code">500</div>
  <h1>Something Went Wrong</h1>
  <p>Our server hit an unexpected error. We&rsquo;ve been notified and are looking into it &mdash; please try again in a moment.</p>
  <div class="btns">
    <a href="/" class="primary">&#8962; Go Home</a>
    <button onclick="location.reload()" class="secondary">&#8635; Try Again</button>
  </div>
</div>
</body>
</html>
