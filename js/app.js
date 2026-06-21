/**
 * FlyMasar Main Application JavaScript
 * Handles: airport autocomplete, passenger counters, tabs,
 * mobile nav, currency dropdown, search form logic
 */

'use strict';

/* ============================================================
   AIRPORT DATA
   ============================================================ */
const airports = window.airports = [
  { code: 'LHR', name: 'لندن هيثرو', nameEn: 'London Heathrow', city: 'لندن', cityEn: 'London', country: 'المملكة المتحدة', countryEn: 'United Kingdom' },
  { code: 'LGW', name: 'لندن غاتويك', nameEn: 'London Gatwick', city: 'لندن', cityEn: 'London', country: 'المملكة المتحدة', countryEn: 'United Kingdom' },
  { code: 'DXB', name: 'دبي الدولي', nameEn: 'Dubai International', city: 'دبي', cityEn: 'Dubai', country: 'الإمارات', countryEn: 'UAE' },
  { code: 'KWI', name: 'الكويت الدولي', nameEn: 'Kuwait International', city: 'الكويت', cityEn: 'Kuwait City', country: 'الكويت', countryEn: 'Kuwait' },
  { code: 'RUH', name: 'الرياض الملك خالد', nameEn: 'King Khalid International', city: 'الرياض', cityEn: 'Riyadh', country: 'السعودية', countryEn: 'Saudi Arabia' },
  { code: 'JED', name: 'جدة الملك عبدالعزيز', nameEn: 'King Abdulaziz International', city: 'جدة', cityEn: 'Jeddah', country: 'السعودية', countryEn: 'Saudi Arabia' },
  { code: 'CAI', name: 'القاهرة الدولي', nameEn: 'Cairo International', city: 'القاهرة', cityEn: 'Cairo', country: 'مصر', countryEn: 'Egypt' },
  { code: 'AMM', name: 'عمّان الملكة علياء', nameEn: 'Queen Alia International', city: 'عمّان', cityEn: 'Amman', country: 'الأردن', countryEn: 'Jordan' },
  { code: 'BEY', name: 'بيروت رفيق الحريري', nameEn: 'Beirut Rafic Hariri', city: 'بيروت', cityEn: 'Beirut', country: 'لبنان', countryEn: 'Lebanon' },
  { code: 'IST', name: 'إسطنبول', nameEn: 'Istanbul Airport', city: 'إسطنبول', cityEn: 'Istanbul', country: 'تركيا', countryEn: 'Turkey' },
  { code: 'DOH', name: 'الدوحة حمد الدولي', nameEn: 'Hamad International', city: 'الدوحة', cityEn: 'Doha', country: 'قطر', countryEn: 'Qatar' },
  { code: 'AUH', name: 'أبوظبي الدولي', nameEn: 'Abu Dhabi International', city: 'أبوظبي', cityEn: 'Abu Dhabi', country: 'الإمارات', countryEn: 'UAE' },
  { code: 'BAH', name: 'البحرين الدولي', nameEn: 'Bahrain International', city: 'المنامة', cityEn: 'Manama', country: 'البحرين', countryEn: 'Bahrain' },
  { code: 'MCT', name: 'مسقط الدولي', nameEn: 'Muscat International', city: 'مسقط', cityEn: 'Muscat', country: 'عُمان', countryEn: 'Oman' },
  { code: 'CDG', name: 'باريس شارل ديغول', nameEn: 'Paris Charles de Gaulle', city: 'باريس', cityEn: 'Paris', country: 'فرنسا', countryEn: 'France' },
  { code: 'FRA', name: 'فرانكفورت الدولي', nameEn: 'Frankfurt Airport', city: 'فرانكفورت', cityEn: 'Frankfurt', country: 'ألمانيا', countryEn: 'Germany' },
  { code: 'JFK', name: 'نيويورك جون كيندي', nameEn: 'John F. Kennedy', city: 'نيويورك', cityEn: 'New York', country: 'الولايات المتحدة', countryEn: 'United States' },
  { code: 'LAX', name: 'لوس أنجلوس الدولي', nameEn: 'Los Angeles International', city: 'لوس أنجلوس', cityEn: 'Los Angeles', country: 'الولايات المتحدة', countryEn: 'United States' },
  { code: 'SIN', name: 'سنغافورة تشانغي', nameEn: 'Singapore Changi', city: 'سنغافورة', cityEn: 'Singapore', country: 'سنغافورة', countryEn: 'Singapore' },
  { code: 'BKK', name: 'بانكوك سوفارنابومي', nameEn: 'Bangkok Suvarnabhumi', city: 'بانكوك', cityEn: 'Bangkok', country: 'تايلاند', countryEn: 'Thailand' },
  { code: 'MAN', name: 'مانشستر الدولي', nameEn: 'Manchester Airport', city: 'مانشستر', cityEn: 'Manchester', country: 'المملكة المتحدة', countryEn: 'United Kingdom' },
  { code: 'TUN', name: 'تونس قرطاج الدولي', nameEn: 'Tunis Carthage', city: 'تونس', cityEn: 'Tunis', country: 'تونس', countryEn: 'Tunisia' },
  { code: 'CMN', name: 'الدار البيضاء محمد الخامس', nameEn: 'Mohammed V International', city: 'الدار البيضاء', cityEn: 'Casablanca', country: 'المغرب', countryEn: 'Morocco' },
  { code: 'ALG', name: 'الجزائر هواري بومدين', nameEn: 'Houari Boumediene', city: 'الجزائر', cityEn: 'Algiers', country: 'الجزائر', countryEn: 'Algeria' },
  { code: 'DUS', name: 'دوسلدورف الدولي', nameEn: 'Dusseldorf International', city: 'دوسلدورف', cityEn: 'Dusseldorf', country: 'ألمانيا', countryEn: 'Germany' },
  { code: 'AMS', name: 'أمستردام سخيبول', nameEn: 'Amsterdam Schiphol', city: 'أمستردام', cityEn: 'Amsterdam', country: 'هولندا', countryEn: 'Netherlands' },
];

