document.addEventListener('DOMContentLoaded', function () {
  const sitesSlider = document.getElementById('sitesSlider');
  const priceSlider = document.getElementById('priceSlider');
  const sitesValue = document.getElementById('sitesValue');
  const priceValue = document.getElementById('priceValue');
  const breakdownText = document.getElementById('breakdownText');
  const netProfit = document.getElementById('netProfit');
  if (!sitesSlider || !priceSlider) return;

  const SUBSCRIPTION_COST = 21.99;

  function recalculate() {
    const sites = parseInt(sitesSlider.value, 10);
    const price = parseInt(priceSlider.value, 10);
    const gross = sites * price;
    const net = gross - SUBSCRIPTION_COST;

    sitesValue.textContent = sites;
    priceValue.textContent = '$' + price;
    breakdownText.textContent = sites + ' websites x $' + price + ' = $' + gross.toLocaleString();
    netProfit.textContent = '$' + Math.max(0, net).toLocaleString(undefined, {maximumFractionDigits: 0});
  }

  sitesSlider.addEventListener('input', recalculate);
  priceSlider.addEventListener('input', recalculate);
  recalculate();
});
