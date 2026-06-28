/**
 * admin-sidebar.js — FlyMasar Admin Panel
 * Dynamically injects the sidebar into #adminSidebar element
 * and handles auth check, active nav item, admin name display.
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
    { section: 'التسويق' },
    { href: 'coupons.html',       icon: '🎟️', label: 'القسائم' },
    { href: 'commissions.html',   icon: '💰', label: 'العمولات' },
    { section: 'التحليلات' },
    { href: 'analytics.html',     icon: '📈', label: 'التحليلات والتقارير' },
    { href: 'notifications.html', icon: '🔔', label: 'الإشعارات' },
    { section: 'الإعدادات' },
    { href: 'currencies.html',    icon: '💱', label: 'العملات' },
    { href: 'api-settings.html',  icon: '⚙️', label: 'إعدادات API' },
    { href: 'cms.html',           icon: '📝', label: 'المحتوى' },
    { section: 'النظام' },
    { href: '../index.html',      icon: '🌐', label: 'الموقع الرئيسي' },
  ];

  // ── Active detection ──────────────────────────────────────────────────────
  const currentPath = window.location.pathname;

  function isActive(href) {
    if (!href) return false;
    const base = href.replace(/^\.\.\//, '');
    return currentPath.endsWith('/' + href) || currentPath.endsWith('/' + base) || currentPath.endsWith(href);
  }

  function buildNav() {
    return NAV_ITEMS.map(function (item) {
      if (item.section) {
        return '<div class="sidebar-section-title">' + item.section + '</div>';
      }
      var cls = isActive(item.href) ? ' class="active"' : '';
      return '<a href="' + item.href + '"' + cls + '><span class="nav-icon">' + item.icon + '</span> ' + item.label + '</a>';
    }).join('\n');
  }

  // ── Admin name / initial ──────────────────────────────────────────────────
  var adminName = user.first_name || user.email || 'المشرف';
  var adminInitial = adminName[0].toUpperCase();

  // ── Build sidebar HTML ────────────────────────────────────────────────────
  var sidebarHTML = [
    '<div class="sidebar-header">',
    '  <div class="sidebar-brand">✈️ Fly<span>Masar</span></div>',
    '  <div style="font-size:.75rem;color:rgba(255,255,255,.4);margin-top:4px">لوحة التحكم</div>',
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

  // ── Inject into DOM ───────────────────────────────────────────────────────
  function inject() {
    var el = document.getElementById('adminSidebar');
    if (el) {
      el.innerHTML = sidebarHTML;
    }
    // Mobile sidebar toggle
    window.toggleSidebar = function () {
      var s = document.getElementById('adminSidebar');
      if (s) s.classList.toggle('open');
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inject);
  } else {
    inject();
  }

})();