/* ============================================================
   STATE
   ============================================================ */
const state = {
  activeTab: 'flights',
  tripType: 'roundtrip',
  fromAirport: null,
  toAirport: null,
  departureDate: null,
  returnDate: null,
  passengers: { adults: 1, children: 0, infants: 0 },
  flightClass: 'economy',
  hotelDestination: '',
  checkinDate: null,
  checkoutDate: null,
  rooms: 1,
  hotelAdults: 2,
  hotelChildren: 0,
};

/* ============================================================
   AIRPORT AUTOCOMPLETE
   ============================================================ */
function filterAirports(query) {
  if (!query || query.length < 1) return [];
  const q = query.toLowerCase().trim();
  const lang = document.documentElement.lang === 'en' ? 'en' : 'ar';
  return airports.filter(a => {
    return (
      a.code.toLowerCase().includes(q) ||
      a.name.toLowerCase().includes(q) ||
      a.nameEn.toLowerCase().includes(q) ||
      a.city.toLowerCase().includes(q) ||
      a.cityEn.toLowerCase().includes(q) ||
      a.country.toLowerCase().includes(q) ||
      a.countryEn.toLowerCase().includes(q)
    );
  }).slice(0, 8);
}

function createAirportDropdown(inputEl, displayEl, onSelect) {
  const field = inputEl.closest('.search-field') || inputEl.parentElement;
  const dropdown = document.createElement('div');
  dropdown.className = 'airport-dropdown';
  field.appendChild(dropdown);

  let highlightIndex = -1;
  let results = [];

  function renderDropdown(matches) {
    results = matches;
    highlightIndex = -1;
    dropdown.innerHTML = '';
    if (matches.length === 0) {
      dropdown.classList.remove('open');
      return;
    }
    const lang = document.documentElement.lang === 'en' ? 'en' : 'ar';
    matches.forEach((a, idx) => {
      const opt = document.createElement('div');
      opt.className = 'airport-option';
      opt.innerHTML = `
        <span class="airport-option-code">${a.code}</span>
        <div class="airport-option-info">
          <div class="airport-option-name">${lang === 'ar' ? a.name : a.nameEn} — ${lang === 'ar' ? a.city : a.cityEn}</div>
          <div class="airport-option-country">${lang === 'ar' ? a.country : a.countryEn}</div>
        </div>
      `;
      opt.addEventListener('mousedown', (e) => {
        e.preventDefault();
        selectAirport(a);
      });
      dropdown.appendChild(opt);
    });
    dropdown.classList.add('open');
  }

  function selectAirport(airport) {
    onSelect(airport);
    const lang = document.documentElement.lang === 'en' ? 'en' : 'ar';
    inputEl.value = lang === 'ar' ? `${airport.name} (${airport.code})` : `${airport.nameEn} (${airport.code})`;
    if (displayEl) {
      const badge = displayEl.querySelector('.airport-code-badge');
      const text = displayEl.querySelector('.placeholder-text') || displayEl;
      if (badge) badge.textContent = airport.code;
      else displayEl.innerHTML = `<span class="airport-code-badge">${airport.code}</span> ${lang === 'ar' ? airport.name : airport.nameEn}`;
    }
    dropdown.classList.remove('open');
    highlightIndex = -1;
  }

  inputEl.addEventListener('input', () => {
    const matches = filterAirports(inputEl.value);
    renderDropdown(matches);
  });

  inputEl.addEventListener('focus', () => {
    if (inputEl.value.length > 0) {
      renderDropdown(filterAirports(inputEl.value));
    } else {
      // Show popular airports on focus
      renderDropdown(airports.slice(0, 6));
    }
  });

  inputEl.addEventListener('keydown', (e) => {
    const items = dropdown.querySelectorAll('.airport-option');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      highlightIndex = Math.min(highlightIndex + 1, items.length - 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      highlightIndex = Math.max(highlightIndex - 1, 0);
    } else if (e.key === 'Enter' && highlightIndex >= 0) {
      e.preventDefault();
      if (results[highlightIndex]) selectAirport(results[highlightIndex]);
    } else if (e.key === 'Escape') {
      dropdown.classList.remove('open');
    }
    items.forEach((item, i) => item.classList.toggle('highlighted', i === highlightIndex));
  });

  inputEl.addEventListener('blur', () => {
    setTimeout(() => dropdown.classList.remove('open'), 150);
  });

  document.addEventListener('langChanged', () => {
    // Re-render if open
    if (dropdown.classList.contains('open') && results.length) {
      renderDropdown(results);
    }
  });

  return dropdown;
}

