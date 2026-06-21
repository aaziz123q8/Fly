const CURRENCY = {
  GBP: { symbol: '£', name: 'GBP', rate: 1 },
  KWD: { symbol: 'د.ك', name: 'KWD', rate: 0.40 },
  SAR: { symbol: 'ر.س', name: 'SAR', rate: 4.75 },
  USD: { symbol: '$', name: 'USD', rate: 1.27 },
};

let currentCurrency = localStorage.getItem('flymasar_currency') || 'GBP';

function setCurrency(code) {
  if (!CURRENCY[code]) return;
  currentCurrency = code;
  localStorage.setItem('flymasar_currency', code);
  document.querySelectorAll('.currency-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.currency === code);
  });
  document.querySelectorAll('[data-price-gbp]').forEach(el => {
    const gbp = parseFloat(el.dataset.priceGbp);
    if (!isNaN(gbp)) el.textContent = formatPrice(gbp);
  });
}

function formatPrice(amountGBP, currencyCode) {
  const code = currencyCode || currentCurrency;
  const c = CURRENCY[code];
  if (!c) return amountGBP.toFixed(2);
  const converted = amountGBP * c.rate;
  return c.symbol + ' ' + converted.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function initCurrency() {
  setCurrency(currentCurrency);
}
