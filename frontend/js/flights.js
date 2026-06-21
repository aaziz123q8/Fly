// Flight search and booking logic

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
        const h = Math.floor(minutes / 60);
        const m = minutes % 60;
        return `${h}س ${m}د`;
    },

    formatPrice(amount, currency = 'SAR') {
        return new Intl.NumberFormat('ar-SA', { style: 'currency', currency }).format(amount);
    },

    renderFlightCard(flight) {
        return `
        <div class="flight-card card" data-id="${flight.id}">
            <div class="flight-card__header">
                <div class="flight-airline">
                    <span class="airline-logo">${flight.airline_code || '✈'}</span>
                    <span class="airline-name">${flight.airline_name || flight.airline}</span>
                </div>
                <div class="flight-price">
                    <span class="price-amount">${Flights.formatPrice(flight.price)}</span>
                    <span class="price-label">للمسافر الواحد</span>
                </div>
            </div>
            <div class="flight-card__body">
                <div class="flight-route">
                    <div class="flight-point">
                        <div class="flight-time">${flight.departure_time || flight.departureTime}</div>
                        <div class="flight-city">${flight.origin || flight.from}</div>
                    </div>
                    <div class="flight-duration">
                        <div class="duration-line">
                            <span class="duration-dot"></span>
                            <span class="duration-bar"></span>
                            <span class="duration-plane">✈</span>
                            <span class="duration-bar"></span>
                            <span class="duration-dot"></span>
                        </div>
                        <div class="duration-text">${flight.duration ? Flights.formatDuration(flight.duration) : flight.flight_duration || '--'}</div>
                        <div class="stops-text">${flight.stops === 0 ? 'مباشر' : flight.stops + ' توقف'}</div>
                    </div>
                    <div class="flight-point">
                        <div class="flight-time">${flight.arrival_time || flight.arrivalTime}</div>
                        <div class="flight-city">${flight.destination || flight.to}</div>
                    </div>
                </div>
                <div class="flight-meta">
                    <span class="flight-class">${Flights.getClassLabel(flight.cabin_class || flight.class)}</span>
                    <span class="flight-seats">${flight.available_seats || ''} ${flight.available_seats ? 'مقعد متاح' : ''}</span>
                </div>
            </div>
            <div class="flight-card__footer">
                <button class="btn btn-primary" onclick="Flights.openBookingModal('${flight.id}')">
                    احجز الآن ←
                </button>
                <button class="btn btn-ghost" onclick="Flights.viewDetails('${flight.id}')">
                    التفاصيل
                </button>
            </div>
        </div>`;
    },

    getClassLabel(cls) {
        const labels = { economy: 'اقتصادية', business: 'أعمال', first: 'أولى', 'first-class': 'درجة أولى' };
        return labels[cls] || cls || 'اقتصادية';
    },

    openBookingModal(flightId) {
        if (!Auth.isLoggedIn()) {
            showToast('يجب تسجيل الدخول أولاً للحجز', 'warning');
            setTimeout(() => window.location.href = '/login.html', 1500);
            return;
        }
        // Store flight ID and open modal
        localStorage.setItem('booking_flight_id', flightId);
        const modal = document.getElementById('bookingModal');
        if (modal) {
            modal.classList.add('active');
            document.getElementById('bookingFlightId').value = flightId;
        }
    },

    viewDetails(flightId) {
        // Could open a details modal or navigate
        showToast('جاري تحميل التفاصيل...', 'info');
    },

    renderSearchResults(results, container) {
        if (!container) return;
        if (!results || results.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">✈️</div>
                    <h3>لا توجد رحلات متاحة</h3>
                    <p>جرب تغيير تواريخ البحث أو الوجهة</p>
                </div>`;
            return;
        }
        container.innerHTML = results.map(f => Flights.renderFlightCard(f)).join('');
    }
};
