<?php http_response_code(500); ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Server Error — Utiligo</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap" rel="stylesheet">
<style><?php include __DIR__ . '/error_styles.css.php'; ?></style>
</head><body>
<div class="wrap">
  <div class="code">500</div>
  <h1>Something Went Wrong</h1>
  <p>Our server hit an unexpected error. We’ve been notified — please try again in a moment.</p>
  <a href="/" class="btn">Go Home</a>
</div>
</body></html>