/* ============================================================
   PASSENGER COUNTER (FLIGHTS)
   ============================================================ */
function initPassengerCounter() {
  const trigger = document.getElementById('passengersTrigger');
  const popup = document.getElementById('passengersPopup');
  if (!trigger || !popup) return;

  function updateTrigger() {
    const lang = document.documentElement.lang === 'en' ? 'en' : 'ar';
    const total = state.passengers.adults + state.passengers.children + state.passengers.infants;
    const classNames = {
      economy: lang === 'ar' ? 'اقتصادية' : 'Economy',
      business: lang === 'ar' ? 'أعمال' : 'Business',
      first: lang === 'ar' ? 'أولى' : 'First',
    };
    const className = classNames[state.flightClass] || classNames.economy;
    const ph = trigger.querySelector('.placeholder-text');
    if (ph) {
      if (lang === 'ar') {
        ph.textContent = `${total} ${total === 1 ? 'مسافر' : 'مسافرون'} · ${className}`;
      } else {
        ph.textContent = `${total} ${total === 1 ? 'Passenger' : 'Passengers'} · ${className}`;
      }
    }
    // Update counter displays
    ['adults', 'children', 'infants'].forEach(type => {
      const el = document.getElementById(`count-${type}`);
      if (el) el.textContent = state.passengers[type];
    });
    // Update button states
    const adultsDec = document.getElementById('dec-adults');
    if (adultsDec) adultsDec.disabled = state.passengers.adults <= 1;
    const childrenDec = document.getElementById('dec-children');
    if (childrenDec) childrenDec.disabled = state.passengers.children <= 0;
    const infantsDec = document.getElementById('dec-infants');
    if (infantsDec) infantsDec.disabled = state.passengers.infants <= 0;
    // Max 9 total
    const total2 = state.passengers.adults + state.passengers.children + state.passengers.infants;
    ['adults', 'children', 'infants'].forEach(type => {
      const incBtn = document.getElementById(`inc-${type}`);
      if (incBtn) incBtn.disabled = total2 >= 9;
    });
    // Infants can't exceed adults
    const infantsInc = document.getElementById('inc-infants');
    if (infantsInc) infantsInc.disabled = state.passengers.infants >= state.passengers.adults || total2 >= 9;
  }

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    popup.classList.toggle('open');
    // Close other popups
    document.querySelectorAll('.rooms-popup.open').forEach(p => p.classList.remove('open'));
  });

  // Counter buttons
  ['adults', 'children', 'infants'].forEach(type => {
    const dec = document.getElementById(`dec-${type}`);
    const inc = document.getElementById(`inc-${type}`);
    if (dec) dec.addEventListener('click', () => {
      const min = type === 'adults' ? 1 : 0;
      if (state.passengers[type] > min) {
        state.passengers[type]--;
        // Infants can't exceed adults
        if (type === 'adults' && state.passengers.infants > state.passengers.adults) {
          state.passengers.infants = state.passengers.adults;
        }
        updateTrigger();
      }
    });
    if (inc) inc.addEventListener('click', () => {
      const total = state.passengers.adults + state.passengers.children + state.passengers.infants;
      if (total >= 9) return;
      if (type === 'infants' && state.passengers.infants >= state.passengers.adults) return;
      state.passengers[type]++;
      updateTrigger();
    });
  });

  // Class selector
  const classSelect = document.getElementById('flightClass');
  if (classSelect) {
    classSelect.addEventListener('change', () => {
      state.flightClass = classSelect.value;
      updateTrigger();
    });
  }

  // Done button
  const doneBtn = document.getElementById('paxDone');
  if (doneBtn) doneBtn.addEventListener('click', () => popup.classList.remove('open'));

  // Close on outside click
  document.addEventListener('click', (e) => {
    if (!popup.contains(e.target) && e.target !== trigger) {
      popup.classList.remove('open');
    }
  });

  document.addEventListener('langChanged', updateTrigger);
  updateTrigger();
}

