/* ============================================================================
   FlyMasar — Unified Site Chrome (v2 · violet identity)
   ----------------------------------------------------------------------------
   ONE source of truth for the header, mobile drawer and footer across every
   page, matching the new homepage design (index.html). Include with:

       <script src="js/site-chrome.js?v=..."></script>

   It reuses the project's official helpers when present — setLang()/setCurrency()
   (i18n.js/currency.js) and the Auth object (auth.js) — and degrades gracefully.
   Self-contained CSS + font so it renders consistently even without app.css.
   Force the active tab with `window.FLY_CHROME = { active: 'flights' }` before
   this script; otherwise it is inferred from the URL.
   ==========================================================================*/
(function () {
  'use strict';
  var CFG = window.FLY_CHROME || {};

  var path = (location.pathname || '').toLowerCase();
  var here = (path.split('/').pop() || 'index.html'); if (!here) here = 'index.html';
  var active = CFG.active || ({
    'index.html': 'home', '': 'home',
    'flights.html': 'flights',
    'hotels.html': 'hotels', 'hotel-detail.html': 'hotels',
    'lookup.html': 'lookup'
  }[here] || '');

  var MK = '<span class="scmk"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5z"/></svg></span>';
  var NAV = [
    { k:'home',    href:'index.html',   label:'الرئيسية' },
    { k:'flights', href:'flights.html', label:'رحلات' },
    { k:'hotels',  href:'hotels.html',  label:'فنادق' },
    { k:'lookup',  href:'lookup.html',  label:'استعلام' }
  ];
  var CUR = [ {c:'KWD',t:'د.ك'}, {c:'SAR',t:'﷼'}, {c:'USD',t:'$'} ];

  var css = [
    ":root{--sc-v1:#7C3AED;--sc-v2:#9333EA;--sc-v3:#4C1D95;--sc-ink:#1B172B;--sc-muted:#6B6880;--sc-line:#ECE8F4;--sc-grad:linear-gradient(120deg,#7C3AED,#9333EA 55%,#C026D3)}",
    /* base type + bg to match the new identity (only if page opts in via body.fm) */
    "body.fm{font-family:'IBM Plex Sans Arabic',sans-serif;background:#FAF8FF;color:var(--sc-ink)}",
    ".sc-nav{position:sticky;top:0;z-index:60;background:rgba(255,255,255,.82);backdrop-filter:saturate(160%) blur(14px);border-bottom:1px solid var(--sc-line)}",
    ".sc-in{max-width:1200px;margin:0 auto;padding:0 22px;height:72px;display:flex;align-items:center;gap:22px}",
    ".sc-brand{display:flex;align-items:center;gap:10px;font-size:1.4rem;font-weight:700;color:var(--sc-ink);white-space:nowrap}",
    ".scmk{width:40px;height:40px;border-radius:13px;background:var(--sc-grad);display:flex;align-items:center;justify-content:center;color:#fff;box-shadow:0 10px 22px -8px rgba(124,58,237,.7)}",
    ".scmk svg{width:22px;height:22px}",
    ".sc-brand b{font-weight:700}.sc-brand b em{font-style:normal;color:var(--sc-v2)}",
    ".sc-links{display:flex;gap:2px;margin-inline-start:12px}",
    ".sc-links a{color:var(--sc-muted);font-weight:600;font-size:.97rem;padding:9px 14px;border-radius:11px;transition:.2s}",
    ".sc-links a:hover,.sc-links a.on{color:var(--sc-v2);background:#F4EEFE}",
    ".sc-ctrls{margin-inline-start:auto;display:flex;align-items:center;gap:10px}",
    ".sc-seg{display:flex;background:#F4F1FA;border:1px solid var(--sc-line);border-radius:12px;padding:3px}",
    ".sc-seg button{border:0;background:none;font:inherit;font-weight:700;font-size:.82rem;color:var(--sc-muted);padding:6px 11px;border-radius:9px;cursor:pointer;line-height:1}",
    ".sc-seg button.active{background:#fff;color:var(--sc-v2);box-shadow:0 1px 3px rgba(0,0,0,.08)}",
    ".sc-b-ghost{color:var(--sc-ink);border:1.5px solid var(--sc-line);padding:9px 18px;border-radius:12px;font-weight:700;font-size:.9rem}",
    ".sc-b-ghost:hover{border-color:#c9b6f2;color:var(--sc-v2)}",
    ".sc-b-cta{background:var(--sc-grad);color:#fff;padding:10px 20px;border-radius:12px;font-weight:700;font-size:.9rem;box-shadow:0 12px 24px -10px rgba(124,58,237,.7)}",
    ".sc-ham{display:none;flex-direction:column;gap:5px;width:44px;height:44px;align-items:center;justify-content:center;border:1.5px solid var(--sc-line);border-radius:12px;background:#fff;cursor:pointer;margin-inline-start:auto}",
    ".sc-ham span{display:block;width:20px;height:2px;background:var(--sc-v2);border-radius:2px}",
    /* drawer */
    ".sc-ov{position:fixed;inset:0;background:rgba(20,8,45,.5);z-index:70;opacity:0;visibility:hidden;transition:.25s}",
    ".sc-ov.open{opacity:1;visibility:visible}",
    ".sc-dr{position:fixed;top:0;right:0;height:100%;width:300px;max-width:86vw;background:#fff;z-index:71;transform:translateX(110%);transition:transform .28s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;box-shadow:-10px 0 40px rgba(20,8,45,.25)}",
    "html[dir='ltr'] .sc-dr{right:auto;left:0;transform:translateX(-110%)}",
    ".sc-dr.open{transform:translateX(0)}",
    ".sc-dh{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--sc-line)}",
    ".sc-dx{border:0;background:none;font-size:1.4rem;color:var(--sc-muted);cursor:pointer;line-height:1}",
    ".sc-db{flex:1;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:16px}",
    ".sc-dlinks{display:flex;flex-direction:column;gap:2px}",
    ".sc-dlinks a{padding:12px;border-radius:12px;font-weight:600;color:var(--sc-ink);transition:.15s}",
    ".sc-dlinks a:hover,.sc-dlinks a.on{background:#F4EEFE;color:var(--sc-v2)}",
    ".sc-dt{font-size:.72rem;font-weight:700;color:var(--sc-muted);letter-spacing:.04em;margin-bottom:8px}",
    ".sc-drow{display:flex;gap:6px;flex-wrap:wrap}",
    ".sc-drow button{flex:1;min-width:66px;border:1.5px solid var(--sc-line);background:none;border-radius:11px;padding:10px;font:inherit;font-weight:700;font-size:.85rem;color:var(--sc-muted);cursor:pointer}",
    ".sc-drow button.active{border-color:var(--sc-v2);color:var(--sc-v2);background:#F4EEFE}",
    ".sc-df{padding:14px 18px;border-top:1px solid var(--sc-line);font-size:.78rem;color:var(--sc-muted);text-align:center}",
    /* footer */
    ".sc-footer{background:#160B2E;color:#b7abd6;padding:52px 0 24px;margin-top:60px}",
    ".sc-fin{max-width:1200px;margin:0 auto;padding:0 22px}",
    ".sc-fg{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:32px;margin-bottom:30px}",
    ".sc-fg h4{color:#fff;margin-bottom:13px;font-size:.98rem}",
    ".sc-fg a{display:block;color:#b7abd6;padding:5px 0;font-size:.92rem}.sc-fg a:hover{color:#fff}",
    ".sc-fbrand{display:flex;align-items:center;gap:9px;font-size:1.2rem;font-weight:700;color:#fff;margin-bottom:12px}",
    ".sc-fdesc{max-width:300px;color:#b7abd6;font-size:.92rem;line-height:1.7}",
    ".sc-fcopy{border-top:1px solid rgba(255,255,255,.1);padding-top:20px;text-align:center;font-size:.85rem;color:#8577ab}",
    "@media(max-width:960px){.sc-links{display:none}.sc-ctrls .sc-seg,.sc-ctrls .sc-auth{display:none}.sc-ham{display:flex}.sc-fg{grid-template-columns:1fr 1fr}}",
    "@media(max-width:560px){.sc-fg{grid-template-columns:1fr}}"
  ].join('');

  function links(cls){ return NAV.map(function(n){return '<a class="'+cls+(n.k===active?' on':'')+'" href="'+n.href+'">'+n.label+'</a>';}).join(''); }
  function curBtns(){ return CUR.map(function(x){return '<button type="button" class="currency-btn" data-currency="'+x.c+'">'+x.t+'</button>';}).join(''); }
  function authDesktop(){
    var on = (window.Auth && Auth.isLoggedIn && Auth.isLoggedIn());
    return on ? '<a class="sc-b-ghost" href="dashboard.html">حسابي</a><button type="button" class="sc-b-cta" style="border:0;cursor:pointer" data-sc="logout">خروج</button>'
              : '<a class="sc-b-ghost" href="login.html">دخول</a><a class="sc-b-cta" href="register.html">حساب جديد</a>';
  }
  function authDrawer(){
    var on = (window.Auth && Auth.isLoggedIn && Auth.isLoggedIn());
    return on ? '<a class="sc-b-cta" href="dashboard.html" style="display:block;text-align:center">حسابي وحجوزاتي</a><button type="button" class="sc-b-ghost" data-sc="logout" style="width:100%;margin-top:8px;cursor:pointer">تسجيل الخروج</button>'
              : '<div style="display:flex;gap:8px"><a class="sc-b-cta" href="login.html" style="flex:1;text-align:center">دخول</a><a class="sc-b-ghost" href="register.html" style="flex:1;text-align:center">حساب جديد</a></div>';
  }

  function headerHtml(){
    return '<header class="sc-nav"><div class="sc-in">'
      + '<a class="sc-brand" href="index.html">'+MK+'<b>فلاي<em> مسار</em></b></a>'
      + '<nav class="sc-links">'+links('')+'</nav>'
      + '<div class="sc-ctrls">'
        + '<div class="sc-seg">'+curBtns()+'</div>'
        + '<span class="sc-auth" id="scAuth">'+authDesktop()+'</span>'
      + '</div>'
      + '<button type="button" class="sc-ham" data-sc="open" aria-label="القائمة"><span></span><span></span><span></span></button>'
      + '</div></header>'
      + '<div class="sc-ov" data-sc="close"></div>'
      + '<aside class="sc-dr" id="scDrawer">'
        + '<div class="sc-dh"><span class="sc-brand" style="font-size:1.15rem">'+MK+'<b>فلاي<em> مسار</em></b></span><button type="button" class="sc-dx" data-sc="close">✕</button></div>'
        + '<div class="sc-db">'
          + '<div id="scDrawerAuth">'+authDrawer()+'</div>'
          + '<div class="sc-dlinks">'+links('sc-dlink')+'</div>'
          + '<div><div class="sc-dt">العملة</div><div class="sc-drow">'+curBtns()+'</div></div>'
          + '<div class="sc-dlinks"><a href="about.html">من نحن</a><a href="contact.html">اتصل بنا</a><a href="privacy.html">سياسة الخصوصية</a><a href="terms.html">الشروط والأحكام</a></div>'
        + '</div>'
        + '<div class="sc-df">© ٢٠٢٦ فلاي مسار — جميع الحقوق محفوظة</div>'
      + '</aside>';
  }
  function footerHtml(){
    return '<footer class="sc-footer"><div class="sc-fin">'
      + '<div class="sc-fg">'
        + '<div><div class="sc-fbrand">'+MK+'<span style="color:#fff">فلاي<em style="font-style:normal;color:#c4b5fd"> مسار</em></span></div><p class="sc-fdesc">منصّة السفر الأولى للعالم العربي — أفضل الأسعار وحجز آمن وسريع.</p></div>'
        + '<div><h4>الشركة</h4><a href="about.html">من نحن</a><a href="services.html">خدماتنا</a><a href="contact.html">اتصل بنا</a></div>'
        + '<div><h4>الدعم</h4><a href="lookup.html">استعلام عن حجز</a><a href="contact.html">الأسئلة الشائعة</a></div>'
        + '<div><h4>قانوني</h4><a href="privacy.html">سياسة الخصوصية</a><a href="terms.html">الشروط والأحكام</a></div>'
      + '</div>'
      + '<div class="sc-fcopy">© ٢٠٢٦ فلاي مسار. جميع الحقوق محفوظة.</div>'
      + '</div></footer>';
  }

  function ensureFont(){
    if (document.getElementById('sc-font')) return;
    var l1=document.createElement('link'); l1.rel='preconnect'; l1.href='https://fonts.googleapis.com'; document.head.appendChild(l1);
    var l=document.createElement('link'); l.id='sc-font'; l.rel='stylesheet';
    l.href='https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap';
    document.head.appendChild(l);
  }

  function openDrawer(){ var d=document.getElementById('scDrawer'),o=document.querySelector('.sc-ov'); if(d)d.classList.add('open'); if(o)o.classList.add('open'); }
  function closeDrawer(){ var d=document.getElementById('scDrawer'),o=document.querySelector('.sc-ov'); if(d)d.classList.remove('open'); if(o)o.classList.remove('open'); }

  function ensureMobileApp(){
    if (document.getElementById('mappTabs') || document.getElementById('sc-mapp')) return;
    var s=document.createElement('script'); s.id='sc-mapp'; s.src='js/mobile-app.js?v=2026070112'; document.body.appendChild(s);
  }

  function ensurePwa(){
    if (document.getElementById('sc-pwa')) return;
    var s=document.createElement('script'); s.id='sc-pwa'; s.src='js/pwa.js?v=2026070107'; document.body.appendChild(s);
  }

  function mount(){
    if (document.getElementById('scDrawer')) return;
    ensureFont();
    ensureMobileApp();
    ensurePwa();
    document.body.classList.add('fm');
    var s=document.createElement('style'); s.id='sc-styles'; s.textContent=css; document.head.appendChild(s);
    var host=document.getElementById('sc-header');
    if(host){ host.innerHTML=headerHtml(); } else { var w=document.createElement('div'); w.innerHTML=headerHtml(); while(w.firstChild) document.body.insertBefore(w.firstChild, document.body.firstChild); }
    var fh=document.getElementById('sc-footer');
    if(fh){ fh.innerHTML=footerHtml(); } else { var w2=document.createElement('div'); w2.innerHTML=footerHtml(); while(w2.firstChild) document.body.appendChild(w2.firstChild); }
    wire();
  }

  function wire(){
    document.addEventListener('click', function(e){
      var t=e.target.closest('[data-sc]'); if(!t) return;
      var a=t.getAttribute('data-sc');
      if(a==='open') openDrawer();
      else if(a==='close') closeDrawer();
      else if(a==='logout'){ if(window.Auth&&Auth.logout) Auth.logout(); else { localStorage.removeItem('flymasar_token'); localStorage.removeItem('flymasar_user'); location.href='index.html'; } }
    });
    document.querySelectorAll('.sc-nav .currency-btn, .sc-dr .currency-btn').forEach(function(b){
      b.addEventListener('click', function(){ var f=window.setCurrency||window.setCur; if(f) f(b.dataset.currency);
        document.querySelectorAll('.currency-btn').forEach(function(x){x.classList.toggle('active', x.dataset.currency===b.dataset.currency);}); });
    });
    // reflect stored currency
    var cur=localStorage.getItem('fly_cur')||localStorage.getItem('flymasar_currency')||'KWD';
    if(window.initCurrency){ try{initCurrency();}catch(e){} }
    document.querySelectorAll('.currency-btn').forEach(function(x){x.classList.toggle('active', x.dataset.currency===cur);});
    if(window.setLang){ try{ setLang(localStorage.getItem('flymasar_lang')||'ar'); }catch(e){} }
  }

  window.SiteChrome = { open:openDrawer, close:closeDrawer, refreshAuth:function(){ var a=document.getElementById('scAuth'); if(a)a.innerHTML=authDesktop(); var d=document.getElementById('scDrawerAuth'); if(d)d.innerHTML=authDrawer(); } };

  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
