document.addEventListener('DOMContentLoaded', function () {
  const csrfToken = document.body.dataset.csrf;
  const uploadStatus = document.getElementById('uploadStatus');
  const slots = document.querySelectorAll('.upload-slot');

  if (!slots.length) return;

  function setStatus(msg, isError) {
    if (!uploadStatus) return;
    uploadStatus.textContent = msg;
    uploadStatus.className = 'text-xs ' + (isError ? 'text-red-400' : 'text-emerald-400');
    if (msg) setTimeout(() => { uploadStatus.textContent = ''; }, 3000);
  }

  function uploadFile(file, slotName) {
    const formData = new FormData();
    formData.append('image', file);
    formData.append('slot', slotName);
    formData.append('csrf_token', csrfToken);

    return fetch('/api/upload-image.php', { method: 'POST', body: formData })
      .then((r) => r.json());
  }

  slots.forEach((slotEl) => {
    const slotName = slotEl.dataset.slot;
    const dropzone = slotEl.querySelector('.dropzone');
    const input = slotEl.querySelector('.upload-input');
    const resultInput = slotEl.querySelector('.upload-result-input');
    const isMulti = dropzone.dataset.multi === '1';
    const singlePreview = slotEl.querySelector('.upload-preview');
    const galleryGrid = slotEl.querySelector('.gallery-preview-grid');
    const placeholder = slotEl.querySelector('.upload-placeholder');

    let galleryUrls = [];

    dropzone.addEventListener('click', () => input.click());

    ['dragenter', 'dragover'].forEach((evt) => {
      dropzone.addEventListener(evt, (e) => {
        e.preventDefault();
        dropzone.classList.add('dragover');
      });
    });
    ['dragleave', 'drop'].forEach((evt) => {
      dropzone.addEventListener(evt, (e) => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
      });
    });
    dropzone.addEventListener('drop', (e) => {
      handleFiles(e.dataTransfer.files);
    });
    input.addEventListener('change', (e) => {
      handleFiles(e.target.files);
    });

    function handleFiles(fileList) {
      const files = Array.from(fileList).filter((f) => f.type.startsWith('image/'));
      if (!files.length) return;

      if (!isMulti) {
        const file = files[0];
        setStatus('Uploading...', false);
        uploadFile(file, slotName).then((data) => {
          if (data.success) {
            resultInput.value = data.url;
            singlePreview.src = data.url;
            singlePreview.classList.remove('hidden');
            placeholder.classList.add('hidden');
            setStatus('Image uploaded!', false);
          } else {
            setStatus(data.error || 'Upload failed.', true);
          }
        }).catch(() => setStatus('Upload failed.', true));
      } else {
        const limited = files.slice(0, 6 - galleryUrls.length);
        setStatus('Uploading ' + limited.length + ' image(s)...', false);
        Promise.all(limited.map((f) => uploadFile(f, slotName))).then((results) => {
          const successes = results.filter((r) => r.success);
          successes.forEach((r) => galleryUrls.push(r.url));
          resultInput.value = JSON.stringify(galleryUrls);
          renderGalleryPreview();
          const failedCount = results.length - successes.length;
          setStatus(failedCount ? (failedCount + ' image(s) failed to upload.') : 'Images uploaded!', !!failedCount);
        }).catch(() => setStatus('Upload failed.', true));
      }
    }

    function renderGalleryPreview() {
      galleryGrid.innerHTML = '';
      galleryUrls.forEach((url) => {
        const img = document.createElement('img');
        img.src = url;
        img.className = 'w-full h-full object-cover';
        galleryGrid.appendChild(img);
      });
      galleryGrid.classList.remove('hidden');
      placeholder.classList.add('hidden');
    }
  });
});