/* ============================================================
   ROOMS / GUESTS COUNTER (HOTELS)
   ============================================================ */
function initRoomsCounter() {
  const trigger = document.getElementById('roomsTrigger');
  const popup = document.getElementById('roomsPopup');
  if (!trigger || !popup) return;

  function updateTrigger() {
    const lang = document.documentElement.lang === 'en' ? 'en' : 'ar';
    const ph = trigger.querySelector('.placeholder-text');
    if (ph) {
      if (lang === 'ar') {
        ph.textContent = `${state.rooms} غرفة · ${state.hotelAdults} بالغ${state.hotelChildren > 0 ? ' · ' + state.hotelChildren + ' طفل' : ''}`;
      } else {
        ph.textContent = `${state.rooms} Room${state.rooms > 1 ? 's' : ''} · ${state.hotelAdults} Adult${state.hotelAdults > 1 ? 's' : ''}${state.hotelChildren > 0 ? ' · ' + state.hotelChildren + ' Child' : ''}`;
      }
    }
    ['rooms', 'hotelAdults', 'hotelChildren'].forEach(key => {
      const el = document.getElementById(`count-${key}`);
      if (el) el.textContent = state[key];
    });
    const roomsDec = document.getElementById('dec-rooms');
    if (roomsDec) roomsDec.disabled = state.rooms <= 1;
    const adultsDec = document.getElementById('dec-hotelAdults');
    if (adultsDec) adultsDec.disabled = state.hotelAdults <= 1;
    const childrenDec = document.getElementById('dec-hotelChildren');
    if (childrenDec) childrenDec.disabled = state.hotelChildren <= 0;
  }

  trigger.addEventListener('click', (e) => {
    e.stopPropagation();
    popup.classList.toggle('open');
    document.querySelectorAll('.passengers-popup.open').forEach(p => p.classList.remove('open'));
  });

  ['rooms', 'hotelAdults', 'hotelChildren'].forEach(key => {
    const dec = document.getElementById(`dec-${key}`);
    const inc = document.getElementById(`inc-${key}`);
    const minVals = { rooms: 1, hotelAdults: 1, hotelChildren: 0 };
    const maxVals = { rooms: 8, hotelAdults: 16, hotelChildren: 8 };
    if (dec) dec.addEventListener('click', () => {
      if (state[key] > minVals[key]) { state[key]--; updateTrigger(); }
    });
    if (inc) inc.addEventListener('click', () => {
      if (state[key] < maxVals[key]) { state[key]++; updateTrigger(); }
    });
  });

  const roomsDone = document.getElementById('roomsDone');
  if (roomsDone) roomsDone.addEventListener('click', () => popup.classList.remove('open'));

  document.addEventListener('click', (e) => {
    if (!popup.contains(e.target) && e.target !== trigger) {
      popup.classList.remove('open');
    }
  });

  document.addEventListener('langChanged', updateTrigger);
  updateTrigger();
}

