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

  function recalculate() {
    const sites = parseInt(sitesSlider.value, 10);
    const price = parseInt(priceSlider.value, 10);
    const gross = sites * price;
    const net   = gross - SUBSCRIPTION_COST;
    const annual = Math.max(0, net * 12);

    sitesValue.textContent = sites;
    priceValue.textContent = '$' + price;
    breakdownText.textContent = sites + ' websites x $' + price + ' = $' + gross.toLocaleString();
    netProfit.textContent = fmt(net);
    if (annualProfit) annualProfit.textContent = fmt(annual);
  }

  sitesSlider.addEventListener('input', recalculate);
  priceSlider.addEventListener('input', recalculate);
  recalculate();
});