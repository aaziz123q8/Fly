/* ============================================================================
   FlyMasar — Mobile App Shell
   ----------------------------------------------------------------------------
   On phones (<= 768px) the site presents as a NATIVE-style app: a colored app
   top bar (brand on home; back-button + screen title on inner pages) and a
   fixed bottom tab bar, while the desktop website header/drawer is hidden.
   On desktop this file does nothing visible. Include site-wide (site-chrome
   loads it automatically); the homepage includes it directly.
   ==========================================================================*/
(function () {
  'use strict';

  // ── Lock zoom on phones for a native-app feel ──────────────────────────
  // The viewport `user-scalable=no` alone is ignored by iOS Safari, so we also
  // (1) set `touch-action` on the root to kill pinch + double-tap zoom in modern
  // browsers, and (2) preventDefault iOS gesture events and multi-touch moves.
  // All guarded to phone widths so desktop/touch-laptops are unaffected.
  try {
    var _vp = document.querySelector('meta[name="viewport"]');
    if (!_vp) { _vp = document.createElement('meta'); _vp.name = 'viewport'; document.head.appendChild(_vp); }
    _vp.setAttribute('content', 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no');

    var zs = document.createElement('style');
    zs.textContent = '@media(max-width:768px){html{touch-action:pan-x pan-y;-ms-touch-action:pan-x pan-y}}';
    document.head.appendChild(zs);

    var isPhone = function () { return window.innerWidth <= 768; };
    // iOS Safari pinch gestures
    ['gesturestart', 'gesturechange', 'gestureend'].forEach(function (ev) {
      document.addEventListener(ev, function (e) { if (isPhone()) e.preventDefault(); }, { passive: false });
    });
    // Multi-touch (pinch) moves
    document.addEventListener('touchmove', function (e) {
      if (isPhone() && e.touches && e.touches.length > 1) e.preventDefault();
    }, { passive: false });
    // Double-tap to zoom
    var _lastTouch = 0;
    document.addEventListener('touchend', function (e) {
      if (!isPhone()) return;
      var now = Date.now();
      if (now - _lastTouch <= 320) e.preventDefault();
      _lastTouch = now;
    }, { passive: false });
  } catch (e) {}

  var path = (location.pathname || '').toLowerCase();
  var here = (path.split('/').pop() || 'index.html'); if (!here) here = 'index.html';
  var isHome = (here === 'index.html' || here === '');

  var I = {
    home:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>',
    flight: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5z"/></svg>',
    offers: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 12 22l-9-9V4a1 1 0 0 1 1-1h6z"/><circle cx="7.5" cy="7.5" r="1.2" fill="currentColor"/><path d="m8 15 6-6"/></svg>',
    ticket: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4z"/><path d="M13 5v14"/></svg>',
    user:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 12 0v1"/></svg>',
    bell:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
    back:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>'
  };

  var loggedIn = (window.Auth && Auth.isLoggedIn && Auth.isLoggedIn());

  // Checkout-funnel pages hand the bottom of the screen to the price/pay bar,
  // so the app tab bar is suppressed there.
  var NO_TABS = ['flights.html', 'hotels.html', 'booking.html', 'services.html', 'payment.html',
                 'hotel-detail.html', 'hotel-booking.html', 'hotel-payment.html'];
  var hideTabs = NO_TABS.indexOf(here) >= 0;

  var TABS = [
    { k:'home',    href:'index.html',     icon:I.home,   label:'الرئيسية', match:['index.html',''] },
    { k:'flights', href:'flights.html',   icon:I.flight, label:'رحلات',    match:['flights.html'] },
    { k:'offers',  href:'offers.html',    icon:I.offers, label:'العروض',   match:['offers.html'] },
    { k:'trips',   href:'dashboard.html', icon:I.ticket, label:'رحلاتي',   match:['dashboard.html'] },
    { k:'account', href:(loggedIn?'dashboard.html':'login.html'), icon:I.user, label:'حسابي', match:['login.html','register.html','travelers.html'] }
  ];

  var TITLES = {
    'flights.html':'الرحلات','hotels.html':'الفنادق','hotel-detail.html':'تفاصيل الفندق',
    'offers.html':'العروض','package.html':'باقة سفر','dashboard.html':'حسابي','travelers.html':'المسافرون',
    'login.html':'تسجيل الدخول','register.html':'حساب جديد','lookup.html':'استعلام عن حجز',
    'booking.html':'إتمام الحجز','payment.html':'الدفع','confirmation.html':'تأكيد الحجز',
    'hotel-booking.html':'إتمام الحجز','hotel-payment.html':'الدفع','hotel-confirmation.html':'تأكيد الحجز',
    'about.html':'من نحن','contact.html':'اتصل بنا','privacy.html':'سياسة الخصوصية',
    'terms.html':'الشروط والأحكام','services.html':'خدماتنا','invoice.html':'الفاتورة'
  };

  var css =
    ".mapp{display:none}" +
    "@media(max-width:768px){" +
      ".nav,.sc-nav,.mdrawer,.moverlay,.sc-dr,.sc-ov,.bottom-nav,.sc-bnav{display:none!important}" +
      "body{padding-bottom:calc(66px + env(safe-area-inset-bottom,0)) !important}" +
      ".mapp{display:block}" +
      ".mapp-top{position:sticky;top:0;z-index:800;background:linear-gradient(120deg,#7C3AED,#9333EA 60%,#C026D3);color:#fff;padding:calc(10px + env(safe-area-inset-top,0)) 14px 12px;display:flex;align-items:center;gap:10px;box-shadow:0 6px 20px -10px rgba(76,29,149,.7)}" +
      ".mapp-brand{display:flex;align-items:center;gap:8px;font-weight:800;font-size:1.15rem;color:#fff}" +
      ".mapp-brand .mk{width:32px;height:32px;border-radius:10px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center}" +
      ".mapp-brand .mk svg{width:18px;height:18px;stroke:#fff;fill:none;stroke-width:2}" +
      ".mapp-brand em{font-style:normal;color:#FDE68A}" +
      ".mapp-back{width:40px;height:40px;border-radius:12px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;color:#fff;cursor:pointer;border:0;flex-shrink:0}" +
      ".mapp-back svg{width:22px;height:22px}" +
      ".mapp-title{font-weight:800;font-size:1.1rem;color:#fff}" +
      ".mapp-actions{margin-inline-start:auto;display:flex;align-items:center;gap:9px}" +
      ".mapp-ic{width:40px;height:40px;border-radius:12px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;color:#fff}" +
      ".mapp-ic svg{width:20px;height:20px}" +
      ".mapp-av{width:40px;height:40px;border-radius:50%;background:#fff;color:#7C3AED;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1rem}" +
      ".mapp-tabs{position:fixed;bottom:0;left:0;right:0;z-index:900;background:#fff;border-top:1px solid #ECE8F4;display:flex;justify-content:space-around;padding:8px 4px calc(8px + env(safe-area-inset-bottom,0));box-shadow:0 -6px 24px -14px rgba(76,29,149,.4)}" +
      ".mapp-tab{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;color:#9a93ad;font-size:.66rem;font-weight:700;text-decoration:none;transition:.15s;position:relative}" +
      ".mapp-tab svg{width:23px;height:23px}" +
      ".mapp-tab.on{color:#7C3AED}" +
      ".mapp-tab.on .mapp-dot{position:absolute;top:-8px;width:22px;height:3px;border-radius:3px;background:linear-gradient(90deg,#7C3AED,#C026D3)}" +
      ".sc-footer{display:none!important}" +
    "}";

  function activeKey(){ for (var i=0;i<TABS.length;i++){ if (TABS[i].match.indexOf(here)>=0) return TABS[i].k; } return isHome?'home':''; }

  function actionsHtml(){
    var right = loggedIn
      ? '<a class="mapp-av" href="dashboard.html">'+ ((Auth.getUser()&&(Auth.getUser().name||Auth.getUser().first_name||'م'))[0]) +'</a>'
      : '<a class="mapp-ic" href="login.html" aria-label="حسابي">'+I.user+'</a>';
    return '<div class="mapp-actions"><a class="mapp-ic" href="index.html" aria-label="الرئيسية">'+I.home+'</a>'+right+'</div>';
  }

  function topHtml(){
    if (isHome){
      return '<div class="mapp-top">'
        + '<a class="mapp-brand" href="index.html"><span class="mk"><svg viewBox="0 0 24 24"><path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5z"/></svg></span>فلاي<em> مسار</em></a>'
        + actionsHtml() + '</div>';
    }
    var title = TITLES[here] || 'فلاي مسار';
    return '<div class="mapp-top">'
      + '<button type="button" class="mapp-back" data-mapp="back" aria-label="رجوع">'+I.back+'</button>'
      + '<span class="mapp-title">'+title+'</span>'
      + actionsHtml() + '</div>';
  }

  function tabsHtml(){
    var ak = activeKey();
    return '<nav class="mapp-tabs">'+TABS.map(function(t){
      var on = t.k===ak ? ' on' : '';
      return '<a class="mapp-tab'+on+'" href="'+t.href+'">'+(on?'<span class="mapp-dot"></span>':'')+t.icon+'<span>'+t.label+'</span></a>';
    }).join('')+'</nav>';
  }

  function mount(){
    if (document.getElementById('mappTabs') || document.getElementById('mappTop')) return;
    var s=document.createElement('style'); s.textContent=css; document.head.appendChild(s);
    var top=document.createElement('div'); top.className='mapp'; top.id='mappTop'; top.innerHTML=topHtml();
    document.body.insertBefore(top, document.body.firstChild);
    // Checkout-funnel pages: no bottom tab bar (price/pay bar takes over).
    if (!hideTabs) {
      var wrap=document.createElement('div'); wrap.className='mapp'; wrap.id='mappTabs'; wrap.innerHTML=tabsHtml();
      document.body.appendChild(wrap);
    } else {
      var ps=document.createElement('style');
      ps.textContent='@media(max-width:768px){body{padding-bottom:0 !important}}';
      document.head.appendChild(ps);
    }
    document.addEventListener('click', function(e){
      var t=e.target.closest('[data-mapp="back"]'); if(!t) return;
      if (history.length > 1) history.back(); else location.href='index.html';
    });
  }

  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