/* ============================================================
   SEARCH TABS
   ============================================================ */
function initSearchTabs() {
  const tabs = document.querySelectorAll('.search-tab');
  const panels = document.querySelectorAll('.search-panel');
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.tab;
      tabs.forEach(t => t.classList.remove('active'));
      panels.forEach(p => p.classList.remove('active'));
      tab.classList.add('active');
      const panel = document.getElementById('panel-' + target);
      if (panel) panel.classList.add('active');
      state.activeTab = target;
    });
  });
}

/* ============================================================
   TRIP TYPE TOGGLE
   ============================================================ */
function initTripType() {
  const radios = document.querySelectorAll('input[name="tripType"]');
  const returnField = document.getElementById('returnDateField');
  radios.forEach(r => {
    r.addEventListener('change', () => {
      state.tripType = r.value;
      if (returnField) {
        returnField.style.display = r.value === 'oneway' ? 'none' : '';
        returnField.style.opacity = r.value === 'oneway' ? '0' : '1';
      }
    });
  });
}

/* ============================================================
   SWAP AIRPORTS
   ============================================================ */
function initSwapButton() {
  const swapBtn = document.getElementById('swapBtn');
  if (!swapBtn) return;
  swapBtn.addEventListener('click', () => {
    const fromInput = document.getElementById('fromInput');
    const toInput = document.getElementById('toInput');
    if (!fromInput || !toInput) return;

    const tempVal = fromInput.value;
    const tempAirport = state.fromAirport;
    fromInput.value = toInput.value;
    state.fromAirport = state.toAirport;
    toInput.value = tempVal;
    state.toAirport = tempAirport;

    // Update display elements
    updateAirportDisplay('fromDisplay', state.fromAirport);
    updateAirportDisplay('toDisplay', state.toAirport);
  });
}

function updateAirportDisplay(displayId, airport) {
  const el = document.getElementById(displayId);
  if (!el) return;
  if (!airport) {
    // reset to placeholder
    return;
  }
  const lang = document.documentElement.lang === 'en' ? 'en' : 'ar';
  el.innerHTML = `<span class="airport-code-badge">${airport.code}</span> ${lang === 'ar' ? airport.name : airport.nameEn}`;
}

/* ============================================================
   DATE PICKERS INITIALIZATION
   ============================================================ */
