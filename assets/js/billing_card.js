document.addEventListener('DOMContentLoaded', function () {
  const cardNumberInput = document.getElementById('cardNumberInput');
  const expiryInput = document.getElementById('cardExpiryInput');
  const cvcInput = document.getElementById('cardCvcInput');

  if (cardNumberInput) {
    cardNumberInput.addEventListener('input', function (e) {
      let digits = e.target.value.replace(/\D/g, '').slice(0, 16);
      let formatted = digits.replace(/(.{4})/g, '$1 ').trim();
      e.target.value = formatted;
    });
  }

  if (expiryInput) {
    expiryInput.addEventListener('input', function (e) {
      let digits = e.target.value.replace(/\D/g, '').slice(0, 4);
      if (digits.length >= 3) {
        e.target.value = digits.slice(0, 2) + '/' + digits.slice(2);
      } else {
        e.target.value = digits;
      }
    });
    expiryInput.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && e.target.value.endsWith('/')) {
        e.target.value = e.target.value.slice(0, -1);
      }
    });
  }

  if (cvcInput) {
    cvcInput.addEventListener('input', function (e) {
      e.target.value = e.target.value.replace(/\D/g, '').slice(0, 4);
    });
  }
});
