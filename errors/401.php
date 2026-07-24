<?php http_response_code(401); ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Unauthorised — Utiligo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
<style><?php include __DIR__ . '/error_styles.css.php'; ?></style>
</head><body>
<div class="wrap">
  <div class="code">401</div>
  <h1>Unauthorised</h1>
  <p>You need to be logged in to view this page.</p>
  <a href="/login" class="btn">Sign In</a>
  <a href="/" class="btn ghost">Go Home</a>
</div>
</body></html>