function initDatePickers() {
  const today = new Date();

  // Flight departure date
  const depInput = document.getElementById('departureDateInput');
  if (depInput) {
    const depPicker = new DatePicker(depInput, {
      mode: 'single',
      minDate: today,
      lang: document.documentElement.lang,
      onSelect(date) {
        state.departureDate = date;
        // Set return picker min date to next day
        if (window._returnPicker) {
          const nextDay = new Date(date);
          nextDay.setDate(nextDay.getDate() + 1);
          window._returnPicker.setMinDate(nextDay);
        }
      }
    });
    window._departurePicker = depPicker;
  }

  // Flight return date
  const retInput = document.getElementById('returnDateInput');
  if (retInput) {
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const retPicker = new DatePicker(retInput, {
      mode: 'single',
      minDate: tomorrow,
      lang: document.documentElement.lang,
      onSelect(date) {
        state.returnDate = date;
      }
    });
    window._returnPicker = retPicker;
  }

  // Hotel date range
  const hotelDateInput = document.getElementById('hotelDatesInput');
  if (hotelDateInput) {
    const hotelPicker = new DatePicker(hotelDateInput, {
      mode: 'range',
      minDate: today,
      lang: document.documentElement.lang,
      startInput: 'checkinDisplay',
      endInput: 'checkoutDisplay',
      onSelect(start, end) {
        state.checkinDate = start;
        state.checkoutDate = end;
      }
    });
    window._hotelPicker = hotelPicker;
  }

  // Contact checkin/checkout split pickers (if on hotel panel)
  const checkinInput = document.getElementById('checkinInput');
  const checkoutInput = document.getElementById('checkoutInput');
  if (checkinInput) {
    new DatePicker(checkinInput, {
      mode: 'single',
      minDate: today,
      onSelect(date) {
        state.checkinDate = date;
        if (window._checkoutPicker) {
          const next = new Date(date);
          next.setDate(next.getDate() + 1);
          window._checkoutPicker.setMinDate(next);
        }
      }
    });
  }
  if (checkoutInput) {
    const tom = new Date();
    tom.setDate(tom.getDate() + 1);
    window._checkoutPicker = new DatePicker(checkoutInput, {
      mode: 'single',
      minDate: tom,
      onSelect(date) { state.checkoutDate = date; }
    });
  }

  // Re-render pickers when lang changes
  document.addEventListener('langChanged', () => {
    [window._departurePicker, window._returnPicker, window._hotelPicker, window._checkoutPicker].forEach(p => {
      if (p) p.render();
    });
  });
}

/* ============================================================
   NAVBAR: SCROLL EFFECT + HAMBURGER + CURRENCY DROPDOWN
   ============================================================ */
function initNavbar() {
  // Scroll effect
  const navbar = document.querySelector('.navbar');
  if (navbar) {
    window.addEventListener('scroll', () => {
      navbar.classList.toggle('scrolled', window.scrollY > 20);
    });
  }

  // Hamburger menu
  const hamburger = document.getElementById('hamburger');
  const mobileMenu = document.getElementById('mobileMenu');
  const overlay = document.getElementById('mobileOverlay');

  function openMenu() {
    hamburger && hamburger.classList.add('open');
    mobileMenu && mobileMenu.classList.add('open');
    overlay && overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeMenu() {
    hamburger && hamburger.classList.remove('open');
    mobileMenu && mobileMenu.classList.remove('open');
    overlay && overlay.classList.remove('open');
    document.body.style.overflow = '';
  }

  if (hamburger) {
    hamburger.addEventListener('click', () => {
      hamburger.classList.contains('open') ? closeMenu() : openMenu();
    });
  }
  if (overlay) overlay.addEventListener('click', closeMenu);

  // Currency dropdown
  const currencyBtn = document.getElementById('currencyBtn');
  const currencyDropdown = document.getElementById('currencyDropdown');
  if (currencyBtn && currencyDropdown) {
    currencyBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      currencyBtn.classList.toggle('open');
      currencyDropdown.classList.toggle('open');
    });

    document.querySelectorAll('.currency-option').forEach(opt => {
      opt.addEventListener('click', () => {
        setCurrency(opt.dataset.currency);
        currencyBtn.classList.remove('open');
        currencyDropdown.classList.remove('open');
      });
    });

    document.addEventListener('click', (e) => {
      if (!currencyBtn.contains(e.target)) {
        currencyBtn.classList.remove('open');
        currencyDropdown.classList.remove('open');
      }
    });
  }

  // Language toggle
  const langBtn = document.getElementById('langToggle');
  if (langBtn) {
    langBtn.addEventListener('click', () => {
      const current = document.documentElement.lang;
      setLang(current === 'ar' ? 'en' : 'ar');
    });
  }

  // Mobile lang toggle
  const mobileLangBtn = document.getElementById('mobileLangToggle');
  if (mobileLangBtn) {
    mobileLangBtn.addEventListener('click', () => {
      const current = document.documentElement.lang;
      setLang(current === 'ar' ? 'en' : 'ar');
    });
  }
}

