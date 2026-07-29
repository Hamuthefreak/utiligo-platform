document.addEventListener('DOMContentLoaded', function () {

  function cardBrand(digits) {
    if (/^4/.test(digits))            return 'fa-brands fa-cc-visa';
    if (/^5[1-5]|^2[2-7]/.test(digits)) return 'fa-brands fa-cc-mastercard';
    if (/^3[47]/.test(digits))        return 'fa-brands fa-cc-amex';
    if (/^6(?:011|5)/.test(digits))   return 'fa-brands fa-cc-discover';
    return 'fa-regular fa-credit-card';
  }

  function initCardNumber(inputId, iconId) {
    var el = document.getElementById(inputId);
    var ic = document.getElementById(iconId);
    if (!el) return;
    el.addEventListener('input', function () {
      var digits = el.value.replace(/\D/g, '').slice(0, 16);
      el.value = digits.replace(/(.{4})/g, '$1 ').trim();
      if (ic) ic.innerHTML = '<i class="' + cardBrand(digits) + '"></i>';
    });
  }

  function initExpiry(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
      var digits = el.value.replace(/\D/g, '').slice(0, 4);
      if (digits.length > 2) {
        el.value = digits.slice(0, 2) + ' / ' + digits.slice(2);
      } else {
        el.value = digits;
      }
    });
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && el.value.endsWith(' ')) {
        el.value = el.value.trimEnd().replace(/\s*\/\s*$/, '');
      }
    });
  }

  function initCvc(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', function () {
      el.value = el.value.replace(/\D/g, '').slice(0, 4);
    });
  }

  // Pro form
  initCardNumber('cardNumberInput',    'cardBrandIconPro');
  initExpiry('cardExpiryInput');
  initCvc('cardCvcInput');

  // Entrepreneur form
  initCardNumber('cardNumberInputEnt', 'cardBrandIconEnt');
  initExpiry('cardExpiryInputEnt');
  initCvc('cardCvcInputEnt');

  // Visual-only submit state (no disabled — breaks POST in some browsers)
  ['billingForm', 'entForm'].forEach(function (formId) {
    var form = document.getElementById(formId);
    if (!form) return;
    form.addEventListener('submit', function () {
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.style.opacity = '0.65';
        btn.style.pointerEvents = 'none';
        btn.innerHTML = btn.innerHTML.replace(/<i[^>]*><\/i>/, '') +
          '<i class="fa-solid fa-spinner fa-spin mr-2"></i>';
      }
    });
  });

});
