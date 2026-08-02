document.addEventListener('DOMContentLoaded', function () {
  const form         = document.getElementById('generateForm');
  const progressWrap = document.getElementById('genProgressWrap');
  const progressLabel = document.getElementById('genProgressLabel');
  const progressFill  = document.getElementById('genProgressFill');
  const downloadWrap  = document.getElementById('genDownloadWrap');
  const downloadLink  = document.getElementById('genDownloadLink');
  const previewLink   = document.getElementById('genPreviewLink');
  const errBoundary   = document.getElementById('genErrorBoundary');
  const errMsg        = document.getElementById('genErrorMsg');
  const templateInput = document.getElementById('selectedTemplateInput');
  const templateLabel = document.getElementById('selectedTemplateLabel');
  const templateCards = document.querySelectorAll('.template-card');

  // CSRF lives on data-csrf of <body> (set by portal_layout.php)
  const csrfToken = document.body.dataset.csrf || '';

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

  // Tell the portal transition system NOT to show the page loader on this form
  form.dataset.noLoader = '1';

  const steps = [
    { pct: 15, label: 'Analyzing business info...' },
    { pct: 35, label: 'Building Home page...' },
    { pct: 50, label: 'Building About & Services...' },
    { pct: 70, label: 'Building Gallery & Contact...' },
    { pct: 90, label: 'Packaging ZIP file...' },
    { pct: 100, label: 'Done!' },
  ];

  function showError(msg) {
    if (progressWrap) progressWrap.classList.add('hidden');
    if (downloadWrap)  downloadWrap.classList.add('hidden');
    if (errMsg)        errMsg.textContent = msg || 'Something went wrong. Please try again.';
    if (errBoundary)   errBoundary.classList.remove('hidden');
  }

  const retryBtn = document.getElementById('genRetryBtn');
  if (retryBtn) {
    retryBtn.addEventListener('click', () => {
      if (errBoundary) errBoundary.classList.add('hidden');
      if (form)        form.classList.remove('hidden');
    });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    form.classList.add('hidden');
    if (errBoundary) errBoundary.classList.add('hidden');
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
    const payload  = {};
    formData.forEach((value, key) => { payload[key] = value; });
    payload.csrf_token = csrfToken;

    fetch('/api/generate-site.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then((r) => {
        if (!r.ok) throw new Error('Server error ' + r.status);
        return r.json();
      })
      .then((data) => {
        clearInterval(interval);
        if (data.success) {
          progressFill.style.width = '100%';
          progressLabel.textContent = 'Done!';
          setTimeout(() => {
            progressWrap.classList.add('hidden');
            downloadWrap.classList.remove('hidden');

            if (downloadLink) downloadLink.href = data.zip_url;
            if (previewLink && data.preview_url) previewLink.href = data.preview_url;

            const editLink = document.getElementById('genEditLink');
            if (editLink && data.site_id) editLink.href = '/portal/site_editor.php?site_id=' + data.site_id;

            const mySitesBtn = document.getElementById('genMySitesBtn');
            if (mySitesBtn) mySitesBtn.href = '/portal/my_sites.php';

            // Share link
            const shareWrap    = document.getElementById('genShareLinkWrap');
            const shareInput   = document.getElementById('genShareLinkInput');
            const shareCopyBtn = document.getElementById('genShareLinkCopy');
            if (shareWrap && shareInput && data.public_url && data.share_links_enabled) {
              const fullUrl = window.location.origin + data.public_url;
              shareInput.value = fullUrl;
              shareWrap.classList.remove('hidden');

              const qrWrap = document.getElementById('genQrWrap');
              const qrImg  = document.getElementById('genQrImg');
              if (qrWrap && qrImg) {
                qrImg.src = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&format=png&ecc=M&data='
                  + encodeURIComponent(fullUrl);
                qrImg.alt = 'QR code for ' + fullUrl;
                qrWrap.classList.remove('hidden');
              }

              if (shareCopyBtn) {
                shareCopyBtn.addEventListener('click', function () {
                  navigator.clipboard.writeText(fullUrl).then(() => {
                    const orig = shareCopyBtn.textContent;
                    shareCopyBtn.textContent = 'Copied!';
                    setTimeout(() => { shareCopyBtn.textContent = orig; }, 1500);
                  });
                });
              }
            }
          }, 400);
        } else {
          showError(data.error || 'Generation failed. Please try again.');
        }
      })
      .catch(() => {
        clearInterval(interval);
        showError('Connection error — please check your internet and try again.');
      });
  });
});
