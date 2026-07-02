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

  // ── Install hook ──────────────────────────────────────────────────────────
  // No site-wide install UI. The install button lives ONLY on the home
  // launcher and calls window.flyInstall(). Here we just capture the native
  // prompt, expose helpers, and provide the iOS instruction sheet.
  window.__flyStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
                           window.navigator.standalone === true;

  var STYLE = [
    '.pwa-sheet-ov{position:fixed;inset:0;background:rgba(20,8,45,.55);z-index:2290;display:none}',
    '.pwa-sheet{position:fixed;left:0;right:0;bottom:0;z-index:2300;background:#fff;border-radius:22px 22px 0 0;',
    'padding:10px 20px calc(24px + env(safe-area-inset-bottom,0));transform:translateY(100%);transition:transform .26s;box-shadow:0 -20px 50px -18px rgba(46,16,101,.5)}',
    '.pwa-sheet.open{transform:translateY(0)}.pwa-sheet-grip{width:44px;height:5px;border-radius:5px;background:#ECE8F4;margin:6px auto 14px}',
    '.pwa-sheet h3{color:#4C1D95;font-size:1.1rem;margin:0 0 6px;text-align:center}',
    '.pwa-sheet p{color:#6B6880;font-size:.9rem;text-align:center;margin:0 0 14px}',
    '.pwa-step{display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid #F1ECFA;font-size:.92rem;color:#1B172B}',
    '.pwa-step b{color:#7C3AED}'
  ].join('');
  var st = document.createElement('style'); st.textContent = STYLE; document.head.appendChild(st);

  var deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    window.dispatchEvent(new Event('fly-install-ready'));
  });

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    window.__flyStandalone = true;
    window.dispatchEvent(new Event('fly-installed'));
  });

  var ua = navigator.userAgent || '';
  var isIOS = /iphone|ipad|ipod/i.test(ua) || (/macintosh/i.test(ua) && navigator.maxTouchPoints > 1);
  var isAndroid = /android/i.test(ua);

  // Platform-aware "how to install" sheet (used whenever the native prompt
  // isn't available — iOS always, Android/desktop when the browser hasn't
  // offered the prompt yet or doesn't support it).
  var helpOv, helpSheet;
  function stepsFor() {
    if (isIOS) {
      return '<p>أضف فلاي مسار إلى شاشتك الرئيسية ليعمل كتطبيق كامل:</p>' +
        '<div class="pwa-step"><span>①</span><span>اضغط زر <b>المشاركة</b> في أسفل متصفح Safari</span></div>' +
        '<div class="pwa-step"><span>②</span><span>اختر <b>«أضف إلى الشاشة الرئيسية»</b></span></div>' +
        '<div class="pwa-step"><span>③</span><span>اضغط <b>«إضافة»</b> — وسيظهر أيقونة التطبيق على جهازك</span></div>';
    }
    if (isAndroid) {
      return '<p>ثبّت فلاي مسار كتطبيق على هاتفك:</p>' +
        '<div class="pwa-step"><span>①</span><span>افتح قائمة المتصفح <b>⋮</b> أعلى الشاشة</span></div>' +
        '<div class="pwa-step"><span>②</span><span>اختر <b>«تثبيت التطبيق»</b> أو <b>«إضافة إلى الشاشة الرئيسية»</b></span></div>' +
        '<div class="pwa-step"><span>③</span><span>اضغط <b>«تثبيت»</b> — وسيظهر التطبيق على جهازك</span></div>' +
        '<p style="margin-top:12px;font-size:.82rem">إن لم تجد الخيار، افتح الموقع في <b>Chrome</b> مباشرةً (وليس داخل تطبيق آخر).</p>';
    }
    return '<p>ثبّت فلاي مسار كتطبيق:</p>' +
      '<div class="pwa-step"><span>①</span><span>افتح قائمة المتصفح</span></div>' +
      '<div class="pwa-step"><span>②</span><span>اختر <b>«تثبيت التطبيق»</b> (Install app)</span></div>';
  }
  function openHelpSheet() {
    if (!helpSheet) {
      helpOv = document.createElement('div'); helpOv.className = 'pwa-sheet-ov';
      helpSheet = document.createElement('div'); helpSheet.className = 'pwa-sheet';
      document.body.appendChild(helpOv); document.body.appendChild(helpSheet);
      helpOv.addEventListener('click', function () { helpSheet.classList.remove('open'); helpOv.style.display = 'none'; });
    }
    helpSheet.innerHTML = '<div class="pwa-sheet-grip"></div><h3>📲 ثبّت تطبيق فلاي مسار</h3>' + stepsFor();
    helpOv.style.display = 'block';
    requestAnimationFrame(function () { helpSheet.classList.add('open'); });
  }

  // Public API used by the home launcher install button.
  window.flyInstall = function () {
    if (deferredPrompt) {
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function () { deferredPrompt = null; });
      return;
    }
    openHelpSheet();
  };
})();
