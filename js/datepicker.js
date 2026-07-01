window.DatePickerInstances = window.DatePickerInstances || [];

class DatePicker {
  constructor(triggerEl, options = {}) {
    this.trigger = triggerEl;
    this.mode = options.mode || 'single'; // 'single' or 'range'
    this.minDate = options.minDate || new Date();
    this.minDate.setHours(0,0,0,0);
    this.onSelect = options.onSelect || function(){};
    this.lang = options.lang || (window.currentLang || 'ar');
    this.startDate = null;
    this.endDate = null;
    this.selecting = false;
    this.viewDate = new Date();
    this.viewDate2 = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() + 1, 1);
    this._build();
    window.DatePickerInstances.push(this);
  }

  _build() {
    this.popup = document.createElement('div');
    this.popup.className = 'dp-popup';
    this.popup.innerHTML = `
      <div class="dp-wrap">
        <div class="dp-month-block" id="dpM1_${this._id()}"></div>
        ${this.mode === 'range' ? `<div class="dp-month-block" id="dpM2_${this._id()}"></div>` : ''}
      </div>
      ${this.mode === 'range' ? '<div class="dp-nights"></div>' : ''}
    `;
    document.body.appendChild(this.popup);
    this._render();

    this.trigger.addEventListener('click', (e) => { e.stopPropagation(); this.toggle(); });
    document.addEventListener('click', (e) => {
      if (!this.popup.contains(e.target) && e.target !== this.trigger) this.hide();
    });
  }

  _id() {
    if (!this.__id) this.__id = Math.random().toString(36).slice(2);
    return this.__id;
  }

  setLang(lang) {
    this.lang = lang;
    this._render();
  }

  setMinDate(date) {
    this.minDate = new Date(date);
    this.minDate.setHours(0, 0, 0, 0);
    this._render();
  }

  _months() { return (window.I18N && window.I18N[this.lang] && window.I18N[this.lang].months) || ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; }
  _days() { return (window.I18N && window.I18N[this.lang] && window.I18N[this.lang].days) || ['Su','Mo','Tu','We','Th','Fr','Sa']; }

  _render() {
    const m1El = this.popup.querySelector('[id^="dpM1_"]');
    if (m1El) m1El.innerHTML = this._renderMonth(this.viewDate, true);
    if (this.mode === 'range') {
      const m2El = this.popup.querySelector('[id^="dpM2_"]');
      if (m2El) m2El.innerHTML = this._renderMonth(this.viewDate2, false);
      this._updateNights();
    }
    this._bindDays();
    this._bindNav();
  }

  _renderMonth(date, isFirst) {
    const months = this._months();
    const days = this._days();
    const y = date.getFullYear(), m = date.getMonth();
    const firstDay = new Date(y, m, 1).getDay();
    const daysInMonth = new Date(y, m + 1, 0).getDate();
    const today = new Date(); today.setHours(0,0,0,0);

    let html = `<div class="dp-month-header">`;
    if (isFirst) html += `<button class="dp-nav dp-prev" data-dir="-1">&#8249;</button>`;
    else html += `<span></span>`;
    html += `<span class="dp-month-title">${months[m]} ${y}</span>`;
    if (!isFirst || this.mode === 'single') html += `<button class="dp-nav dp-next" data-dir="1" data-month="${isFirst?'1':'2'}">&#8250;</button>`;
    else html += `<span></span>`;
    html += `</div><div class="dp-grid">`;

    days.forEach(d => { html += `<div class="dp-day-name">${d}</div>`; });
    for (let i = 0; i < firstDay; i++) html += `<div></div>`;

    for (let d = 1; d <= daysInMonth; d++) {
      const cur = new Date(y, m, d);
      cur.setHours(0,0,0,0);
      let cls = 'dp-day';
      const isPast = cur < this.minDate;
      if (isPast) cls += ' dp-disabled';
      if (this._isSame(cur, today)) cls += ' dp-today';
      if (this._isSame(cur, this.startDate)) cls += ' dp-selected dp-start';
      if (this.mode === 'range' && this._isSame(cur, this.endDate)) cls += ' dp-selected dp-end';
      if (this.mode === 'range' && this.startDate && this.endDate && cur > this.startDate && cur < this.endDate) cls += ' dp-in-range';
      html += `<div class="${cls}" data-date="${cur.getTime()}">${d}</div>`;
    }
    html += '</div>';
    return html;
  }

  _bindDays() {
    this.popup.querySelectorAll('.dp-day:not(.dp-disabled)').forEach(el => {
      el.addEventListener('click', (e) => {
        e.stopPropagation();
        const ts = parseInt(el.dataset.date);
        const d = new Date(ts);
        if (this.mode === 'single') {
          this.startDate = d;
          this.trigger.value = this._fmt(d);
          // Store ISO date for programmatic reading
          // Use local date parts to avoid UTC timezone shift (e.g. UTC+3 → midnight = prev day in UTC)
          const _y = d.getFullYear();
          const _m = String(d.getMonth() + 1).padStart(2, '0');
          const _d = String(d.getDate()).padStart(2, '0');
          this.trigger.dataset.isoDate = `${_y}-${_m}-${_d}`;
          this.trigger.classList.add('filled');
          this.onSelect(d, null, this);
          this._render();
          this.hide();
        } else {
          if (!this.selecting || (this.startDate && d < this.startDate)) {
            this.startDate = d; this.endDate = null; this.selecting = true;
            this._render();
          } else {
            this.endDate = d; this.selecting = false;
            this.onSelect(this.startDate, this.endDate, this);
            this._render();
            // Auto-close once BOTH dates are chosen (brief delay lets the user
            // see the selected range highlight before it closes).
            setTimeout(() => this.hide(), 280);
          }
        }
      });
    });
  }

  _bindNav() {
    this.popup.querySelectorAll('.dp-nav').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const dir = parseInt(btn.dataset.dir);
        const isSecond = btn.dataset.month === '2';
        if (this.mode === 'range' && isSecond) {
          this.viewDate2 = new Date(this.viewDate2.getFullYear(), this.viewDate2.getMonth() + dir, 1);
        } else {
          this.viewDate = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() + dir, 1);
          if (this.mode === 'range') {
            this.viewDate2 = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() + 1, 1);
          }
        }
        this._render();
      });
    });
  }

  _updateNights() {
    const el = this.popup.querySelector('.dp-nights');
    if (!el) return;
    if (this.startDate && this.endDate) {
      const n = Math.round((this.endDate - this.startDate) / 86400000);
      const lang = this.lang;
      const nightWord = (window.I18N && window.I18N[lang] && window.I18N[lang].nights) || 'night';
      el.textContent = n + ' ' + nightWord;
      el.style.display = 'block';
    } else {
      el.style.display = 'none';
    }
  }

  _isSame(a, b) {
    if (!a || !b) return false;
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  }

  _fmt(d) {
    if (!d) return '';
    const months = this._months();
    return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
  }

  show() {
    // Only one date picker open at a time (prevents overlapping popups).
    (window.DatePickerInstances || []).forEach((dp) => { if (dp !== this) dp.hide(); });
    this.popup.style.display = 'block';

    // Phones: show as a centered modal with a dim backdrop, full width, one month per row.
    if (window.innerWidth <= 768) {
      if (!this._backdrop) {
        const b = document.createElement('div');
        b.className = 'dp-backdrop';
        b.style.cssText = 'position:fixed;inset:0;background:rgba(20,8,45,.5);z-index:1999';
        document.body.appendChild(b);
        this._backdrop = b;
      }
      this._backdrop.style.display = 'block';
      this._backdrop.onclick = () => this.hide();
      this.popup.style.left = '12px';
      this.popup.style.right = '12px';
      this.popup.style.width = 'auto';
      this.popup.style.maxWidth = 'none';
      this.popup.querySelectorAll('.dp-month-block').forEach((b) => { b.style.minWidth = '0'; b.style.width = '100%'; });
      const wrap = this.popup.querySelector('.dp-wrap'); if (wrap) wrap.style.flexDirection = 'column';
      this.popup.style.maxHeight = (window.innerHeight - 40) + 'px';
      this.popup.style.overflowY = 'auto';
      const popH = this.popup.offsetHeight || 360;
      this.popup.style.top = Math.max(20, (window.innerHeight - popH) / 2) + 'px';
      return;
    }

    const rect = this.trigger.getBoundingClientRect();
    const popW = this.popup.offsetWidth || 300;
    const popH = this.popup.offsetHeight || 320;
    let left = rect.left;
    let top = rect.bottom + 4;
    if (left + popW > window.innerWidth - 10) left = window.innerWidth - popW - 10;
    if (left < 10) left = 10;
    if (top + popH > window.innerHeight - 10) top = rect.top - popH - 4;
    if (top < 10) top = 10;
    this.popup.style.top = top + 'px';
    this.popup.style.left = left + 'px';
    this.popup.style.right = 'auto';
  }

  hide() {
    this.popup.style.display = 'none';
    if (this._backdrop) this._backdrop.style.display = 'none';
  }

  toggle() {
    if (this.popup.style.display === 'block') this.hide(); else this.show();
  }
}
