// Dark mode toggle — auto-injects button into .navbar elements
(function () {
  const STORAGE_KEY = 'flymasar_theme';

  function applyTheme(dark) {
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    document.querySelectorAll('.dark-toggle').forEach(btn => {
      btn.textContent = dark ? '☀️' : '🌙';
      btn.title = dark ? 'الوضع النهاري' : 'الوضع الليلي';
    });
  }

  function toggle() {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const next = !isDark;
    localStorage.setItem(STORAGE_KEY, next ? 'dark' : 'light');
    applyTheme(next);
  }

  function injectButton() {
    // Inject into every .navbar found on the page
    document.querySelectorAll('.navbar .container > div:last-child, .navbar > .container > div').forEach(nav => {
      if (nav.querySelector('.dark-toggle')) return;
      const btn = document.createElement('button');
      btn.className = 'dark-toggle';
      btn.onclick = toggle;
      nav.prepend(btn);
    });
  }

  // Apply saved preference immediately (before render)
  const saved = localStorage.getItem(STORAGE_KEY) || 'light';
  applyTheme(saved === 'dark');

  // Inject button after DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', injectButton);
  } else {
    injectButton();
  }
})();
