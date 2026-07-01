/* ============================================================================
   FlyMasar — Unified Site Chrome
   ----------------------------------------------------------------------------
   ONE source of truth for the header, mobile drawer, footer and bottom nav that
   used to be copy-pasted (and diverge) across every .html page. Include this on
   any page with:

       <div id="sc-header"></div>              (optional explicit mount point)
       ...page content...
       <script src="js/site-chrome.js?v=..."></script>

   If no #sc-header element exists, the header is prepended to <body> and the
   footer + bottom-nav appended, so most pages need nothing but the <script>.

   It reuses the project's OFFICIAL shared helpers when present — setLang() /
   setCurrency() (i18n.js / currency.js) and the Auth object (auth.js) — instead
   of re-implementing them, and degrades gracefully if they are absent.

   Active page can be forced with `window.FLY_CHROME = { active: 'flights' }`
   BEFORE this script; otherwise it is inferred from the URL.
   ==========================================================================*/
(function () {
  'use strict';

  var CFG = window.FLY_CHROME || {};
  var THEME_KEY = 'flymasar_theme';

  /* --- which page are we on -------------------------------------------- */
  var path = (location.pathname || '').toLowerCase();
  function file(p) { return (p.split('/').pop() || 'index.html'); }
  var here = file(path);
  if (here === '' || here === '/') here = 'index.html';
  var active = CFG.active || ({
    'index.html': 'home',
    'flights.html': 'flights',
    'hotels.html': 'hotels',
    'hotel-detail.html': 'hotels',
    'hotels-detail.html': 'hotels',
    'dashboard.html': 'account',
    'travelers.html': 'account',
    'lookup.html': 'lookup'
  }[here] || '');

  /* --- brand mark ------------------------------------------------------ */
  var LOGO =
    '<svg class="sc-logo" viewBox="0 0 24 24" width="26" height="26" aria-hidden="true">' +
    '<circle cx="12" cy="12" r="12" fill="currentColor"/>' +
    '<path d="M18.5 12.3l-5.1-1.2-2-4.3a.9.9 0 0 0-1.65.05L7.9 10.4 5.6 11a.6.6 0 0 0-.12 1.1l2 .95.55 2.2a.5.5 0 0 0 .93.08l1.05-2 4.9 1.15a.7.7 0 0 0 .32-1.35l-3.1-1 3.9-.9a.6.6 0 0 0 .07-1.13z" fill="#fff"/>' +
    '</svg>';

  var ICONS = {
    home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>',
    flights: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5z"/></svg>',
    hotels: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V5a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v16"/><path d="M9 8h.01M15 8h.01M9 12h.01M15 12h.01M10 21v-4h4v4"/></svg>',
    account: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    lookup: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></svg>'
  };

  /* --- primary navigation model --------------------------------------- */
  var NAV = [
    { k: 'home',    href: 'index.html',     label: 'الرئيسية', icon: ICONS.home },
    { k: 'flights', href: 'flights.html',   label: 'رحلات',    icon: ICONS.flights },
    { k: 'hotels',  href: 'hotels.html',    label: 'فنادق',    icon: ICONS.hotels },
    { k: 'lookup',  href: 'lookup.html',    label: 'استعلام',  icon: ICONS.lookup }
  ];
  var CURRENCIES = [
    { code: 'KWD', label: 'د.ك' },
    { code: 'SAR', label: 'ر.س' },
    { code: 'USD', label: '$' },
    { code: 'GBP', label: '£' }
  ];

  /* --- styles (self-contained; work even without app.css) ------------- */
  var css = [
    ':root{--sc-h:64px}',
    '.sc-nav{position:sticky;top:0;z-index:900;background:var(--white,#fff);border-bottom:1px solid var(--border,#e5e7eb);box-shadow:0 1px 3px rgba(0,0,0,.05)}',
    '.sc-nav-inner{max-width:1200px;margin:0 auto;height:var(--sc-h);display:flex;align-items:center;gap:18px;padding:0 20px}',
    '.sc-brand{display:flex;align-items:center;gap:8px;font-size:1.3rem;font-weight:800;color:var(--primary,#1B4F8E);white-space:nowrap}',
    '.sc-brand b{color:var(--secondary,#E8A020);font-weight:800}',
    '.sc-logo{color:var(--primary,#1B4F8E);flex-shrink:0}',
    '.sc-links{display:flex;align-items:center;gap:4px;margin-inline-start:8px}',
    '.sc-links a{display:flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;font-size:.95rem;font-weight:600;color:var(--text-muted,#6B7280);transition:.18s}',
    '.sc-links a svg{width:18px;height:18px}',
    '.sc-links a:hover{background:var(--bg-alt,#eef1f7);color:var(--primary,#1B4F8E)}',
    '.sc-links a.on{color:var(--primary,#1B4F8E);background:rgba(27,79,142,.08)}',
    '.sc-ctrls{display:flex;align-items:center;gap:8px;margin-inline-start:auto}',
    '.sc-seg{display:inline-flex;background:var(--bg-alt,#eef1f7);border-radius:10px;padding:3px}',
    '.sc-seg button{border:0;background:none;cursor:pointer;font:inherit;font-size:.82rem;font-weight:700;color:var(--text-muted,#6B7280);padding:5px 9px;border-radius:8px;line-height:1;transition:.15s}',
    '.sc-seg button.active{background:var(--white,#fff);color:var(--primary,#1B4F8E);box-shadow:0 1px 2px rgba(0,0,0,.08)}',
    '.sc-theme{border:1.5px solid var(--border,#e5e7eb);background:none;border-radius:10px;width:36px;height:36px;cursor:pointer;font-size:1rem;line-height:1;color:var(--text-muted,#6B7280);transition:.15s}',
    '.sc-theme:hover{border-color:var(--primary,#1B4F8E);color:var(--primary,#1B4F8E)}',
    '.sc-auth{display:flex;align-items:center;gap:8px}',
    '.sc-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:.9rem;font-weight:700;cursor:pointer;border:1.5px solid transparent;transition:.18s;white-space:nowrap}',
    '.sc-btn-primary{background:var(--primary,#1B4F8E);color:#fff}',
    '.sc-btn-primary:hover{background:var(--primary-dark,#13396B);color:#fff}',
    '.sc-btn-ghost{background:none;border-color:var(--border,#e5e7eb);color:var(--text,#1A1A2E)}',
    '.sc-btn-ghost:hover{border-color:var(--primary,#1B4F8E);color:var(--primary,#1B4F8E)}',
    '.sc-burger{display:none;flex-direction:column;gap:4px;width:40px;height:40px;align-items:center;justify-content:center;border:1.5px solid var(--border,#e5e7eb);border-radius:10px;background:none;cursor:pointer;margin-inline-start:auto}',
    '.sc-burger span{display:block;width:20px;height:2px;background:var(--text,#1A1A2E);border-radius:2px}',
    /* drawer */
    '.sc-ov{position:fixed;inset:0;background:rgba(0,0,0,.45);opacity:0;visibility:hidden;transition:.25s;z-index:1000}',
    '.sc-ov.open{opacity:1;visibility:visible}',
    '.sc-drawer{position:fixed;top:0;right:0;height:100%;width:300px;max-width:85vw;background:var(--white,#fff);z-index:1001;transform:translateX(110%);transition:transform .28s ease;display:flex;flex-direction:column;box-shadow:0 0 40px rgba(0,0,0,.2)}',
    'html[dir="ltr"] .sc-drawer{right:auto;left:0;transform:translateX(-110%)}',
    '.sc-drawer.open{transform:translateX(0)}',
    '.sc-dhead{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--border,#e5e7eb)}',
    '.sc-dclose{border:0;background:none;font-size:1.4rem;cursor:pointer;color:var(--text-muted,#6B7280);line-height:1}',
    '.sc-dbody{flex:1;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:18px}',
    '.sc-dsec-t{font-size:.75rem;font-weight:700;color:var(--text-light,#9CA3AF);margin-bottom:8px;text-transform:uppercase;letter-spacing:.4px}',
    '.sc-dlink{display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:10px;font-weight:600;color:var(--text,#1A1A2E);transition:.15s}',
    '.sc-dlink svg{width:20px;height:20px;color:var(--text-muted,#6B7280)}',
    '.sc-dlink:hover,.sc-dlink.on{background:var(--bg-alt,#eef1f7);color:var(--primary,#1B4F8E)}',
    '.sc-dlink.on svg{color:var(--primary,#1B4F8E)}',
    '.sc-drow{display:flex;flex-wrap:wrap;gap:6px}',
    '.sc-drow button{flex:1;min-width:60px;border:1.5px solid var(--border,#e5e7eb);background:none;border-radius:9px;padding:9px;font:inherit;font-size:.85rem;font-weight:600;cursor:pointer;color:var(--text-muted,#6B7280)}',
    '.sc-drow button.active{border-color:var(--primary,#1B4F8E);color:var(--primary,#1B4F8E);background:rgba(27,79,142,.06)}',
    '.sc-dfoot{padding:14px 18px;border-top:1px solid var(--border,#e5e7eb);font-size:.78rem;color:var(--text-light,#9CA3AF)}',
    /* footer */
    '.sc-footer{background:var(--primary-dark,#13396B);color:#e8eef7;margin-top:56px}',
    '.sc-footer-in{max-width:1200px;margin:0 auto;padding:44px 20px 22px}',
    '.sc-fgrid{display:grid;grid-template-columns:1.6fr 1fr 1fr;gap:32px}',
    '.sc-fbrand{display:flex;align-items:center;gap:8px;font-size:1.25rem;font-weight:800;color:#fff;margin-bottom:12px}',
    '.sc-fbrand .sc-logo{color:#fff}',
    '.sc-fdesc{font-size:.9rem;line-height:1.7;color:#b9c7db;max-width:320px}',
    '.sc-footer h4{font-size:.95rem;color:#fff;margin-bottom:14px}',
    '.sc-footer ul{display:flex;flex-direction:column;gap:9px}',
    '.sc-footer a{font-size:.88rem;color:#b9c7db;transition:.15s}',
    '.sc-footer a:hover{color:#fff}',
    '.sc-fcopy{margin-top:34px;padding-top:18px;border-top:1px solid rgba(255,255,255,.12);text-align:center;font-size:.82rem;color:#93a6c2}',
    /* bottom nav */
    '.sc-bnav{display:none;position:fixed;bottom:0;left:0;right:0;background:var(--white,#fff);border-top:1px solid var(--border,#e5e7eb);z-index:900;padding:6px 0 env(safe-area-inset-bottom,6px);justify-content:space-around}',
    '.sc-bnav a{display:flex;flex-direction:column;align-items:center;gap:2px;padding:5px 14px;font-size:.68rem;font-weight:600;color:var(--text-muted,#6B7280);transition:.15s}',
    '.sc-bnav a svg{width:22px;height:22px}',
    '.sc-bnav a.on{color:var(--primary,#1B4F8E)}',
    /* responsive */
    '@media(max-width:900px){.sc-links{display:none}.sc-ctrls .sc-seg,.sc-ctrls .sc-theme,.sc-ctrls .sc-auth{display:none}.sc-burger{display:flex}}',
    '@media(max-width:768px){.sc-bnav{display:flex}body{padding-bottom:64px}.sc-fgrid{grid-template-columns:1fr;gap:26px}}',
    '[data-theme="dark"] .sc-nav{background:#1e293b;border-color:#334155}',
    '[data-theme="dark"] .sc-drawer,[data-theme="dark"] .sc-bnav{background:#1e293b;border-color:#334155}'
  ].join('');

  /* --- markup builders ------------------------------------------------- */
  function navLinksHtml(cls) {
    return NAV.map(function (n) {
      var on = n.k === active ? ' on' : '';
      return '<a class="' + cls + on + '" href="' + n.href + '">' + n.icon + '<span>' + n.label + '</span></a>';
    }).join('');
  }
  function curBtns(cls) {
    return CURRENCIES.map(function (c) {
      return '<button type="button" class="currency-btn" data-currency="' + c.code + '">' + c.label + '</button>';
    }).join('');
  }

  function authDesktopHtml() {
    var logged = (window.Auth && Auth.isLoggedIn && Auth.isLoggedIn());
    if (logged) {
      return '<a class="sc-btn sc-btn-ghost" href="dashboard.html">حسابي</a>' +
             '<button type="button" class="sc-btn sc-btn-primary" data-sc="logout">خروج</button>';
    }
    return '<a class="sc-btn sc-btn-ghost" href="login.html">دخول</a>' +
           '<a class="sc-btn sc-btn-primary" href="register.html">حساب جديد</a>';
  }
  function authDrawerHtml() {
    var logged = (window.Auth && Auth.isLoggedIn && Auth.isLoggedIn());
    if (logged) {
      return '<a class="sc-dlink" href="dashboard.html">' + ICONS.account + '<span>حسابي وحجوزاتي</span></a>' +
             '<a class="sc-dlink" href="travelers.html">' + ICONS.account + '<span>المسافرون</span></a>' +
             '<button type="button" class="sc-dlink" data-sc="logout" style="width:100%;border:0;background:none;cursor:pointer;text-align:start">' +
               '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>' +
               '<span>تسجيل الخروج</span></button>';
    }
    return '<a class="sc-btn sc-btn-primary" href="login.html" style="justify-content:center">دخول</a>' +
           '<a class="sc-btn sc-btn-ghost" href="register.html" style="justify-content:center;margin-top:8px">حساب جديد</a>';
  }

  function headerHtml() {
    return '' +
    '<header class="sc-nav">' +
      '<div class="sc-nav-inner">' +
        '<a class="sc-brand" href="index.html">' + LOGO + 'فلاي<b>مسار</b></a>' +
        '<nav class="sc-links">' + navLinksHtml('') + '</nav>' +
        '<div class="sc-ctrls">' +
          '<div class="sc-seg sc-lang">' +
            '<button type="button" class="lang-btn" data-lang="ar">ع</button>' +
            '<button type="button" class="lang-btn" data-lang="en">EN</button>' +
          '</div>' +
          '<div class="sc-seg sc-cur">' + curBtns() + '</div>' +
          '<button type="button" class="sc-theme" data-sc="theme" title="الوضع الليلي">🌙</button>' +
          '<span class="sc-auth" id="scAuth">' + authDesktopHtml() + '</span>' +
        '</div>' +
        '<button type="button" class="sc-burger" data-sc="open" aria-label="القائمة"><span></span><span></span><span></span></button>' +
      '</div>' +
    '</header>';
  }

  function drawerHtml() {
    return '' +
    '<div class="sc-ov" data-sc="close"></div>' +
    '<aside class="sc-drawer" id="scDrawer">' +
      '<div class="sc-dhead"><span class="sc-brand" style="font-size:1.15rem">' + LOGO + 'فلاي<b>مسار</b></span>' +
        '<button type="button" class="sc-dclose" data-sc="close" aria-label="إغلاق">✕</button></div>' +
      '<div class="sc-dbody">' +
        '<div id="scDrawerAuth" style="display:flex;flex-direction:column">' + authDrawerHtml() + '</div>' +
        '<div><div class="sc-dsec-t">التنقل</div>' + navLinksHtml('sc-dlink') + '</div>' +
        '<div><div class="sc-dsec-t">اللغة</div><div class="sc-drow">' +
          '<button type="button" class="lang-btn" data-lang="ar">العربية</button>' +
          '<button type="button" class="lang-btn" data-lang="en">English</button></div></div>' +
        '<div><div class="sc-dsec-t">العملة</div><div class="sc-drow">' + curBtns() + '</div></div>' +
        '<div><div class="sc-dsec-t">روابط</div>' +
          '<a class="sc-dlink" href="about.html">من نحن</a>' +
          '<a class="sc-dlink" href="services.html">خدماتنا</a>' +
          '<a class="sc-dlink" href="contact.html">اتصل بنا</a>' +
          '<a class="sc-dlink" href="privacy.html">سياسة الخصوصية</a>' +
          '<a class="sc-dlink" href="terms.html">الشروط والأحكام</a></div>' +
      '</div>' +
      '<div class="sc-dfoot">© ٢٠٢٦ فلاي مسار — جميع الحقوق محفوظة</div>' +
    '</aside>';
  }

  function footerHtml() {
    return '' +
    '<footer class="sc-footer">' +
      '<div class="sc-footer-in">' +
        '<div class="sc-fgrid">' +
          '<div><div class="sc-fbrand">' + LOGO + 'فلاي مسار</div>' +
            '<p class="sc-fdesc">منصّة السفر الأولى للعالم العربي — أفضل أسعار الطيران والفنادق، وحجز آمن وسريع.</p></div>' +
          '<div><h4>الشركة</h4><ul>' +
            '<li><a href="about.html">من نحن</a></li>' +
            '<li><a href="services.html">خدماتنا</a></li>' +
            '<li><a href="contact.html">اتصل بنا</a></li>' +
            '<li><a href="lookup.html">استعلام عن حجز</a></li></ul></div>' +
          '<div><h4>قانوني</h4><ul>' +
            '<li><a href="privacy.html">سياسة الخصوصية</a></li>' +
            '<li><a href="terms.html">الشروط والأحكام</a></li></ul></div>' +
        '</div>' +
        '<div class="sc-fcopy">© ٢٠٢٦ فلاي مسار. جميع الحقوق محفوظة.</div>' +
      '</div>' +
    '</footer>';
  }

  function bottomNavHtml() {
    var items = [NAV[0], NAV[1], NAV[2], { k: 'account', href: 'dashboard.html', label: 'حسابي', icon: ICONS.account }];
    return '<nav class="sc-bnav">' + items.map(function (n) {
      var on = n.k === active ? ' on' : '';
      return '<a class="' + on.trim() + '" href="' + n.href + '">' + n.icon + '<span>' + n.label + '</span></a>';
    }).join('') + '</nav>';
  }

  /* --- theme ----------------------------------------------------------- */
  function applyTheme(dark) {
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    document.querySelectorAll('[data-sc="theme"]').forEach(function (b) {
      b.textContent = dark ? '☀️' : '🌙';
      b.title = dark ? 'الوضع النهاري' : 'الوضع الليلي';
    });
  }

  /* --- mount ----------------------------------------------------------- */
  function mount() {
    if (document.getElementById('scDrawer')) return; // already mounted

    // styles
    var s = document.createElement('style');
    s.id = 'sc-styles';
    s.textContent = css;
    document.head.appendChild(s);

    // header (into #sc-header if present, else top of body)
    var headerHost = document.getElementById('sc-header');
    if (headerHost) {
      headerHost.innerHTML = headerHtml() + drawerHtml();
    } else {
      var wrap = document.createElement('div');
      wrap.innerHTML = headerHtml() + drawerHtml();
      while (wrap.firstChild) document.body.insertBefore(wrap.firstChild, document.body.firstChild);
    }

    // footer + bottom nav (into #sc-footer if present, else end of body)
    var footerHost = document.getElementById('sc-footer');
    if (footerHost) {
      footerHost.innerHTML = footerHtml() + bottomNavHtml();
    } else {
      var w2 = document.createElement('div');
      w2.innerHTML = footerHtml() + bottomNavHtml();
      while (w2.firstChild) document.body.appendChild(w2.firstChild);
    }

    wire();
  }

  function openDrawer() {
    var d = document.getElementById('scDrawer'), o = document.querySelector('.sc-ov');
    if (d) d.classList.add('open');
    if (o) o.classList.add('open');
  }
  function closeDrawer() {
    var d = document.getElementById('scDrawer'), o = document.querySelector('.sc-ov');
    if (d) d.classList.remove('open');
    if (o) o.classList.remove('open');
  }

  function wire() {
    // delegated clicks
    document.addEventListener('click', function (e) {
      var t = e.target.closest('[data-sc]');
      if (!t) return;
      var a = t.getAttribute('data-sc');
      if (a === 'open') openDrawer();
      else if (a === 'close') closeDrawer();
      else if (a === 'theme') {
        var dark = document.documentElement.getAttribute('data-theme') === 'dark';
        localStorage.setItem(THEME_KEY, !dark ? 'dark' : 'light');
        applyTheme(!dark);
      } else if (a === 'logout') {
        if (window.Auth && Auth.logout) Auth.logout();
        else { localStorage.removeItem('flymasar_token'); localStorage.removeItem('flymasar_user'); location.href = 'index.html'; }
      }
    });

    // language buttons -> official setLang(); refresh chrome auth labels stay AR
    document.querySelectorAll('.sc-nav .lang-btn, .sc-drawer .lang-btn').forEach(function (b) {
      b.addEventListener('click', function () {
        if (window.setLang) setLang(b.dataset.lang);
      });
    });
    // currency buttons -> official setCurrency() (updates prices + .active state)
    document.querySelectorAll('.sc-nav .currency-btn, .sc-drawer .currency-btn').forEach(function (b) {
      b.addEventListener('click', function () {
        var cf = window.setCurrency || window.setCur; // official helper, or a page's inline one
        if (cf) cf(b.dataset.currency);
        document.querySelectorAll('.currency-btn').forEach(function (x) { x.classList.toggle('active', x.dataset.currency === b.dataset.currency); });
      });
    });

    // reflect saved theme (dark-mode.js may also do this; idempotent)
    applyTheme((localStorage.getItem(THEME_KEY) || 'light') === 'dark');

    // sync currency + language highlight using official initializers if present
    if (window.initCurrency) { try { initCurrency(); } catch (e) {} }
    else {
      var cur = localStorage.getItem('fly_cur') || localStorage.getItem('flymasar_currency') || 'KWD';
      document.querySelectorAll('.currency-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.currency === cur); });
    }
    if (window.setLang) { try { setLang(localStorage.getItem('flymasar_lang') || 'ar'); } catch (e) {} }
    else {
      var lang = localStorage.getItem('flymasar_lang') || 'ar';
      document.querySelectorAll('.lang-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.lang === lang); });
    }
  }

  // expose a tiny API in case pages need it
  window.SiteChrome = { open: openDrawer, close: closeDrawer, refreshAuth: function () {
    var a = document.getElementById('scAuth'); if (a) a.innerHTML = authDesktopHtml();
    var da = document.getElementById('scDrawerAuth'); if (da) da.innerHTML = authDrawerHtml();
  } };

  // apply theme ASAP to avoid flash, then mount when DOM is ready
  applyTheme((localStorage.getItem(THEME_KEY) || 'light') === 'dark');
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();
