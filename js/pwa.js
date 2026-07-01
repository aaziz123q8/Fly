/* ============================================================================
   FlyMasar — PWA bootstrap (installable app)
   Injects the manifest + iOS/theme meta tags, registers the service worker,
   and on phones shows a custom "install app" pill. Loaded site-wide via
   site-chrome.js. Safe/no-op on desktop and unsupported browsers.
   ==========================================================================*/
(function () {
  'use strict';

  var THEME = '#7C3AED';

  // ── Inject <head> tags (idempotent) ────────────────────────────────────────
  function addOnce(selector, create) {
    if (document.head.querySelector(selector)) return;
    document.head.appendChild(create());
  }
  function meta(name, content, useProperty) {
    var m = document.createElement('meta');
    m.setAttribute(useProperty ? 'property' : 'name', name);
    m.setAttribute('content', content);
    return m;
  }
  function link(rel, href, extra) {
    var l = document.createElement('link');
    l.rel = rel; l.href = href;
    if (extra) Object.keys(extra).forEach(function (k) { l.setAttribute(k, extra[k]); });
    return l;
  }

  try {
    addOnce('link[rel="manifest"]', function () { return link('manifest', '/manifest.json'); });
    addOnce('meta[name="theme-color"]', function () { return meta('theme-color', THEME); });
    addOnce('meta[name="mobile-web-app-capable"]', function () { return meta('mobile-web-app-capable', 'yes'); });
    addOnce('meta[name="apple-mobile-web-app-capable"]', function () { return meta('apple-mobile-web-app-capable', 'yes'); });
    addOnce('meta[name="apple-mobile-web-app-status-bar-style"]', function () { return meta('apple-mobile-web-app-status-bar-style', 'black-translucent'); });
    addOnce('meta[name="apple-mobile-web-app-title"]', function () { return meta('apple-mobile-web-app-title', 'فلاي مسار'); });
    addOnce('link[rel="apple-touch-icon"]', function () { return link('apple-touch-icon', '/icons/icon-180x180.png'); });
  } catch (e) {}

  // ── Register service worker ────────────────────────────────────────────────
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js').catch(function () {});
    });
  }

  // ── Install experience (phones only) ───────────────────────────────────────
  var isPhone = function () { return window.innerWidth <= 768; };
  var isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
                     window.navigator.standalone === true;
  if (isStandalone) return; // already installed / running as app

  var STYLE = [
    '.pwa-install{position:fixed;left:50%;transform:translateX(-50%);bottom:calc(74px + env(safe-area-inset-bottom,0));',
    'z-index:1200;display:none;align-items:center;gap:10px;background:linear-gradient(120deg,#7C3AED,#9333EA 55%,#C026D3);',
    'color:#fff;border:0;border-radius:40px;padding:12px 18px;font:inherit;font-weight:800;font-size:.92rem;',
    'box-shadow:0 16px 34px -12px rgba(76,29,149,.85);cursor:pointer;max-width:calc(100vw - 28px)}',
    '.pwa-install .pwa-x{background:rgba(255,255,255,.22);border-radius:50%;width:22px;height:22px;display:flex;',
    'align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0}',
    '.pwa-sheet-ov{position:fixed;inset:0;background:rgba(20,8,45,.55);z-index:1290;display:none}',
    '.pwa-sheet{position:fixed;left:0;right:0;bottom:0;z-index:1300;background:#fff;border-radius:22px 22px 0 0;',
    'padding:10px 20px calc(24px + env(safe-area-inset-bottom,0));transform:translateY(100%);transition:transform .26s;box-shadow:0 -20px 50px -18px rgba(46,16,101,.5)}',
    '.pwa-sheet.open{transform:translateY(0)}.pwa-sheet-grip{width:44px;height:5px;border-radius:5px;background:#ECE8F4;margin:6px auto 14px}',
    '.pwa-sheet h3{color:#4C1D95;font-size:1.1rem;margin:0 0 6px;text-align:center}',
    '.pwa-sheet p{color:#6B6880;font-size:.9rem;text-align:center;margin:0 0 14px}',
    '.pwa-step{display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid #F1ECFA;font-size:.92rem;color:#1B172B}',
    '.pwa-step b{color:#7C3AED}'
  ].join('');
  var st = document.createElement('style'); st.textContent = STYLE; document.head.appendChild(st);

  var dismissed = false;
  try { dismissed = localStorage.getItem('pwa_install_dismissed') === '1'; } catch (e) {}

  var deferredPrompt = null;
  var btn = null;

  function makeBtn(label) {
    if (btn) return btn;
    btn = document.createElement('button');
    btn.className = 'pwa-install';
    btn.type = 'button';
    btn.innerHTML = '<span>📲 ' + label + '</span><span class="pwa-x" aria-label="إغلاق">✕</span>';
    document.body.appendChild(btn);
    btn.addEventListener('click', function (e) {
      if (e.target && e.target.classList.contains('pwa-x')) {
        hideBtn();
        try { localStorage.setItem('pwa_install_dismissed', '1'); } catch (er) {}
        return;
      }
      onInstallClick();
    });
    return btn;
  }
  function showBtn() { if (btn && isPhone() && !dismissed) btn.style.display = 'flex'; }
  function hideBtn() { if (btn) btn.style.display = 'none'; }

  // Android / Chromium: native install flow.
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    if (dismissed) return;
    makeBtn('حمّل تطبيق فلاي مسار');
    showBtn();
  });

  window.addEventListener('appinstalled', function () {
    hideBtn();
    try { localStorage.setItem('pwa_install_dismissed', '1'); } catch (e) {}
  });

  function onInstallClick() {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function () { deferredPrompt = null; hideBtn(); });
    } else {
      openIosSheet(); // iOS has no prompt — show instructions
    }
  }

  // iOS Safari: no beforeinstallprompt — offer an instruction sheet.
  var ua = navigator.userAgent || '';
  var isIOS = /iphone|ipad|ipod/i.test(ua);
  var isSafari = isIOS && /safari/i.test(ua) && !/crios|fxios|edgios/i.test(ua);
  var iosOv, iosSheet;
  function openIosSheet() {
    if (!iosSheet) {
      iosOv = document.createElement('div'); iosOv.className = 'pwa-sheet-ov';
      iosSheet = document.createElement('div'); iosSheet.className = 'pwa-sheet';
      iosSheet.innerHTML =
        '<div class="pwa-sheet-grip"></div>' +
        '<h3>ثبّت تطبيق فلاي مسار</h3>' +
        '<p>أضف فلاي مسار إلى شاشتك الرئيسية ليعمل كتطبيق كامل.</p>' +
        '<div class="pwa-step"><span>①</span><span>اضغط زر المشاركة <b>􀈂</b> في شريط سفاري بالأسفل</span></div>' +
        '<div class="pwa-step"><span>②</span><span>اختر <b>«أضف إلى الشاشة الرئيسية»</b></span></div>' +
        '<div class="pwa-step"><span>③</span><span>اضغط <b>«إضافة»</b> — وسيظهر التطبيق على جهازك</span></div>';
      document.body.appendChild(iosOv); document.body.appendChild(iosSheet);
      iosOv.addEventListener('click', function () { iosSheet.classList.remove('open'); iosOv.style.display = 'none'; });
    }
    iosOv.style.display = 'block';
    requestAnimationFrame(function () { iosSheet.classList.add('open'); });
  }

  if (isIOS && isSafari && !dismissed) {
    // Show the install pill after a short delay so it doesn't interrupt load.
    window.addEventListener('load', function () {
      setTimeout(function () { makeBtn('ثبّت التطبيق على جهازك'); showBtn(); }, 1500);
    });
  }
})();
