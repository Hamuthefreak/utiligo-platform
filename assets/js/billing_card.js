document.addEventListener('DOMContentLoaded', function () {
  function initCardNumber(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function (e) {
      var digits = e.target.value.replace(/\D/g, '').slice(0, 16);
      e.target.value = digits.replace(/(.{4})/g, '$1 ').trim();
    });
  }

  function initExpiry(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function (e) {
      var digits = e.target.value.replace(/\D/g, '').slice(0, 4);
      e.target.value = digits.length >= 3 ? digits.slice(0, 2) + '/' + digits.slice(2) : digits;
    });
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && e.target.value.endsWith('/')) {
        e.target.value = e.target.value.slice(0, -1);
      }
    });
  }

  function initCvc(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function (e) {
      e.target.value = e.target.value.replace(/\D/g, '').slice(0, 4);
    });
  }

  // Pro form
  initCardNumber('cardNumberInput');
  initExpiry('cardExpiryInput');
  initCvc('cardCvcInput');

  // Entrepreneur form
  initCardNumber('cardNumberInputEnt');
  initExpiry('cardExpiryInputEnt');
  initCvc('cardCvcInputEnt');

  // Visual-only loading state — do NOT disable the button (breaks POST in Chrome/Safari)
  ['billingForm', 'entForm'].forEach(function (formId) {
    var form = document.getElementById(formId);
    if (!form) return;
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.style.opacity = '0.6';
        btn.style.pointerEvents = 'none';
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i>Processing...';
      }
    });
  });
});
