document.addEventListener('DOMContentLoaded', function () {
  const calc       = document.getElementById('calculator');
  const sitesSlider = document.getElementById('sitesSlider');
  const priceSlider = document.getElementById('priceSlider');
  const sitesValue  = document.getElementById('sitesValue');
  const priceValue  = document.getElementById('priceValue');
  const breakdownText = document.getElementById('breakdownText');
  const netProfit   = document.getElementById('netProfit');
  const annualProfit= document.getElementById('annualProfit');
  if (!sitesSlider || !priceSlider) return;

  // Pro plan price is injected by PHP (data-sub-price on the section) so the
  // calculator stays in sync if pricing ever changes.
  const SUBSCRIPTION_COST = calc && calc.dataset.subPrice ? parseFloat(calc.dataset.subPrice) : 21.99;

  const fmt = n => '$' + Math.max(0, n).toLocaleString(undefined, { maximumFractionDigits: 0 });

  /* The two headline figures count to their new value rather than snapping to
     it, so dragging the handle moves the money with your hand instead of
     flicking between numbers.

     The tween itself lives in ui-theme.js, because the same routine animates a
     price when it scrolls into view on the pricing table. If that file is
     missing or blocked, this falls back to writing the value directly — the
     numbers are still correct, they just arrive instantly. */
  const tween = (window.UtligoMotion && window.UtligoMotion.tween) || function (el, to) {
    if (el) el.textContent = fmt(to);
  };

  function recalculate() {
    const sites = parseInt(sitesSlider.value, 10);
    const price = parseInt(priceSlider.value, 10);
    const gross = sites * price;
    const net   = gross - SUBSCRIPTION_COST;
    const annual = Math.max(0, net * 12);

    sitesValue.textContent = sites;
    priceValue.textContent = '$' + price;
    breakdownText.textContent = sites + ' websites x $' + price + ' = $' + gross.toLocaleString();
    // 380ms: short enough that the figure never lags far behind the thumb,
    // long enough to read as motion. Whole dollars only — this is a projection,
    // not an invoice, and cents make it feel like one.
    tween(netProfit, Math.max(0, net), { prefix: '$', decimals: 0, duration: 380 });
    if (annualProfit) tween(annualProfit, annual, { prefix: '$', decimals: 0, duration: 380 });
  }

  sitesSlider.addEventListener('input', recalculate);
  priceSlider.addEventListener('input', recalculate);
  recalculate();
});