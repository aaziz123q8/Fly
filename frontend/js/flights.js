const Flights = {
    searchParams: {},

    async search(params) {
        Flights.searchParams = params;
        return await Api.get(`/api/flights/search?${new URLSearchParams(params)}`);
    },

    async getDetails(flightId) {
        return await Api.get(`/api/flights/${flightId}`);
    },

    async book(bookingData) {
        return await Api.post('/api/bookings/flights', bookingData, true);
    },

    async getMyBookings() {
        return await Api.get('/api/bookings/my-flights', true);
    },

    formatDuration(minutes) {
        if (!minutes) return '--';
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        return h + 'س ' + (m ? m + 'د' : '');
    },

    getClassLabel(cls) {
        const labels = { economy: 'اقتصادية', business: 'أعمال', first: 'أولى', 'first-class': 'درجة أولى' };
        return labels[cls] || cls || 'اقتصادية';
    },

    renderFlightCard(flight) {
        const stops = flight.stops === 0 || flight.stops === '0' ? 0 : (parseInt(flight.stops) || 0);
        const stopsLabel = stops === 0 ? '<span style="color:#16a34a;font-weight:600;font-size:0.78rem">مباشر</span>'
            : '<span style="color:var(--text-muted);font-size:0.78rem">' + stops + ' توقف</span>';
        const priceGBP = parseFloat(flight.price) || 0;
        const priceDisplay = typeof formatPrice === 'function' ? formatPrice(priceGBP) : '£ ' + priceGBP.toFixed(0);
        const dep = flight.departure_time || flight.departureTime || '--:--';
        const arr = flight.arrival_time || flight.arrivalTime || '--:--';
        const from = flight.origin || flight.from || flight.origin_code || '---';
        const to = flight.destination || flight.to || flight.destination_code || '---';
        const airline = flight.airline_name || flight.airline || 'شركة طيران';
        const airlineCode = flight.airline_code || flight.iata_code || '';
        const duration = Flights.formatDuration(flight.duration || flight.flight_duration);

        return `<div class="flight-card" data-id="${flight.id || ''}">
  <div class="fc-airline">
    ${airlineCode
        ? `<img class="airline-logo" src="https://pics.avs.io/80/80/${airlineCode}.png" alt="${airline}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">`
        : ''}
    <div style="width:48px;height:48px;background:var(--primary);color:#fff;border-radius:8px;display:${airlineCode?'none':'flex'};align-items:center;justify-content:center;font-size:1.2rem;font-weight:800">${airlineCode || '✈'}</div>
    <div class="airline-name">${airline}</div>
  </div>
  <div class="fc-route">
    <div class="route-point">
      <div class="route-time">${dep}</div>
      <div class="route-code">${from}</div>
    </div>
    <div class="route-mid">
      <div class="route-duration">${duration}</div>
      <div class="route-bar"></div>
      ${stopsLabel}
    </div>
    <div class="route-point">
      <div class="route-time">${arr}</div>
      <div class="route-code">${to}</div>
    </div>
  </div>
  <div class="fc-price">
    <div class="price-amount" data-price-gbp="${priceGBP}">${priceDisplay}</div>
    <div class="price-per">للمسافر الواحد</div>
    <button class="btn btn-primary btn-sm" onclick="Flights.openBooking('${flight.id || ''}')">احجز الآن</button>
  </div>
</div>`;
    },

    openBooking(flightId) {
        if (!Auth.isLoggedIn()) {
            if (typeof showToast === 'function') showToast('يجب تسجيل الدخول أولاً للحجز', 'warning');
            setTimeout(() => window.location.href = 'login.html', 1500);
            return;
        }
        localStorage.setItem('booking_flight_id', flightId);
        window.location.href = 'booking.html?flight=' + flightId;
    },

    openBookingModal(flightId) {
        Flights.openBooking(flightId);
    },

    renderSearchResults(results, container) {
        if (!container) return;
        if (!results || results.length === 0) {
            container.innerHTML = `<div style="text-align:center;padding:60px 20px">
  <div style="font-size:3rem;margin-bottom:16px">✈️</div>
  <h3 style="color:var(--primary);margin-bottom:8px">لا توجد رحلات متاحة</h3>
  <p style="color:var(--text-muted)">جرب تغيير تواريخ البحث أو الوجهة</p>
</div>`;
            return;
        }
        container.innerHTML = results.map(f => Flights.renderFlightCard(f)).join('');
    }
};
