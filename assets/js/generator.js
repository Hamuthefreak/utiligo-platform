document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('generateForm');
  const progressWrap = document.getElementById('genProgressWrap');
  const progressLabel = document.getElementById('genProgressLabel');
  const progressFill = document.getElementById('genProgressFill');
  const downloadWrap = document.getElementById('genDownloadWrap');
  const downloadLink = document.getElementById('genDownloadLink');
  const previewLink = document.getElementById('genPreviewLink');
  const csrfToken = document.body.dataset.csrf;
  const templateInput = document.getElementById('selectedTemplateInput');
  const templateLabel = document.getElementById('selectedTemplateLabel');
  const templateCards = document.querySelectorAll('.template-card');

  function selectTemplate(card) {
    templateCards.forEach((c) => c.classList.remove('border-emerald-400', 'ring-2', 'ring-emerald-400/40'));
    card.classList.add('border-emerald-400', 'ring-2', 'ring-emerald-400/40');
    if (templateInput) templateInput.value = card.dataset.template;
    if (templateLabel) templateLabel.textContent = card.dataset.label;
  }

  templateCards.forEach((card) => {
    card.addEventListener('click', () => selectTemplate(card));
  });
  if (templateCards.length) selectTemplate(templateCards[0]);

  if (!form) return;

  const steps = [
    { pct: 15, label: 'Analyzing business info...' },
    { pct: 35, label: 'Building Home page...' },
    { pct: 50, label: 'Building About & Services...' },
    { pct: 70, label: 'Building Gallery & Contact...' },
    { pct: 90, label: 'Packaging ZIP file...' },
    { pct: 100, label: 'Done!' },
  ];

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    form.classList.add('hidden');
    progressWrap.classList.remove('hidden');

    let stepIndex = 0;
    const interval = setInterval(() => {
      if (stepIndex < steps.length - 1) {
        progressFill.style.width = steps[stepIndex].pct + '%';
        progressLabel.textContent = steps[stepIndex].label;
        stepIndex++;
      }
    }, 450);

    const formData = new FormData(form);
    const payload = {};
    formData.forEach((value, key) => { payload[key] = value; });
    payload.csrf_token = csrfToken;

    fetch('/api/generate-site.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then((r) => r.json())
      .then((data) => {
        clearInterval(interval);
        progressFill.style.width = '100%';
        progressLabel.textContent = data.success ? 'Done!' : 'Error';
        if (data.success) {
          setTimeout(() => {
            progressWrap.classList.add('hidden');
            downloadWrap.classList.remove('hidden');
            downloadLink.href = data.zip_url;
            if (previewLink && data.preview_url) previewLink.href = data.preview_url;
            const editLink = document.getElementById('genEditLink');
            if (editLink && data.site_id) editLink.href = '/portal/site_editor.php?site_id=' + data.site_id;

            const shareWrap = document.getElementById('genShareLinkWrap');
            const shareInput = document.getElementById('genShareLinkInput');
            const shareCopyBtn = document.getElementById('genShareLinkCopy');
            if (shareWrap && shareInput && data.public_url && data.share_links_enabled) {
              const fullUrl = window.location.origin + data.public_url;
              shareInput.value = fullUrl;
              shareWrap.classList.remove('hidden');
              shareCopyBtn.addEventListener('click', function () {
                navigator.clipboard.writeText(fullUrl).then(() => {
                  const original = shareCopyBtn.textContent;
                  shareCopyBtn.textContent = 'Copied!';
                  setTimeout(() => { shareCopyBtn.textContent = original; }, 1500);
                });
              });
            }
          }, 400);
        } else {
          progressLabel.textContent = data.error || 'Generation failed.';
        }
      })
      .catch(() => {
        clearInterval(interval);
        progressLabel.textContent = 'Something went wrong. Please try again.';
      });
  });
});
