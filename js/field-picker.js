/* ──────────────────────────────────────────────────────────────────────────
   FlyMasar — FieldPicker: professional pop-up pickers for traveler data.

   Progressive enhancement — the native <select>/<input> stays in the DOM as
   the source of truth (so all existing validation, prefill and submit logic
   keeps working); we only overlay a comfortable, auto-closing UI:

     • enhanceSelect(sel)      → tap-to-open bottom-sheet (mobile) / modal
                                 (desktop) list, searchable for long lists,
                                 taps select + auto-close.
     • enhanceDMY(wrapEl)      → a wrapper holding 3 <select> (day, month,
                                 year) becomes one trigger opening a 3-column
                                 sheet; auto-closes once all three are chosen.
     • scan(root)             → enhances every [data-fp] select and
                                 [data-fp-dmy] group inside root.
     • sync(root)             → re-reads triggers from their controls (call
                                 after programmatic prefill).

   Selecting always writes the native value and dispatches a 'change' event so
   the page's own onchange handlers (updateDob, updateDocDate, …) still run.
   ────────────────────────────────────────────────────────────────────────── */
(function () {
  'use strict';
  if (window.FieldPicker) return;

  // ── one-time injected styles ──────────────────────────────────────────────
  if (!document.getElementById('fp-styles')) {
    var css =
      ".fp-trigger{display:flex;align-items:center;justify-content:space-between;gap:8px;width:100%;" +
        "padding:13px 15px;border:1.5px solid var(--line,#ECE8F4);border-radius:14px;background:#faf8ff;" +
        "color:var(--ink,#1B172B);font:inherit;font-size:.95rem;cursor:pointer;text-align:right;transition:.2s}" +
      ".fp-trigger:focus,.fp-trigger.fp-open{outline:none;border-color:var(--v1,#7C3AED);background:#fff;" +
        "box-shadow:0 0 0 3px rgba(124,58,237,.14)}" +
      ".fp-trigger.fp-err{border-color:#ef4444;box-shadow:0 0 0 3px rgba(239,68,68,.14)}" +
      ".fp-trigger .fp-val{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}" +
      ".fp-trigger.fp-empty .fp-val{color:#9b95ad}" +
      ".fp-trigger .fp-chev{width:18px;height:18px;flex-shrink:0;color:#9b8fc7;transition:transform .2s}" +
      ".fp-trigger.fp-open .fp-chev{transform:rotate(180deg)}" +
      ".fp-ovl{position:fixed;inset:0;z-index:3000;background:rgba(30,16,60,.42);backdrop-filter:blur(2px);" +
        "display:flex;align-items:flex-end;justify-content:center;opacity:0;transition:opacity .18s}" +
      ".fp-ovl.fp-in{opacity:1}" +
      ".fp-sheet{width:100%;max-width:560px;background:#fff;border-radius:24px 24px 0 0;max-height:85vh;" +
        "display:flex;flex-direction:column;box-shadow:0 -20px 60px -20px rgba(46,16,101,.5);" +
        "transform:translateY(100%);transition:transform .24s cubic-bezier(.22,1,.36,1);overflow:hidden}" +
      ".fp-ovl.fp-in .fp-sheet{transform:translateY(0)}" +
      ".fp-grab{width:44px;height:5px;border-radius:3px;background:#e4def2;margin:10px auto 4px}" +
      ".fp-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 18px 12px}" +
      ".fp-title{font-weight:800;font-size:1.05rem;color:var(--ink,#1B172B)}" +
      ".fp-x{background:#f3eefe;border:0;width:34px;height:34px;border-radius:11px;cursor:pointer;color:#6B6880;" +
        "font-size:1.1rem;display:flex;align-items:center;justify-content:center}" +
      ".fp-searchwrap{padding:0 18px 10px}" +
      ".fp-search{width:100%;padding:12px 14px;border:1.5px solid var(--line,#ECE8F4);border-radius:13px;" +
        "font:inherit;font-size:.95rem;background:#faf8ff}" +
      ".fp-search:focus{outline:none;border-color:var(--v1,#7C3AED);background:#fff}" +
      ".fp-list{overflow-y:auto;padding:2px 12px 16px;-webkit-overflow-scrolling:touch}" +
      ".fp-opt{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 14px;" +
        "border-radius:13px;cursor:pointer;font-size:.98rem;color:var(--ink,#1B172B);min-height:52px}" +
      ".fp-opt:hover{background:#f6f1ff}" +
      ".fp-opt.fp-sel{background:linear-gradient(120deg,#7C3AED,#9333EA);color:#fff;font-weight:700}" +
      ".fp-opt .fp-tick{width:20px;height:20px;flex-shrink:0;opacity:0}" +
      ".fp-opt.fp-sel .fp-tick{opacity:1}" +
      ".fp-empty-msg{text-align:center;color:#9b95ad;padding:26px 0;font-size:.9rem}" +
      ".fp-cols{display:grid;grid-template-columns:1fr 1.4fr 1fr;gap:8px;padding:4px 16px 8px;height:44vh;min-height:240px}" +
      ".fp-col{display:flex;flex-direction:column;overflow:hidden;border:1.5px solid var(--line,#ECE8F4);border-radius:16px}" +
      ".fp-col-h{text-align:center;font-size:.78rem;font-weight:700;color:#6B6880;padding:8px 0;background:#faf8ff;" +
        "border-bottom:1px solid var(--line,#ECE8F4);position:sticky;top:0}" +
      ".fp-col-scroll{overflow-y:auto;padding:4px;-webkit-overflow-scrolling:touch}" +
      ".fp-ci{text-align:center;padding:11px 4px;border-radius:10px;cursor:pointer;font-size:.95rem;color:var(--ink,#1B172B)}" +
      ".fp-ci:hover{background:#f6f1ff}" +
      ".fp-ci.fp-sel{background:linear-gradient(120deg,#7C3AED,#9333EA);color:#fff;font-weight:800}" +
      ".fp-done{margin:6px 16px 18px;padding:14px;border:0;border-radius:15px;font:inherit;font-weight:800;" +
        "font-size:1.02rem;color:#fff;cursor:pointer;background:linear-gradient(120deg,#7C3AED,#9333EA 60%,#C026D3)}" +
      ".fp-done[disabled]{opacity:.5;cursor:not-allowed}" +
      "@media(min-width:720px){.fp-ovl{align-items:center}.fp-sheet{border-radius:24px;max-height:80vh}" +
        ".fp-ovl.fp-in .fp-sheet{transform:translateY(0) scale(1)}" +
        ".fp-sheet{transform:translateY(16px) scale(.98)}.fp-grab{display:none}}";
    var st = document.createElement('style');
    st.id = 'fp-styles';
    st.textContent = css;
    document.head.appendChild(st);
  }

  var CHEV = '<svg class="fp-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>';
  var TICK = '<svg class="fp-tick" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>';

  function labelFor(el) {
    // explicit override
    if (el.getAttribute('data-fp-title')) return el.getAttribute('data-fp-title');
    // nearest preceding .form-label within the same form-group
    var grp = el.closest('.form-group') || el.parentElement;
    var lab = grp && grp.querySelector('.form-label');
    if (lab) return lab.textContent.replace(/\*+/g, '').replace(/\(.*?\)/g, '').trim();
    return 'اختر';
  }

  var openSheet = null; // currently mounted overlay

  function closeSheet() {
    if (!openSheet) return;
    var ovl = openSheet;
    openSheet = null;
    ovl.classList.remove('fp-in');
    setTimeout(function () { if (ovl.parentNode) ovl.parentNode.removeChild(ovl); }, 220);
    document.body.style.overflow = '';
  }

  function mountSheet(inner) {
    if (openSheet) closeSheet();
    var ovl = document.createElement('div');
    ovl.className = 'fp-ovl';
    ovl.setAttribute('dir', 'rtl');
    var sheet = document.createElement('div');
    sheet.className = 'fp-sheet';
    sheet.appendChild(el('<div class="fp-grab"></div>'));
    inner(sheet);
    ovl.appendChild(sheet);
    ovl.addEventListener('click', function (e) { if (e.target === ovl) closeSheet(); });
    document.body.appendChild(ovl);
    document.body.style.overflow = 'hidden';
    openSheet = ovl;
    // trigger transition
    requestAnimationFrame(function () { ovl.classList.add('fp-in'); });
    return sheet;
  }

  function el(html) {
    var t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstChild;
  }

  // ── LIST (single-select) ──────────────────────────────────────────────────
  function optionsOf(sel) {
    return Array.prototype.map.call(sel.options, function (o) {
      return { value: o.value, label: o.textContent.trim() };
    }).filter(function (o) { return o.value !== ''; });
  }

  function triggerLabel(sel) {
    var o = sel.options[sel.selectedIndex];
    if (o && o.value !== '') return { text: o.textContent.trim(), empty: false };
    var ph = sel.options[0] && sel.options[0].value === '' ? sel.options[0].textContent.trim() : 'اختر';
    return { text: ph, empty: true };
  }

  function makeTrigger(sel) {
    var trg = el('<button type="button" class="fp-trigger"><span class="fp-val"></span>' + CHEV + '</button>');
    sel.style.display = 'none';
    sel.setAttribute('data-fp-bound', '1');
    sel.parentNode.insertBefore(trg, sel);
    sel._fpTrigger = trg;
    refreshTrigger(sel);
    return trg;
  }

  function refreshTrigger(sel) {
    var trg = sel._fpTrigger;
    if (!trg) return;
    var l = triggerLabel(sel);
    trg.querySelector('.fp-val').textContent = l.text;
    trg.classList.toggle('fp-empty', l.empty);
  }

  // A required control that is display:none triggers the browser's
  // "invalid form control is not focusable" error and blocks submit — so we
  // move `required` onto our own bookkeeping and validate via FieldPicker.validate.
  function stripRequired(el) {
    if (el.required || el.hasAttribute('required')) {
      el.required = false;
      el.removeAttribute('required');
      el.setAttribute('data-fp-req', '1');
    }
  }

  function enhanceSelect(sel) {
    if (!sel || sel.getAttribute('data-fp-bound')) return;
    var trg = makeTrigger(sel);
    stripRequired(sel);
    var title = labelFor(sel);
    var searchable = sel.hasAttribute('data-fp-search') || sel.options.length > 9;

    trg.addEventListener('click', function () {
      trg.classList.add('fp-open');
      trg.classList.remove('fp-err');
      var opts = optionsOf(sel);
      mountSheet(function (sheet) {
        sheet.appendChild(el(
          '<div class="fp-head"><div class="fp-title">' + esc(title) + '</div>' +
          '<button type="button" class="fp-x" aria-label="إغلاق">✕</button></div>'));
        sheet.querySelector('.fp-x').addEventListener('click', done);

        var searchInput = null;
        if (searchable) {
          var sw = el('<div class="fp-searchwrap"><input class="fp-search" type="text" placeholder="ابحث…" inputmode="search"></div>');
          sheet.appendChild(sw);
          searchInput = sw.querySelector('.fp-search');
        }

        var list = el('<div class="fp-list"></div>');
        sheet.appendChild(list);

        function render(filter) {
          list.innerHTML = '';
          var f = (filter || '').trim().toLowerCase();
          var shown = 0;
          opts.forEach(function (o) {
            if (f && o.label.toLowerCase().indexOf(f) === -1) return;
            shown++;
            var row = el('<div class="fp-opt"><span>' + esc(o.label) + '</span>' + TICK + '</div>');
            if (o.value === sel.value) row.classList.add('fp-sel');
            row.addEventListener('click', function () { pick(o.value); });
            list.appendChild(row);
          });
          if (!shown) list.appendChild(el('<div class="fp-empty-msg">لا نتائج مطابقة</div>'));
        }
        render('');
        if (searchInput) {
          searchInput.addEventListener('input', function () { render(searchInput.value); });
          // desktop convenience — focus search; skip on touch to avoid keyboard jump
          if (!('ontouchstart' in window)) setTimeout(function () { searchInput.focus(); }, 60);
        }

        function pick(v) {
          if (sel.value !== v) {
            sel.value = v;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
          }
          refreshTrigger(sel);
          done();
        }
        function done() { trg.classList.remove('fp-open'); closeSheet(); }
      });
    });
  }

  // ── DATE — shared 3-column sheet ──────────────────────────────────────────
  var AR_MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

  // Open the 3-column date sheet. `model` provides the ranges, the current
  // state and the commit hook — so both the select-group and single-input
  // enhancers share exactly the same UI/behaviour.
  //   model.ranges  -> { d:[{value,label}], m:[...], y:[...] }
  //   model.state() -> { d, m, y }   (string values; '' when unset)
  //   model.pick(part, value)        (commit one column)
  function openDateSheet(trg, title, model) {
    trg.classList.add('fp-open');
    trg.classList.remove('fp-err');
    mountSheet(function (sheet) {
      sheet.appendChild(el(
        '<div class="fp-head"><div class="fp-title">' + esc(title) + '</div>' +
        '<button type="button" class="fp-x" aria-label="إغلاق">✕</button></div>'));
      function finish() { trg.classList.remove('fp-open'); if (model.refresh) model.refresh(); closeSheet(); }
      sheet.querySelector('.fp-x').addEventListener('click', finish);

      var cols = el('<div class="fp-cols">' +
        '<div class="fp-col"><div class="fp-col-h">اليوم</div><div class="fp-col-scroll" data-c="d"></div></div>' +
        '<div class="fp-col"><div class="fp-col-h">الشهر</div><div class="fp-col-scroll" data-c="m"></div></div>' +
        '<div class="fp-col"><div class="fp-col-h">السنة</div><div class="fp-col-scroll" data-c="y"></div></div>' +
        '</div>');
      sheet.appendChild(cols);
      var doneBtn = el('<button type="button" class="fp-done">تم</button>');
      sheet.appendChild(doneBtn);

      var interacted = 0;
      function allSet() { var s = model.state(); return s.d && s.m && s.y; }
      function updateDone() { doneBtn.disabled = !allSet(); }

      function buildCol(part) {
        var scroll = cols.querySelector('[data-c="' + part + '"]');
        scroll.innerHTML = '';
        var cur = model.state()[part];
        model.ranges[part].forEach(function (o) {
          var item = el('<div class="fp-ci">' + esc(o.label) + '</div>');
          if (o.value === cur) item.classList.add('fp-sel');
          item.addEventListener('click', function () {
            model.pick(part, o.value);
            Array.prototype.forEach.call(scroll.children, function (c) { c.classList.remove('fp-sel'); });
            item.classList.add('fp-sel');
            if (model.refresh) model.refresh();
            updateDone();
            interacted++;
            if (allSet() && interacted >= 3) setTimeout(finish, 180);
          });
          scroll.appendChild(item);
        });
        var selEl = scroll.querySelector('.fp-sel');
        if (selEl) setTimeout(function () { selEl.scrollIntoView({ block: 'center' }); }, 30);
      }
      buildCol('d'); buildCol('m'); buildCol('y');
      updateDone();
      doneBtn.addEventListener('click', finish);
    });
  }

  // Enhance a wrapper holding 3 <select> (day, month, year).
  function enhanceDMY(wrap) {
    if (!wrap || wrap.getAttribute('data-fp-bound')) return;
    var sels = wrap.querySelectorAll('select');
    if (sels.length < 3) return;
    var dEl = sels[0], mEl = sels[1], yEl = sels[2];
    wrap.setAttribute('data-fp-bound', '1');
    var wasReq = dEl.required || mEl.required || yEl.required;
    [dEl, mEl, yEl].forEach(stripRequired);
    if (wasReq) wrap.setAttribute('data-fp-req', '1');

    var title = wrap.getAttribute('data-fp-title') || labelFor(wrap) || 'اختر التاريخ';
    var trg = el('<button type="button" class="fp-trigger"><span class="fp-val"></span>' + CHEV + '</button>');
    wrap.style.display = 'none';
    wrap.parentNode.insertBefore(trg, wrap);
    wrap._fpTrigger = trg;

    function rangeOf(sel) {
      return Array.prototype.map.call(sel.options, function (o) { return { value: o.value, label: o.textContent.trim() }; })
        .filter(function (o) { return o.value !== ''; });
    }
    function labelOf(sel) { var o = sel.options[sel.selectedIndex]; return o && o.value !== '' ? o.textContent.trim() : ''; }
    function refresh() {
      var d = labelOf(dEl), m = labelOf(mEl), y = labelOf(yEl);
      if (d && m && y) { trg.querySelector('.fp-val').textContent = d + ' / ' + m + ' / ' + y; trg.classList.remove('fp-empty'); }
      else { trg.querySelector('.fp-val').textContent = title; trg.classList.add('fp-empty'); }
    }
    wrap._fpRefresh = refresh;
    refresh();

    trg.addEventListener('click', function () {
      openDateSheet(trg, title, {
        ranges: { d: rangeOf(dEl), m: rangeOf(mEl), y: rangeOf(yEl) },
        state: function () { return { d: dEl.value, m: mEl.value, y: yEl.value }; },
        pick: function (part, v) {
          var sel = part === 'd' ? dEl : part === 'm' ? mEl : yEl;
          if (sel.value !== v) { sel.value = v; sel.dispatchEvent(new Event('change', { bubbles: true })); }
        },
        refresh: refresh
      });
    });
  }

  // Enhance a single <input type="date"> (or text) into a DMY pop-up that
  // writes an ISO 'YYYY-MM-DD' value. Year range via data-fp-min-year /
  // data-fp-max-year (defaults suit a date of birth).
  function enhanceDateInput(input) {
    if (!input || input.getAttribute('data-fp-bound')) return;
    input.setAttribute('data-fp-bound', '1');
    var nowY = new Date().getFullYear();
    var maxY = parseInt(input.getAttribute('data-fp-max-year') || nowY, 10);
    var minY = parseInt(input.getAttribute('data-fp-min-year') || (nowY - 100), 10);
    var title = input.getAttribute('data-fp-title') || labelFor(input) || 'اختر التاريخ';

    // internal state, seeded from any existing ISO value
    var st = { d: '', m: '', y: '' };
    function seed() {
      var v = (input.value || '').split('-');
      if (v.length === 3) { st.y = v[0]; st.m = v[1]; st.d = v[2]; }
    }
    seed();

    stripRequired(input);
    if (input.type === 'date') input.type = 'hidden'; else input.style.display = 'none';
    var trg = el('<button type="button" class="fp-trigger"><span class="fp-val"></span>' + CHEV + '</button>');
    input.parentNode.insertBefore(trg, input);
    input._fpTrigger = trg;

    var pad = function (n) { return String(n).padStart(2, '0'); };
    var days = []; for (var d = 1; d <= 31; d++) days.push({ value: pad(d), label: String(d) });
    var months = AR_MONTHS.map(function (m, k) { return { value: pad(k + 1), label: m }; });
    var years = []; for (var y = maxY; y >= minY; y--) years.push({ value: String(y), label: String(y) });

    function refresh() {
      if (st.d && st.m && st.y) {
        var mi = parseInt(st.m, 10) - 1;
        trg.querySelector('.fp-val').textContent = String(parseInt(st.d, 10)) + ' / ' + (AR_MONTHS[mi] || st.m) + ' / ' + st.y;
        trg.classList.remove('fp-empty');
      } else {
        trg.querySelector('.fp-val').textContent = title;
        trg.classList.add('fp-empty');
      }
    }
    function commit() {
      if (st.d && st.m && st.y) {
        var v = st.y + '-' + st.m + '-' + st.d;
        if (input.value !== v) { input.value = v; input.dispatchEvent(new Event('change', { bubbles: true })); }
      }
    }
    input._fpRefresh = function () { seed(); refresh(); };
    refresh();

    trg.addEventListener('click', function () {
      seed();
      openDateSheet(trg, title, {
        ranges: { d: days, m: months, y: years },
        state: function () { return st; },
        pick: function (part, v) { st[part] = v; commit(); },
        refresh: refresh
      });
    });
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function scan(root) {
    root = root || document;
    root.querySelectorAll('select[data-fp]:not([data-fp-bound])').forEach(enhanceSelect);
    root.querySelectorAll('[data-fp-dmy]:not([data-fp-bound])').forEach(enhanceDMY);
    root.querySelectorAll('[data-fp-date]:not([data-fp-bound])').forEach(enhanceDateInput);
  }

  function sync(root) {
    root = root || document;
    root.querySelectorAll('select[data-fp-bound]').forEach(function (s) { if (s._fpTrigger) refreshTrigger(s); });
    root.querySelectorAll('[data-fp-dmy][data-fp-bound]').forEach(function (w) { if (w._fpRefresh) w._fpRefresh(); });
    root.querySelectorAll('[data-fp-date][data-fp-bound]').forEach(function (i) { if (i._fpRefresh) i._fpRefresh(); });
  }

  // Validate every required (data-fp-req) enhanced control inside root.
  // Returns { ok:true } or { ok:false, label } and highlights the first empty one.
  function validate(root) {
    root = root || document;
    root.querySelectorAll('.fp-trigger.fp-err').forEach(function (t) { t.classList.remove('fp-err'); });
    var reqs = root.querySelectorAll('[data-fp-req]');
    for (var i = 0; i < reqs.length; i++) {
      var elm = reqs[i], empty = false;
      if (elm.hasAttribute('data-fp-dmy')) {
        var s = elm.querySelectorAll('select');
        empty = !(s[0] && s[0].value && s[1] && s[1].value && s[2] && s[2].value);
      } else {
        empty = !elm.value;
      }
      if (empty) {
        var trg = elm._fpTrigger;
        if (trg) {
          trg.classList.add('fp-err');
          try { trg.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
        }
        return { ok: false, el: elm, label: elm.getAttribute('data-fp-title') || labelFor(elm) };
      }
    }
    return { ok: true };
  }

  window.FieldPicker = { scan: scan, sync: sync, validate: validate, enhanceSelect: enhanceSelect, enhanceDMY: enhanceDMY, enhanceDateInput: enhanceDateInput, close: closeSheet };
})();
