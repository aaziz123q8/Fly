// Hotel search and booking logic

const Hotels = {
    searchParams: {},

    async search(params) {
        Hotels.searchParams = params;
        return await Api.get(`/api/hotels/search?${new URLSearchParams(params)}`);
    },

    async getDetails(hotelId) {
        return await Api.get(`/api/hotels/${hotelId}`);
    },

    async getRooms(hotelId) {
        return await Api.get(`/api/hotels/${hotelId}/rooms`);
    },

    async book(bookingData) {
        return await Api.post('/api/bookings/hotels', bookingData, true);
    },

    async getMyBookings() {
        return await Api.get('/api/bookings/my-hotels', true);
    },

    formatPrice(amount, currency = 'SAR') {
        return new Intl.NumberFormat('ar-SA', { style: 'currency', currency }).format(amount);
    },

    renderStars(count) {
        return '⭐'.repeat(Math.min(count || 0, 5));
    },

    renderHotelCard(hotel) {
        return `
        <div class="hotel-card card" data-id="${hotel.id}">
            <div class="hotel-card__image">
                ${hotel.image ? `<img src="${hotel.image}" alt="${hotel.name}" loading="lazy">` : '<div class="hotel-placeholder">🏨</div>'}
                ${hotel.stars ? `<div class="hotel-stars">${Hotels.renderStars(hotel.stars)}</div>` : ''}
            </div>
            <div class="hotel-card__body">
                <h3 class="hotel-name">${hotel.name}</h3>
                <p class="hotel-location">📍 ${hotel.city || hotel.location || ''}</p>
                ${hotel.amenities ? `<div class="hotel-amenities">${(hotel.amenities.slice(0,3)).map(a => `<span class="amenity-tag">${a}</span>`).join('')}</div>` : ''}
                <div class="hotel-rating">
                    ${hotel.rating ? `<span class="rating-score">${hotel.rating}</span><span class="rating-label">/ 10</span>` : ''}
                </div>
            </div>
            <div class="hotel-card__footer">
                <div class="hotel-price">
                    <span class="price-amount">${Hotels.formatPrice(hotel.price_per_night || hotel.price)}</span>
                    <span class="price-label">/ ليلة</span>
                </div>
                <button class="btn btn-primary" onclick="Hotels.openBookingModal('${hotel.id}')">
                    احجز الآن ←
                </button>
            </div>
        </div>`;
    },

    openBookingModal(hotelId) {
        if (!Auth.isLoggedIn()) {
            showToast('يجب تسجيل الدخول أولاً للحجز', 'warning');
            setTimeout(() => window.location.href = '/login.html', 1500);
            return;
        }
        localStorage.setItem('booking_hotel_id', hotelId);
        const modal = document.getElementById('hotelBookingModal');
        if (modal) {
            modal.classList.add('active');
            document.getElementById('bookingHotelId').value = hotelId;
        }
    },

    renderSearchResults(results, container) {
        if (!container) return;
        if (!results || results.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-icon">🏨</div>
                    <h3>لا توجد فنادق متاحة</h3>
                    <p>جرب تغيير تواريخ البحث أو المدينة</p>
                </div>`;
            return;
        }
        container.innerHTML = results.map(h => Hotels.renderHotelCard(h)).join('');
    }
};
