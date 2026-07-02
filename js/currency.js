// Base (collection) currency is GBP (rate 1). Every other currency is cosmetic
// — used only to DISPLAY the price in the customer's preferred currency.
// Order here is the order shown in the launcher currency picker. More
// currencies can be added from the admin panel (they extend this map).
const CURRENCY = {
  KWD: { symbol: 'د.ك', name: 'KWD', ar: 'الدينار الكويتي',   rate: 0.40 },
  SAR: { symbol: 'ر.س', name: 'SAR', ar: 'الريال السعودي',    rate: 4.75 },
  EUR: { symbol: '€',    name: 'EUR', ar: 'اليورو',            rate: 1.17 },
  USD: { symbol: '$',    name: 'USD', ar: 'الدولار الأمريكي',  rate: 1.27 },
  GBP: { symbol: '£',    name: 'GBP', ar: 'الجنيه الإسترليني', rate: 1    },
};

// Unified key — read from either key for backwards compat, write to both
let currentCurrency = localStorage.getItem('fly_cur')
  || localStorage.getItem('flymasar_currency')
  || 'KWD';

function setCurrency(code) {
  if (!CURRENCY[code]) return;
  currentCurrency = code;
  localStorage.setItem('fly_cur', code);
  localStorage.setItem('flymasar_currency', code);
  document.querySelectorAll('.currency-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.currency === code);
  });
  // Update dropdown button label if present
  const lbl = document.getElementById('currencyLabel');
  if (lbl) lbl.textContent = code;
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

// Single source of truth for the "charged in GBP, displayed cosmetically" note,
// so flights and hotels show the EXACT same disclaimer wording everywhere.
function gbpChargeNote() {
  return (currentCurrency && currentCurrency !== 'GBP')
    ? 'يُحصَّل المبلغ بالجنيه الإسترليني (GBP) · المبالغ بعملتك للعرض فقط'
    : '';
}
