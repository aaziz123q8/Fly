/* ============================================================================
   FlyMasar — Mobile Price Bar
   ----------------------------------------------------------------------------
   A fixed bottom price bar shown on phones across the booking funnel
   (flight/hotel/package → booking → payment). It REPLACES the app bottom tab
   bar during checkout: shows the running total, a "التفاصيل" button that opens
   a price-breakdown sheet, and a primary action button (متابعة → next step,
   or الدفع on the payment page).

   API:
     PriceBar.show({ items:[{label, amount}], discount:{label, amount},
                     total, note, actionText, onAction | actionHref, disabled })
     PriceBar.update(partialCfg)   // patch state + refresh
     PriceBar.hide()
   Amounts are in GBP; formatted via window.formatPrice (currency.js) when
   present so they display in the customer's chosen currency.
   Desktop (>=769px) never shows the bar — those pages keep their own summary.
   ==========================================================================*/
(function () {
  'use strict';

  var CSS =
    ".pb-bar{position:fixed;left:0;right:0;bottom:0;z-index:1250;background:#fff;border-top:1px solid #ECE8F4;" +
    "box-shadow:0 -8px 30px -12px rgba(76,29,149,.4);display:none;align-items:center;gap:10px;" +
    "padding:10px 14px calc(10px + env(safe-area-inset-bottom,0))}" +
    ".pb-price{flex:1;min-width:0;text-align:center}" +
    ".pb-total{font-size:1.25rem;font-weight:800;color:#4C1D95;line-height:1.1;white-space:nowrap}" +
    ".pb-note{font-size:.64rem;color:#6B6880;margin-top:2px;line-height:1.3}" +
    ".pb-details{background:#F4EEFE;color:#7C3AED;border:0;border-radius:12px;padding:10px 12px;font:inherit;" +
    "font-weight:800;font-size:.8rem;cursor:pointer;white-space:nowrap;flex-shrink:0;display:flex;align-items:center;gap:5px}" +
    ".pb-caret{font-size:.62rem;transition:transform .2s}" +
    ".pb-bar.pb-open .pb-caret{transform:rotate(180deg)}" +
    ".pb-action{background:linear-gradient(120deg,#7C3AED,#9333EA 55%,#C026D3);color:#fff;border:0;border-radius:13px;" +
    "padding:13px 22px;font:inherit;font-weight:800;font-size:1rem;cursor:pointer;white-space:nowrap;flex-shrink:0;" +
    "box-shadow:0 12px 24px -12px rgba(124,58,237,.8)}" +
    ".pb-action:active{transform:scale(.98)}" +
    ".pb-action:disabled{opacity:.55;cursor:not-allowed;box-shadow:none}" +
    "body.pb-on{padding-bottom:calc(78px + env(safe-area-inset-bottom,0))!important}" +
    ".pb-ov{position:fixed;inset:0;background:rgba(20,8,45,.5);z-index:1260;display:none}" +
    ".pb-ov.open{display:block}" +
    ".pb-sheet{position:fixed;left:0;right:0;bottom:0;z-index:1270;background:#fff;border-radius:22px 22px 0 0;" +
    "padding:8px 18px calc(20px + env(safe-area-inset-bottom,0));transform:translateY(100%);" +
    "transition:transform .26s cubic-bezier(.4,0,.2,1);box-shadow:0 -20px 50px -18px rgba(46,16,101,.5);max-height:80vh;overflow:auto}" +
    ".pb-sheet.open{transform:translateY(0)}" +
    ".pb-grip{width:44px;height:5px;border-radius:5px;background:#ECE8F4;margin:6px auto 12px}" +
    ".pb-sheet-h{font-weight:800;color:#4C1D95;font-size:1.05rem;text-align:center;margin-bottom:10px}" +
    ".pb-row{display:flex;justify-content:space-between;gap:12px;padding:11px 2px;border-bottom:1px solid #F1ECFA;font-size:.92rem;color:#1B172B}" +
    ".pb-row>span:last-child{font-weight:700;white-space:nowrap}" +
    ".pb-row .pb-sub{display:block;font-size:.72rem;color:#9a93ad;font-weight:400;margin-top:2px}" +
    ".pb-disc{color:#16a34a}.pb-disc>span:last-child{color:#16a34a}" +
    ".pb-total-row{border-bottom:0;margin-top:8px;padding-top:14px;border-top:2px solid #7C3AED;font-size:1.12rem;font-weight:800;color:#4C1D95}" +
    ".pb-total-row>span:last-child{color:#7C3AED}" +
    "@media(min-width:769px){.pb-bar,.pb-sheet,.pb-ov{display:none!important}body.pb-on{padding-bottom:0!important}}";

  var mounted = false, sheetOpen = false, state = null;

  function fmt(gbp) {
    var n = (+gbp || 0);
    if (typeof window.formatPrice === 'function') { try { return window.formatPrice(n); } catch (e) {} }
    return '£' + (Math.round(n * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  function computeTotal() {
    if (state && typeof state.total === 'number') return state.total;
    var t = 0;
    (state.items || []).forEach(function (it) { t += (+it.amount || 0); });
    if (state && state.discount) t -= Math.abs(+state.discount.amount || 0);
    return Math.max(0, t);
  }

  function ensure() {
    if (mounted) return;
    var s = document.createElement('style'); s.id = 'pb-style'; s.textContent = CSS; document.head.appendChild(s);

    var bar = document.createElement('div'); bar.className = 'pb-bar'; bar.id = 'pbBar';
    bar.innerHTML =
      '<button type="button" class="pb-details" id="pbDetails">التفاصيل <span class="pb-caret">▾</span></button>' +
      '<div class="pb-price"><div class="pb-total" id="pbTotal">—</div><div class="pb-note" id="pbNote"></div></div>' +
      '<button type="button" class="pb-action" id="pbAction">متابعة</button>';
    document.body.appendChild(bar);

    var ov = document.createElement('div'); ov.className = 'pb-ov'; ov.id = 'pbOv'; document.body.appendChild(ov);
    var sheet = document.createElement('div'); sheet.className = 'pb-sheet'; sheet.id = 'pbSheet';
    sheet.innerHTML = '<div class="pb-grip"></div><div class="pb-sheet-h">تفاصيل السعر</div><div id="pbSheetList"></div>';
    document.body.appendChild(sheet);

    document.getElementById('pbDetails').addEventListener('click', toggleSheet);
    ov.addEventListener('click', closeSheet);
    document.getElementById('pbAction').addEventListener('click', function () {
      if (!state) return;
      if (typeof state.onAction === 'function') state.onAction();
      else if (state.actionHref) window.location.href = state.actionHref;
    });
    mounted = true;
  }

  function renderSheet() {
    var list = document.getElementById('pbSheetList'); if (!list || !state) return;
    var html = '';
    (state.items || []).forEach(function (it) {
      var sub = it.sub ? '<span class="pb-sub">' + it.sub + '</span>' : '';
      html += '<div class="pb-row"><span>' + it.label + sub + '</span><span>' + (it.amount === 0 ? 'مجانًا' : fmt(it.amount)) + '</span></div>';
    });
    if (state.discount && Math.abs(+state.discount.amount || 0) > 0) {
      html += '<div class="pb-row pb-disc"><span>' + (state.discount.label || 'الخصم') + '</span><span>− ' + fmt(Math.abs(state.discount.amount)) + '</span></div>';
    }
    html += '<div class="pb-row pb-total-row"><span>الإجمالي</span><span>' + fmt(computeTotal()) + '</span></div>';
    list.innerHTML = html;
  }

  function toggleSheet() { sheetOpen ? closeSheet() : openSheet(); }
  function openSheet() {
    renderSheet();
    document.getElementById('pbSheet').classList.add('open');
    document.getElementById('pbOv').classList.add('open');
    document.getElementById('pbBar').classList.add('pb-open');
    sheetOpen = true;
  }
  function closeSheet() {
    if (!mounted) return;
    document.getElementById('pbSheet').classList.remove('open');
    document.getElementById('pbOv').classList.remove('open');
    document.getElementById('pbBar').classList.remove('pb-open');
    sheetOpen = false;
  }

  function paint() {
    document.getElementById('pbTotal').textContent = fmt(computeTotal());
    document.getElementById('pbNote').textContent = state.note || '';
    document.getElementById('pbAction').textContent = state.actionText || 'متابعة';
    document.getElementById('pbAction').disabled = !!state.disabled;
    if (sheetOpen) renderSheet();
  }

  window.PriceBar = {
    show: function (cfg) {
      ensure();
      state = cfg || {};
      document.getElementById('pbBar').style.display = 'flex';
      document.body.classList.add('pb-on');
      paint();
    },
    update: function (cfg) {
      if (!mounted) { this.show(cfg || {}); return; }
      state = Object.assign(state || {}, cfg || {});
      document.getElementById('pbBar').style.display = 'flex';
      document.body.classList.add('pb-on');
      paint();
    },
    hide: function () {
      if (mounted) { document.getElementById('pbBar').style.display = 'none'; closeSheet(); }
      document.body.classList.remove('pb-on');
    },
    total: computeTotal
  };
})();
