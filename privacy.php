<?php
/**
 * privacy.php — Placeholder legal page. Add your actual Privacy Policy content
 * below inside the <div class="prose ..."> block. This file is wired up
 * and linked from the site footer already — just fill in the text.
 */
$pageTitle = 'Privacy Policy — Utiligo';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-3xl mx-auto px-6 py-16">
  <h1 class="text-3xl font-bold mb-2">Privacy Policy</h1>
  <p class="text-slate-500 text-sm mb-10">Last updated: <?= date('F j, Y') ?></p>

  <div class="prose prose-invert prose-slate max-w-none text-slate-300 leading-relaxed space-y-4">
    <p class="text-slate-500 italic">Add your privacy policy content here.</p>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