/* ============================================================
   MOBILE BOTTOM NAV
   ============================================================ */
function initBottomNav() {
  const items = document.querySelectorAll('.bottom-nav-item[data-page]');
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';

  items.forEach(item => {
    const page = item.dataset.page;
    if (currentPage === page || (currentPage === '' && page === 'index.html')) {
      item.classList.add('active');
    }
    item.addEventListener('click', () => {
      if (item.tagName !== 'A') {
        window.location.href = page;
      }
    });
  });
}

/* ============================================================
   AIRPORT SEARCH INPUTS INIT
   ============================================================ */
function initAirportSearch() {
  const fromInput = document.getElementById('fromInput');
  const toInput = document.getElementById('toInput');

  if (fromInput) {
    createAirportDropdown(fromInput, null, (airport) => {
      state.fromAirport = airport;
      updateAirportDisplay('fromDisplay', airport);
    });
  }

  if (toInput) {
    createAirportDropdown(toInput, null, (airport) => {
      state.toAirport = airport;
      updateAirportDisplay('toDisplay', airport);
    });
  }
}

/* ============================================================
   FLIGHT SEARCH FORM
   ============================================================ */
function initFlightSearch() {
  const form = document.getElementById('flightSearchForm');
  if (!form) return;

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const lang = document.documentElement.lang === 'en' ? 'en' : 'ar';

    // Validation
    if (!state.fromAirport) {
      showToast(lang === 'ar' ? 'يرجى اختيار مطار المغادرة' : 'Please select departure airport', 'error');
      return;
    }
    if (!state.toAirport) {
      showToast(lang === 'ar' ? 'يرجى اختيار مطار الوصول' : 'Please select destination airport', 'error');
      return;
    }
    if (!state.departureDate) {
      showToast(lang === 'ar' ? 'يرجى اختيار تاريخ المغادرة' : 'Please select departure date', 'error');
      return;
    }
    if (state.tripType === 'roundtrip' && !state.returnDate) {
      showToast(lang === 'ar' ? 'يرجى اختيار تاريخ العودة' : 'Please select return date', 'error');
      return;
    }

    // Build query params
    const params = new URLSearchParams({
      from: state.fromAirport.code,
      to: state.toAirport.code,
      departure: formatDateParam(state.departureDate),
      type: state.tripType,
      adults: state.passengers.adults,
      children: state.passengers.children,
      infants: state.passengers.infants,
      class: state.flightClass,
    });
    if (state.returnDate) params.set('return', formatDateParam(state.returnDate));

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
      const txt = submitBtn.querySelector('.btn-text');
      const sp = submitBtn.querySelector('.spinner');
      submitBtn.disabled = true;
      if (txt) txt.style.display = 'none';
      if (sp) sp.style.display = 'inline-block';
    }

    setTimeout(() => {
      window.location.href = `flights.html?${params.toString()}`;
    }, 600);
  });
}

