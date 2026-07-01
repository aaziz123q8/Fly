/* ============================================================================
   FlyMasar — Mobile App Shell
   ----------------------------------------------------------------------------
   On phones (<= 768px) the site presents as a NATIVE-style app: a colored app
   top bar + a fixed bottom tab bar, and the desktop website header/drawer is
   hidden. On desktop this file does nothing visible. Include site-wide:

       <script src="js/mobile-app.js?v=..."></script>

   Active tab is inferred from the URL. Reuses Auth for the account/avatar.
   ==========================================================================*/
(function () {
  'use strict';

  var path = (location.pathname || '').toLowerCase();
  var here = (path.split('/').pop() || 'index.html'); if (!here) here = 'index.html';

  var I = {
    home:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/></svg>',
    flight:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5z"/></svg>',
    hotel:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V5a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v16"/><path d="M9 8h.01M15 8h.01M9 12h.01M15 12h.01M10 21v-4h4v4"/></svg>',
    ticket:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 0 0 4v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3a2 2 0 0 0 0-4z"/><path d="M13 5v14"/></svg>',
    user:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 12 0v1"/></svg>',
    bell:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>'
  };

  var loggedIn = (window.Auth && Auth.isLoggedIn && Auth.isLoggedIn());
  var accountHref = loggedIn ? 'dashboard.html' : 'login.html';

  var TABS = [
    { k:'home',    href:'index.html',     icon:I.home,   label:'الرئيسية', match:['index.html',''] },
    { k:'flights', href:'flights.html',   icon:I.flight, label:'رحلات',    match:['flights.html'] },
    { k:'hotels',  href:'hotels.html',    icon:I.hotel,  label:'فنادق',    match:['hotels.html','hotel-detail.html'] },
    { k:'trips',   href:'dashboard.html', icon:I.ticket, label:'رحلاتي',   match:['dashboard.html'] },
    { k:'account', href:accountHref,      icon:I.user,   label:'حسابي',    match:['login.html','register.html','travelers.html'] }
  ];

  var css =
    ".mapp{display:none}" +
    "@media(max-width:768px){" +
      /* hide the desktop website chrome on phones */
      ".nav,.sc-nav,.mdrawer,.moverlay,.sc-dr,.sc-ov,.bottom-nav,.sc-bnav{display:none!important}" +
      "body{padding-bottom:calc(64px + env(safe-area-inset-bottom,0)) !important}" +
      ".mapp{display:block}" +
      /* top app bar */
      ".mapp-top{position:sticky;top:0;z-index:800;background:linear-gradient(120deg,#7C3AED,#9333EA 60%,#C026D3);color:#fff;padding:calc(10px + env(safe-area-inset-top,0)) 16px 12px;display:flex;align-items:center;gap:12px;box-shadow:0 6px 20px -10px rgba(76,29,149,.7)}" +
      ".mapp-brand{display:flex;align-items:center;gap:8px;font-weight:800;font-size:1.15rem}" +
      ".mapp-brand .mk{width:32px;height:32px;border-radius:10px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center}" +
      ".mapp-brand .mk svg{width:18px;height:18px;stroke:#fff;fill:none;stroke-width:2}" +
      ".mapp-brand em{font-style:normal;color:#FDE68A}" +
      ".mapp-actions{margin-inline-start:auto;display:flex;align-items:center;gap:10px}" +
      ".mapp-ic{width:40px;height:40px;border-radius:12px;background:rgba(255,255,255,.16);display:flex;align-items:center;justify-content:center;color:#fff}" +
      ".mapp-ic svg{width:20px;height:20px}" +
      ".mapp-av{width:40px;height:40px;border-radius:50%;background:#fff;color:#7C3AED;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1rem}" +
      /* bottom tab bar */
      ".mapp-tabs{position:fixed;bottom:0;left:0;right:0;z-index:900;background:#fff;border-top:1px solid #ECE8F4;display:flex;justify-content:space-around;padding:8px 4px calc(8px + env(safe-area-inset-bottom,0));box-shadow:0 -6px 24px -14px rgba(76,29,149,.4)}" +
      ".mapp-tab{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;color:#9a93ad;font-size:.66rem;font-weight:700;text-decoration:none;transition:.15s;position:relative}" +
      ".mapp-tab svg{width:23px;height:23px}" +
      ".mapp-tab.on{color:#7C3AED}" +
      ".mapp-tab.on .mapp-dot{position:absolute;top:-8px;width:22px;height:3px;border-radius:3px;background:linear-gradient(90deg,#7C3AED,#C026D3)}" +
      /* app-like density tweaks */
      ".sc-footer{margin-bottom:64px}" +
    "}";

  function activeKey(){
    for (var i=0;i<TABS.length;i++){ if (TABS[i].match.indexOf(here)>=0) return TABS[i].k; }
    return 'home';
  }

  function topHtml(){
    var av = loggedIn ? ((Auth.getUser() && (Auth.getUser().name||Auth.getUser().first_name||'م'))[0]) : null;
    var right = loggedIn
      ? '<a class="mapp-av" href="dashboard.html">'+ (av||'م') +'</a>'
      : '<a class="mapp-ic" href="login.html">'+I.user+'</a>';
    return '<div class="mapp-top">'
      + '<a class="mapp-brand" href="index.html"><span class="mk"><svg viewBox="0 0 24 24"><path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5z"/></svg></span>فلاي<em> مسار</em></a>'
      + '<div class="mapp-actions"><a class="mapp-ic" href="dashboard.html" aria-label="الإشعارات">'+I.bell+'</a>'+right+'</div>'
      + '</div>';
  }
  function tabsHtml(){
    var ak = activeKey();
    return '<nav class="mapp-tabs">'+TABS.map(function(t){
      var on = t.k===ak ? ' on' : '';
      return '<a class="mapp-tab'+on+'" href="'+t.href+'">'+(on?'<span class="mapp-dot"></span>':'')+t.icon+'<span>'+t.label+'</span></a>';
    }).join('')+'</nav>';
  }

  function mount(){
    if (document.getElementById('mappTabs')) return;
    var s=document.createElement('style'); s.textContent=css; document.head.appendChild(s);
    // top bar at very top of body
    var top=document.createElement('div'); top.className='mapp'; top.innerHTML=topHtml();
    document.body.insertBefore(top, document.body.firstChild);
    // bottom tabs
    var wrap=document.createElement('div'); wrap.className='mapp'; wrap.id='mappTabs'; wrap.innerHTML=tabsHtml();
    document.body.appendChild(wrap);
  }

  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
