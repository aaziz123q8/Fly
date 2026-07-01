/**
 * admin-sidebar.js — FlyMasar Admin Panel chrome
 * Desktop: injects the violet sidebar into #adminSidebar.
 * Phones (<=768px): injects a NATIVE app shell instead — a fixed bottom tab
 * bar plus a slide-up "More" sheet — and the desktop sidebar is hidden by CSS.
 * Also enforces the app-like zoom lock on phones.
 */
(function () {
  'use strict';

  // ── Auth guard ────────────────────────────────────────────────────────────
  const user = Auth.getUser();
  if (!Auth.isLoggedIn() || !user || (user.role !== 'admin' && user.role !== 'super_admin')) {
    window.location.href = 'login.html';
    return;
  }

  // ── Navigation definition ─────────────────────────────────────────────────
  const NAV_ITEMS = [
    { section: 'الرئيسية' },
    { href: 'index.html',         icon: '📊', label: 'لوحة التحكم' },
    { section: 'الإدارة' },
    { href: 'users.html',         icon: '👤', label: 'المستخدمون' },
    { href: 'travelers.html',     icon: '👥', label: 'المسافرون' },
    { href: 'bookings.html',      icon: '📋', label: 'الحجوزات' },
    { href: 'payments.html',      icon: '💳', label: 'المدفوعات' },
    { href: 'support.html',       icon: '🎧', label: 'الدعم' },
    { section: 'التسويق والتسعير' },
    { href: 'coupons.html',       icon: '🎟️', label: 'القسائم' },
    { href: 'commissions.html',   icon: '💰', label: 'العمولات' },
    { href: 'pricing.html',       icon: '💲', label: 'التسعير والهوامش' },
    { section: 'التحليلات' },
    { href: 'analytics.html',     icon: '📈', label: 'التحليلات والتقارير' },
    { href: 'notifications.html', icon: '🔔', label: 'الإشعارات' },
    { section: 'الإعدادات والتحكم' },
    { href: 'settings.html',      icon: '🛠️', label: 'مركز التحكم' },
    { href: 'currencies.html',    icon: '💱', label: 'العملات' },
    { href: 'api-settings.html',  icon: '⚙️', label: 'إعدادات API' },
    { href: 'cms.html',           icon: '📝', label: 'المحتوى' },
    { section: 'النظام' },
    { href: '../index.html',      icon: '🌐', label: 'الموقع الرئيسي' },
  ];

  // Which items appear as the 4 primary phone tabs (5th is "More").
  const MOBILE_TABS = ['index.html', 'bookings.html', 'users.html', 'analytics.html'];

  // ── Active detection ──────────────────────────────────────────────────────
  const currentPath = window.location.pathname;
  const here = (currentPath.split('/').pop() || 'index.html') || 'index.html';

  function isActive(href) {
    if (!href) return false;
    const base = href.replace(/^\.\.\//, '');
    return currentPath.endsWith('/' + href) || currentPath.endsWith('/' + base) || currentPath.endsWith(href);
  }

  function buildNav() {
    return NAV_ITEMS.map(function (item) {
      if (item.section) return '<div class="sidebar-section-title">' + item.section + '</div>';
      var cls = isActive(item.href) ? ' class="active"' : '';
      return '<a href="' + item.href + '"' + cls + '><span class="nav-icon">' + item.icon + '</span> ' + item.label + '</a>';
    }).join('\n');
  }

  // ── Admin identity ────────────────────────────────────────────────────────
  var adminName = user.first_name || user.email || 'المشرف';
  var adminInitial = adminName[0].toUpperCase();

  // ── Sidebar HTML (desktop) ────────────────────────────────────────────────
  var sidebarHTML = [
    '<div class="sidebar-header" style="display:flex;align-items:center;justify-content:space-between">',
    '  <div>',
    '    <div class="sidebar-brand">✈️ Fly<span>Masar</span></div>',
    '    <div style="font-size:.75rem;color:rgba(255,255,255,.4);margin-top:4px">لوحة التحكم</div>',
    '  </div>',
    '  <button id="sidebarCloseBtn" onclick="toggleSidebar()" style="display:none;border:none;background:rgba(255,255,255,.15);color:white;border-radius:8px;padding:4px 10px;cursor:pointer;font-size:1.1rem">✕</button>',
    '</div>',
    '<nav class="sidebar-nav">',
    buildNav(),
    '</nav>',
    '<div class="sidebar-footer">',
    '  <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">',
    '    <div class="user-avatar" style="background:rgba(255,255,255,.15);font-size:.8rem">' + adminInitial + '</div>',
    '    <div>',
    '      <div style="color:white;font-weight:600;font-size:.88rem">' + adminName + '</div>',
    '      <div style="color:rgba(255,255,255,.4);font-size:.75rem">مشرف النظام</div>',
    '    </div>',
    '  </div>',
    '  <button onclick="Auth.logout()" class="btn btn-ghost btn-sm btn-block" style="border-color:rgba(255,255,255,.2);color:rgba(255,255,255,.7)">🚪 تسجيل الخروج</button>',
    '</div>',
  ].join('\n');

  // ── Mobile bottom tab bar ─────────────────────────────────────────────────
  function buildMobileBar() {
    var tabs = MOBILE_TABS.map(function (href) {
      var it = NAV_ITEMS.find(function (n) { return n.href === href; });
      if (!it) return '';
      var on = isActive(href) ? ' on' : '';
      return '<a class="adm-tab' + on + '" href="' + href + '"><span class="ic">' + it.icon + '</span><span>' + it.label + '</span></a>';
    }).join('');
    var moreActive = MOBILE_TABS.indexOf(here) < 0 ? ' on' : '';
    tabs += '<button type="button" class="adm-tab' + moreActive + '" id="admMoreBtn"><span class="ic">☰</span><span>المزيد</span></button>';
    return '<nav class="adm-mbar" id="admMbar">' + tabs + '</nav>';
  }

  // ── Mobile "More" sheet (full menu) ───────────────────────────────────────
  function buildSheet() {
    var html = '<div class="adm-sheet-grip"></div><div class="adm-sheet-title">القائمة الكاملة</div>';
    var openGrid = false;
    NAV_ITEMS.forEach(function (item) {
      if (item.section) {
        if (openGrid) { html += '</div>'; openGrid = false; }
        html += '<h4>' + item.section + '</h4><div class="adm-sheet-grid">';
        openGrid = true;
      } else {
        var cls = isActive(item.href) ? ' class="active"' : '';
        html += '<a href="' + item.href + '"' + cls + '><span class="ic">' + item.icon + '</span>' + item.label + '</a>';
      }
    });
    if (openGrid) html += '</div>';
    html += '<button class="adm-sheet-logout" onclick="Auth.logout()">🚪 تسجيل الخروج</button>';
    return '<div class="adm-sheet-ov" id="admSheetOv"></div><div class="adm-sheet" id="admSheet">' + html + '</div>';
  }

  // ── Zoom lock on phones (app feel) ────────────────────────────────────────
  function lockZoom() {
    try {
      var vp = document.querySelector('meta[name="viewport"]');
      if (!vp) { vp = document.createElement('meta'); vp.name = 'viewport'; document.head.appendChild(vp); }
      vp.setAttribute('content', 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no');
      var isPhone = function () { return window.innerWidth <= 768; };
      ['gesturestart', 'gesturechange', 'gestureend'].forEach(function (ev) {
        document.addEventListener(ev, function (e) { if (isPhone()) e.preventDefault(); }, { passive: false });
      });
      document.addEventListener('touchmove', function (e) {
        if (isPhone() && e.touches && e.touches.length > 1) e.preventDefault();
      }, { passive: false });
      var last = 0;
      document.addEventListener('touchend', function (e) {
        if (!isPhone()) return;
        var now = Date.now();
        if (now - last <= 320) e.preventDefault();
        last = now;
      }, { passive: false });
    } catch (e) {}
  }

  // ── Inject into DOM ───────────────────────────────────────────────────────
  function inject() {
    lockZoom();

    var el = document.getElementById('adminSidebar');
    if (el) el.innerHTML = sidebarHTML;

    // Desktop drawer overlay + hamburger (kept for tablet ≤768 fallback isn't used;
    // the phone shell below is the primary mobile UI).
    var overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    overlay.id = 'sidebarOverlay';
    overlay.onclick = function () { closeSidebar(); };
    document.body.appendChild(overlay);

    // Mobile app shell
    var bar = document.createElement('div');
    bar.innerHTML = buildMobileBar() + buildSheet();
    while (bar.firstChild) document.body.appendChild(bar.firstChild);

    var moreBtn = document.getElementById('admMoreBtn');
    var sheet = document.getElementById('admSheet');
    var sheetOv = document.getElementById('admSheetOv');
    function openSheet() { if (sheet) sheet.classList.add('open'); if (sheetOv) sheetOv.classList.add('open'); }
    function closeSheet() { if (sheet) sheet.classList.remove('open'); if (sheetOv) sheetOv.classList.remove('open'); }
    if (moreBtn) moreBtn.addEventListener('click', openSheet);
    if (sheetOv) sheetOv.addEventListener('click', closeSheet);

    function closeSidebar() {
      var s = document.getElementById('adminSidebar');
      var o = document.getElementById('sidebarOverlay');
      if (s) s.classList.remove('open');
      if (o) o.classList.remove('active');
    }

    // Kept for any legacy onclick="toggleSidebar()" in page markup.
    window.toggleSidebar = function () {
      var s = document.getElementById('adminSidebar');
      var o = document.getElementById('sidebarOverlay');
      if (!s) return;
      var isOpen = s.classList.toggle('open');
      if (o) o.classList.toggle('active', isOpen);
    };
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', inject);
  else inject();
})();
