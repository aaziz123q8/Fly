/**
 * Shared airport autocomplete — the same professional engine used by the
 * homepage flight search: an instant local Arabic airport list, then enriched
 * live with Duffel results from /api/flights/airports.
 *
 * Markup contract (matches the homepage):
 *   <input id="fFrom" oninput="showDrop('fFrom','dFrom')" autocomplete="off">
 *   <div class="adrop" id="dFrom"></div>
 * On pick: input.value = "Name (CODE)", input.dataset.code = "CODE".
 */
(function (global) {
  'use strict';
  if (global.AIRPORTS && global.showDrop) return; // already loaded (e.g. homepage inline)

  var AIRPORTS = [
    {code:'KWI',name:'الكويت الدولي',nameEn:'Kuwait International',city:'الكويت',cityEn:'Kuwait City',country:'الكويت',countryEn:'Kuwait'},
    {code:'DXB',name:'دبي الدولي',nameEn:'Dubai International',city:'دبي',cityEn:'Dubai',country:'الإمارات',countryEn:'UAE'},
    {code:'RUH',name:'الرياض الملك خالد',nameEn:'King Khalid International',city:'الرياض',cityEn:'Riyadh',country:'السعودية',countryEn:'Saudi Arabia'},
    {code:'JED',name:'جدة الملك عبدالعزيز',nameEn:'King Abdulaziz International',city:'جدة',cityEn:'Jeddah',country:'السعودية',countryEn:'Saudi Arabia'},
    {code:'DOH',name:'الدوحة حمد الدولي',nameEn:'Hamad International',city:'الدوحة',cityEn:'Doha',country:'قطر',countryEn:'Qatar'},
    {code:'AUH',name:'أبوظبي الدولي',nameEn:'Abu Dhabi International',city:'أبوظبي',cityEn:'Abu Dhabi',country:'الإمارات',countryEn:'UAE'},
    {code:'BAH',name:'البحرين الدولي',nameEn:'Bahrain International',city:'المنامة',cityEn:'Manama',country:'البحرين',countryEn:'Bahrain'},
    {code:'MCT',name:'مسقط الدولي',nameEn:'Muscat International',city:'مسقط',cityEn:'Muscat',country:'عُمان',countryEn:'Oman'},
    {code:'CAI',name:'القاهرة الدولي',nameEn:'Cairo International',city:'القاهرة',cityEn:'Cairo',country:'مصر',countryEn:'Egypt'},
    {code:'AMM',name:'عمّان الملكة علياء',nameEn:'Queen Alia International',city:'عمّان',cityEn:'Amman',country:'الأردن',countryEn:'Jordan'},
    {code:'BEY',name:'بيروت رفيق الحريري',nameEn:'Beirut Rafic Hariri',city:'بيروت',cityEn:'Beirut',country:'لبنان',countryEn:'Lebanon'},
    {code:'IST',name:'إسطنبول',nameEn:'Istanbul Airport',city:'إسطنبول',cityEn:'Istanbul',country:'تركيا',countryEn:'Turkey'},
    {code:'LHR',name:'لندن هيثرو',nameEn:'London Heathrow',city:'لندن',cityEn:'London',country:'المملكة المتحدة',countryEn:'United Kingdom'},
    {code:'CDG',name:'باريس شارل ديغول',nameEn:'Paris Charles de Gaulle',city:'باريس',cityEn:'Paris',country:'فرنسا',countryEn:'France'},
    {code:'FRA',name:'فرانكفورت',nameEn:'Frankfurt Airport',city:'فرانكفورت',cityEn:'Frankfurt',country:'ألمانيا',countryEn:'Germany'},
    {code:'JFK',name:'نيويورك جون كيندي',nameEn:'John F. Kennedy',city:'نيويورك',cityEn:'New York',country:'الولايات المتحدة',countryEn:'United States'},
    {code:'LAX',name:'لوس أنجلوس',nameEn:'Los Angeles International',city:'لوس أنجلوس',cityEn:'Los Angeles',country:'الولايات المتحدة',countryEn:'United States'},
    {code:'SIN',name:'سنغافورة تشانغي',nameEn:'Singapore Changi',city:'سنغافورة',cityEn:'Singapore',country:'سنغافورة',countryEn:'Singapore'},
    {code:'BKK',name:'بانكوك سوفارنابومي',nameEn:'Bangkok Suvarnabhumi',city:'بانكوك',cityEn:'Bangkok',country:'تايلاند',countryEn:'Thailand'},
    {code:'MAN',name:'مانشستر',nameEn:'Manchester Airport',city:'مانشستر',cityEn:'Manchester',country:'المملكة المتحدة',countryEn:'United Kingdom'},
    {code:'MED',name:'المدينة المنورة',nameEn:'Prince Mohammad bin Abdulaziz',city:'المدينة المنورة',cityEn:'Medina',country:'السعودية',countryEn:'Saudi Arabia'},
    {code:'LGW',name:'لندن غاتويك',nameEn:'London Gatwick',city:'لندن',cityEn:'London',country:'المملكة المتحدة',countryEn:'United Kingdom'},
    {code:'TUN',name:'تونس قرطاج',nameEn:'Tunis Carthage',city:'تونس',cityEn:'Tunis',country:'تونس',countryEn:'Tunisia'},
    {code:'CMN',name:'الدار البيضاء',nameEn:'Mohammed V International',city:'الدار البيضاء',cityEn:'Casablanca',country:'المغرب',countryEn:'Morocco'},
    {code:'AMS',name:'أمستردام سخيبول',nameEn:'Amsterdam Schiphol',city:'أمستردام',cityEn:'Amsterdam',country:'هولندا',countryEn:'Netherlands'},
    {code:'ALG',name:'الجزائر',nameEn:'Houari Boumediene',city:'الجزائر',cityEn:'Algiers',country:'الجزائر',countryEn:'Algeria'},
  ];

  var _acCache = {}, _acTimers = {};

  function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/'/g,'&#39;').replace(/"/g,'&quot;'); }

  function showDrop(inputId, dropId) {
    var input = document.getElementById(inputId);
    var drop  = document.getElementById(dropId);
    if (!input || !drop) return;
    var q = input.value.trim();
    if (q.length < 2) { drop.classList.remove('open'); return; }

    // Local filter first (instant, Arabic)
    var ql = q.toLowerCase();
    var local = AIRPORTS.filter(function (a) {
      return a.code.toLowerCase().indexOf(ql) > -1 ||
             a.name.toLowerCase().indexOf(ql) > -1 ||
             (a.nameEn||'').toLowerCase().indexOf(ql) > -1 ||
             a.city.toLowerCase().indexOf(ql) > -1 ||
             (a.cityEn||'').toLowerCase().indexOf(ql) > -1;
    }).slice(0, 6);

    if (local.length) {
      drop.innerHTML = local.map(function (a) {
        return '<div class="aitem" onclick="pickAirport(\'' + inputId + '\',\'' + dropId + '\',\'' + a.code + '\',\'' + esc(a.name) + ' (' + a.code + ')\')">' +
          '<span class="acode">' + a.code + '</span>' +
          '<div><div class="aname">' + esc(a.name) + '</div><div class="acountry">' + esc(a.country) + '</div></div>' +
        '</div>';
      }).join('');
      drop.classList.add('open');
    }

    // Then enrich with Duffel API results
    clearTimeout(_acTimers[inputId]);
    _acTimers[inputId] = setTimeout(function () {
      var key = q.toLowerCase();
      if (_acCache[key]) { renderApiDrop(inputId, dropId, _acCache[key]); return; }
      fetch('/api/flights/airports?q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (res) {
          _acCache[key] = res.data || res.airports || [];
          renderApiDrop(inputId, dropId, _acCache[key]);
        }).catch(function () {});
    }, 300);
  }

  function renderApiDrop(inputId, dropId, items) {
    var drop = document.getElementById(dropId);
    if (!drop || !items || !items.length) return;
    drop.innerHTML = items.slice(0, 10).map(function (p) {
      var code = p.iata_code || p.code || '';
      var name = p.name || p.city_name || code;
      var city = p.city_name || '';
      var country = p.iata_country_code || '';
      var display = name + (city && city !== name ? ' — ' + city : '') + ' (' + code + ')';
      var type = p.type === 'airport' ? '✈' : '🏙';
      return '<div class="aitem" onclick="pickAirport(\'' + inputId + '\',\'' + dropId + '\',\'' + esc(code) + '\',\'' + esc(display) + '\')">' +
        '<span class="acode">' + type + ' ' + esc(code) + '</span>' +
        '<div><div class="aname">' + esc(name) + (city && city !== name ? '<span style="color:#9ca3af"> — ' + esc(city) + '</span>' : '') + '</div><div class="acountry">' + esc(country) + '</div></div>' +
      '</div>';
    }).join('');
    drop.classList.add('open');
  }

  function pickAirport(inputId, dropId, code, label) {
    var el = document.getElementById(inputId);
    if (!el) return;
    el.value = label;
    el.dataset.code = code;
    el.classList.add('ok');
    var drop = document.getElementById(dropId);
    if (drop) drop.classList.remove('open');
  }

  global.AIRPORTS = AIRPORTS;
  global.showDrop = showDrop;
  global.renderApiDrop = renderApiDrop;
  global.pickAirport = pickAirport;
})(window);