/* ============================================================
   HOTEL SEARCH FORM
   ============================================================ */
function initHotelSearch() {
  const form = document.getElementById('hotelSearchForm');
  if (!form) return;

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const lang = document.documentElement.lang === 'en' ? 'en' : 'ar';
    const destInput = document.getElementById('hotelDestinationInput');
    if (!destInput || !destInput.value.trim()) {
      showToast(lang === 'ar' ? 'يرجى إدخال الوجهة' : 'Please enter a destination', 'error');
      return;
    }
    if (!state.checkinDate) {
      showToast(lang === 'ar' ? 'يرجى اختيار تاريخ الوصول' : 'Please select check-in date', 'error');
      return;
    }
    if (!state.checkoutDate) {
      showToast(lang === 'ar' ? 'يرجى اختيار تاريخ المغادرة' : 'Please select check-out date', 'error');
      return;
    }

    const params = new URLSearchParams({
      destination: destInput.value.trim(),
      checkin: formatDateParam(state.checkinDate),
      checkout: formatDateParam(state.checkoutDate),
      rooms: state.rooms,
      adults: state.hotelAdults,
      children: state.hotelChildren,
    });

    window.location.href = `hotels.html?${params.toString()}`;
  });
}

/* ============================================================
   UTILITIES
   ============================================================ */
function formatDateParam(date) {
  if (!date) return '';
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

function showToast(message, type = 'success') {
  const container = document.getElementById('toastContainer') || createToastContainer();
  const toast = document.createElement('div');
  toast.className = `toast${type === 'error' ? ' error' : ''}`;
  toast.textContent = message;
  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(-20px)';
    toast.style.transition = 'all 0.3s ease';
    setTimeout(() => toast.remove(), 300);
  }, 3500);
}

function createToastContainer() {
  const c = document.createElement('div');
  c.className = 'toast-container';
  c.id = 'toastContainer';
  document.body.appendChild(c);
  return c;
}

/* ============================================================
   CONTACT FORM
   ============================================================ */
function initContactForm() {
  const form = document.getElementById('contactForm');
  if (!form) return;
  const successMsg = document.getElementById('contactSuccess');
  const errorMsg = document.getElementById('contactError');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    const btnTxt = btn && btn.querySelector('.btn-text');
    const btnSp = btn && btn.querySelector('.spinner');
    if (btn) btn.disabled = true;
    if (btnTxt) btnTxt.style.display = 'none';
    if (btnSp) btnSp.style.display = 'inline-block';
    if (successMsg) successMsg.style.display = 'none';
    if (errorMsg) errorMsg.style.display = 'none';

    const data = {
      name: form.querySelector('#contactName')?.value || '',
      email: form.querySelector('#contactEmail')?.value || '',
      phone: form.querySelector('#contactPhone')?.value || '',
      subject: form.querySelector('#contactSubject')?.value || '',
      message: form.querySelector('#contactMessage')?.value || '',
    };

    try {
      const res = await fetch('/api/contact', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      });
      if (!res.ok) throw new Error('Server error');
      if (successMsg) successMsg.style.display = 'flex';
      form.reset();
    } catch (err) {
      if (errorMsg) errorMsg.style.display = 'flex';
    } finally {
      if (btn) btn.disabled = false;
      if (btnTxt) btnTxt.style.display = 'inline';
      if (btnSp) btnSp.style.display = 'none';
    }
  });
}

/* ============================================================
   DOMContentLoaded — Bootstrap
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  initNavbar();
  initBottomNav();
  initSearchTabs();
  initTripType();
  initAirportSearch();
  initPassengerCounter();
  initRoomsCounter();
  initDatePickers();
  initSwapButton();
  initFlightSearch();
  initHotelSearch();
  initContactForm();

  // Highlight current nav link
  const currentPath = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-link[href]').forEach(link => {
    const href = link.getAttribute('href');
    if (href === currentPath || (currentPath === '' && href === 'index.html')) {
      link.classList.add('active');
    }
  });
});
