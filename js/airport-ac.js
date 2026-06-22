/**
 * Airport autocomplete — calls /api/flights/airports?q=... (Duffel places/suggestions)
 * Usage: initAirportAC('inputId', 'dropId', { onSelect: fn(code, label) })
 */
(function(global) {
  var _cache = {};
  var _timers = {};

  function initAirportAC(inputId, dropId, opts) {
    opts = opts || {};
    var input = document.getElementById(inputId);
    var drop  = document.getElementById(dropId);
    if (!input || !drop) return;

    input.setAttribute('autocomplete', 'off');

    input.addEventListener('input', function() {
      clearTimeout(_timers[inputId]);
      var q = input.value.trim();
      if (q.length < 2) { closeDrop(drop); return; }
      _timers[inputId] = setTimeout(function() { fetchSuggest(q, input, drop, opts); }, 280);
    });

    input.addEventListener('keydown', function(e) {
      var items = drop.querySelectorAll('.ac-item');
      var active = drop.querySelector('.ac-item.ac-hover');
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!active) { if (items[0]) items[0].classList.add('ac-hover'); }
        else { active.classList.remove('ac-hover'); var nx = active.nextElementSibling; if (nx) nx.classList.add('ac-hover'); }
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (active) { active.classList.remove('ac-hover'); var pv = active.previousElementSibling; if (pv) pv.classList.add('ac-hover'); }
      } else if (e.key === 'Enter') {
        if (active) { e.preventDefault(); active.click(); }
      } else if (e.key === 'Escape') {
        closeDrop(drop);
      }
    });

    document.addEventListener('click', function(e) {
      if (!input.contains(e.target) && !drop.contains(e.target)) closeDrop(drop);
    });
  }

  function fetchSuggest(q, input, drop, opts) {
    var key = q.toLowerCase();
    if (_cache[key]) { renderDrop(_cache[key], input, drop, opts); return; }

    drop.innerHTML = '<div class="ac-loading">جاري البحث…</div>';
    drop.style.display = 'block';

    fetch('/api/flights/airports?q=' + encodeURIComponent(q))
      .then(function(r) { return r.json(); })
      .then(function(res) {
        var items = res.data || res.airports || res || [];
        _cache[key] = items;
        renderDrop(items, input, drop, opts);
      })
      .catch(function() { drop.innerHTML = '<div class="ac-loading">تعذّر البحث</div>'; });
  }

  function renderDrop(items, input, drop, opts) {
    if (!items.length) { closeDrop(drop); return; }
    drop.innerHTML = items.slice(0, 12).map(function(p) {
      var code    = p.iata_code || p.iata_country_code || p.code || '';
      var name    = p.name || p.city_name || code;
      var city    = p.city_name || '';
      var country = p.iata_country_code || p.country_code || '';
      var type    = p.type === 'airport' ? '✈' : (p.type === 'city' ? '🏙' : '📍');
      var display = name + (city && city !== name ? ' — ' + city : '') + (code ? ' (' + code + ')' : '');
      return '<div class="ac-item" data-code="' + escHtml(code) + '" data-label="' + escHtml(display) + '">'
        + '<span class="ac-type">' + type + '</span>'
        + '<div class="ac-info">'
        +   '<div class="ac-name">' + escHtml(name) + (city && city !== name ? '<span class="ac-city"> — ' + escHtml(city) + '</span>' : '') + '</div>'
        +   '<div class="ac-meta">' + escHtml(code) + (country ? ' · ' + escHtml(country) : '') + '</div>'
        + '</div>'
        + '</div>';
    }).join('');

    drop.querySelectorAll('.ac-item').forEach(function(el) {
      el.addEventListener('click', function() {
        var code  = el.dataset.code;
        var label = el.dataset.label;
        input.value = label;
        input.dataset.code = code;
        closeDrop(drop);
        if (opts.onSelect) opts.onSelect(code, label);
      });
    });

    drop.style.display = 'block';
  }

  function closeDrop(drop) { drop.style.display = 'none'; drop.innerHTML = ''; }
  function escHtml(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  global.initAirportAC = initAirportAC;
})(window);
