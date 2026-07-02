// Admin panel logic

const Admin = {
    currentPage: 1,
    pageSize: 20,

    requireAdmin() {
        const user = Auth.getUser();
        if (!Auth.isLoggedIn() || !user || (user.role !== 'admin' && user.role !== 'super_admin')) {
            window.location.href = '/admin/login.html';
            return false;
        }
        return true;
    },

    async getStats() {
        return await Api.get('/api/admin/dashboard', true);
    },

    // Travelers
    async getTravelers(page = 1, search = '') {
        return await Api.get(`/api/admin/travelers?page=${page}&limit=${Admin.pageSize}&search=${encodeURIComponent(search)}`, true);
    },

    async updateTravelerStatus(userId, isActive) {
        return await Api.put(`/api/admin/travelers/${userId}`, { is_active: isActive }, true);
    },

    async deleteTraveler(userId) {
        return await Api.delete(`/api/admin/users/${userId}`, true);
    },

    // Bookings
    async getBookings(page = 1, type = '', status = '') {
        return await Api.get(`/api/admin/bookings?page=${page}&limit=${Admin.pageSize}&type=${type}&status=${status}`, true);
    },

    async updateBookingStatus(bookingId, status) {
        return await Api.patch(`/api/admin/bookings/${bookingId}/status`, { status }, true);
    },

    // Coupons
    async getCoupons() {
        return await Api.get('/api/admin/coupons', true);
    },

    async createCoupon(data) {
        return await Api.post('/api/admin/coupons', data, true);
    },

    async updateCoupon(id, data) {
        return await Api.put(`/api/admin/coupons/${id}`, data, true);
    },

    async deleteCoupon(id) {
        return await Api.delete(`/api/admin/coupons/${id}`, true);
    },

    renderStatusBadge(status) {
        const map = {
            active: ['نشط', 'success'],
            inactive: ['غير نشط', 'danger'],
            pending: ['معلق', 'warning'],
            confirmed: ['مؤكد', 'success'],
            cancelled: ['ملغي', 'danger'],
            completed: ['مكتمل', 'info'],
        };
        const [label, type] = map[status] || [status, 'info'];
        return `<span class="badge badge-${type}">${label}</span>`;
    },

    renderPagination(container, total, current, onPageChange) {
        const pages = Math.ceil(total / Admin.pageSize);
        if (pages <= 1) { container.innerHTML = ''; return; }

        let html = '<div class="pagination">';
        if (current > 1) html += `<button class="btn btn-ghost btn-sm" onclick="${onPageChange}(${current - 1})">السابق</button>`;
        for (let i = Math.max(1, current - 2); i <= Math.min(pages, current + 2); i++) {
            html += `<button class="btn btn-sm ${i === current ? 'btn-primary' : 'btn-ghost'}" onclick="${onPageChange}(${i})">${i}</button>`;
        }
        if (current < pages) html += `<button class="btn btn-ghost btn-sm" onclick="${onPageChange}(${current + 1})">التالي</button>`;
        html += '</div>';
        container.innerHTML = html;
    },

    updateSidebarActive() {
        const path = window.location.pathname;
        document.querySelectorAll('.sidebar-nav a').forEach(a => {
            a.classList.toggle('active', a.getAttribute('href') && path.endsWith(a.getAttribute('href')));
        });
    },

    async loadAdminName() {
        const user = Auth.getUser();
        const el = document.getElementById('adminName');
        if (el && user) el.textContent = user.first_name || user.email;
    }
};

// Inline SVG icon helpers (backed by AdminIcon from admin-icons.js).
Admin.icon = function (name, size, cls) {
    return (typeof AdminIcon === 'function') ? AdminIcon(name, size, cls) : '';
};
// Replace any element carrying data-ic="name" with the matching SVG icon.
Admin.hydrateIcons = function (root) {
    (root || document).querySelectorAll('[data-ic]').forEach(function (el) {
        if (el.dataset.icDone) return;
        var s = parseInt(el.getAttribute('data-ic-size'), 10) || 18;
        el.innerHTML = Admin.icon(el.getAttribute('data-ic'), s);
        el.dataset.icDone = '1';
    });
};
document.addEventListener('DOMContentLoaded', function () { Admin.hydrateIcons(); });

// Global toast function used across pages
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<span>${message}</span>`;
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function showLoading(container, message = 'جاري التحميل...') {
    if (container) container.innerHTML = `<div class="loading-state"><div class="spinner"></div><p>${message}</p></div>`;
}

function confirmDialog(message) {
    return window.confirm(message);
}
