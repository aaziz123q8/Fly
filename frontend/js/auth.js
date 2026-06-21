const Auth = {
    getToken: () => localStorage.getItem('flymasar_token'),
    getUser: () => JSON.parse(localStorage.getItem('flymasar_user') || 'null'),
    setSession: (token, user) => {
        localStorage.setItem('flymasar_token', token);
        localStorage.setItem('flymasar_user', JSON.stringify(user));
    },
    clear: () => {
        localStorage.removeItem('flymasar_token');
        localStorage.removeItem('flymasar_user');
    },
    isLoggedIn: () => !!localStorage.getItem('flymasar_token'),
    requireAuth: () => {
        if (!Auth.isLoggedIn()) {
            window.location.href = '/login.html';
            return false;
        }
        return true;
    },
    requireGuest: () => {
        if (Auth.isLoggedIn()) {
            window.location.href = '/dashboard.html';
            return false;
        }
        return true;
    },
    requireAdmin: () => {
        const user = Auth.getUser();
        if (!Auth.isLoggedIn() || !user || user.role !== 'admin') {
            window.location.href = '/admin/login.html';
            return false;
        }
        return true;
    },
    updateNavbar: () => {
        const authButtons = document.getElementById('authButtons');
        const userMenu = document.getElementById('userMenu');
        const userNameEl = document.getElementById('userName');

        if (Auth.isLoggedIn()) {
            const user = Auth.getUser();
            if (authButtons) authButtons.style.display = 'none';
            if (userMenu) userMenu.style.display = 'flex';
            if (userNameEl && user) userNameEl.textContent = user.first_name || user.email;
        } else {
            if (authButtons) authButtons.style.display = 'flex';
            if (userMenu) userMenu.style.display = 'none';
        }
    },
    logout: () => {
        Auth.clear();
        window.location.href = '/index.html';
    }
};
