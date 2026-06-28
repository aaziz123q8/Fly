(function() {
  // Inject CSS
  const style = document.createElement('style');
  style.textContent = `
    .bottom-nav { display:none; position:fixed; bottom:0; left:0; right:0; background:#fff; border-top:1px solid #e5e7eb; z-index:1000; padding:8px 0 env(safe-area-inset-bottom,8px); justify-content:space-around; }
    @media(max-width:768px) { .bottom-nav { display:flex; } body { padding-bottom:70px; } }
    .bnav-item { display:flex; flex-direction:column; align-items:center; gap:3px; padding:6px 16px; text-decoration:none; color:#6b7280; font-size:0.7rem; transition:color .2s; }
    .bnav-item:hover, .bnav-item.active { color:#1B4F8E; }
    .bnav-icon { font-size:1.4rem; }
    [data-theme="dark"] .bottom-nav { background:#1e293b; border-color:#334155; }
  `;
  document.head.appendChild(style);

  const path = window.location.pathname;
  const links = [
    { href: 'index.html', icon: '🏠', label: 'الرئيسية' },
    { href: 'flights.html', icon: '✈️', label: 'رحلات' },
    { href: 'hotels.html', icon: '🏨', label: 'فنادق' },
    { href: 'dashboard.html', icon: '👤', label: 'حسابي' },
  ];

  const nav = document.createElement('nav');
  nav.className = 'bottom-nav';
  nav.innerHTML = links.map(function(l) {
    // Active if path ends with the href, or for index if we're at root
    var active = path.endsWith(l.href) || (l.href === 'index.html' && (path === '/' || path.endsWith('/'))) ? ' active' : '';
    return '<a href="' + l.href + '" class="bnav-item' + active + '"><span class="bnav-icon">' + l.icon + '</span><span>' + l.label + '</span></a>';
  }).join('');
  document.body.appendChild(nav);
})();
