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
            window.location.href = 'login.html';
            return false;
        }
        return true;
    },
    requireGuest: () => {
        if (Auth.isLoggedIn()) {
            window.location.href = 'dashboard.html';
            return false;
        }
        return true;
    },
    requireAdmin: () => {
        const user = Auth.getUser();
        if (!Auth.isLoggedIn() || !user || user.role !== 'admin') {
            window.location.href = 'admin/login.html';
            return false;
        }
        return true;
    },
    logout: () => {
        Auth.clear();
        window.location.href = 'index.html';
    },
    checkTokenExpiry: () => {
        const token = Auth.getToken();
        if (!token) return;
        try {
            // JWT tokens have 3 parts: header.payload.signature
            const parts = token.split('.');
            if (parts.length !== 3) return;
            const payload = JSON.parse(atob(parts[1]));
            if (payload.exp && Date.now() / 1000 > payload.exp) {
                Auth.clear();
                // Only redirect if on a protected page
                const protectedPages = ['dashboard.html', 'travelers.html'];
                const path = window.location.pathname;
                if (protectedPages.some(p => path.endsWith(p))) {
                    window.location.href = 'login.html?expired=1';
                }
            }
        } catch(e) { /* non-JWT token, ignore */ }
    },
};
