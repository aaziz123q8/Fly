(function() {
  // Inject CSS
  const style = document.createElement('style');
  style.textContent = `
    .bottom-nav { display:none; position:fixed; bottom:0; left:0; right:0; background:#fff; border-top:1px solid #e5e7eb; z-index:1000; padding:8px 0 env(safe-area-inset-bottom,8px); justify-content:space-around; }
    @media(max-width:768px) { .bottom-nav { display:flex; } body { padding-bottom:70px; } }
    .bnav-item { display:flex; flex-direction:column; align-items:center; gap:3px; padding:6px 16px; text-decoration:none; color:#6b7280; font-size:0.7rem; transition:color .2s; }
    .bnav-item:hover, .bnav-item.active { color:#1B4F8E; }
    .bnav-icon { display:flex; align-items:center; justify-content:center; }
    .bnav-icon svg { width:22px; height:22px; }
    [data-theme="dark"] .bottom-nav { background:#1e293b; border-color:#334155; }
  `;
  document.head.appendChild(style);

  // Monochrome icons that inherit the link colour (brand navy when active, grey
  // otherwise) — replaces the multicolour emoji for a clean, unified look.
  var ICON = {
    home:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    flight:  '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16v-2l-8-5V3.5a1.5 1.5 0 0 0-3 0V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5z"/></svg>',
    hotel:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V5a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v16"/><path d="M9 8h.01M15 8h.01M9 12h.01M15 12h.01M10 21v-4h4v4"/></svg>',
    user:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'
  };

  const path = window.location.pathname;
  // Canonical page id — always "<name>.html" so clean URLs (/flights) match.
  let here = (path.split('/').pop() || '').replace(/\.html$/, '');
  if (!here) here = 'index';
  here += '.html';
  const links = [
    { href: 'index.html', icon: ICON.home, label: 'الرئيسية' },
    { href: 'flights.html', icon: ICON.flight, label: 'رحلات' },
    { href: 'hotels.html', icon: ICON.hotel, label: 'فنادق' },
    { href: 'dashboard.html', icon: ICON.user, label: 'حسابي' },
  ];

  const nav = document.createElement('nav');
  nav.className = 'bottom-nav';
  nav.innerHTML = links.map(function(l) {
    // Active if path ends with the href, or for index if we're at root
    var active = (here === l.href || (l.href === 'index.html' && (path === '/' || path.endsWith('/')))) ? ' active' : '';
    return '<a href="' + l.href + '" class="bnav-item' + active + '"><span class="bnav-icon">' + l.icon + '</span><span>' + l.label + '</span></a>';
  }).join('');
  document.body.appendChild(nav);
})();
