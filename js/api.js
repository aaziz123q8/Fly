const API_BASE = '';

const Api = {
    async request(method, endpoint, data = null, requiresAuth = false) {
        const headers = { 'Content-Type': 'application/json' };
        if (requiresAuth) {
            const token = Auth.getToken();
            if (token) headers['Authorization'] = `Bearer ${token}`;
        }
        const options = { method, headers };
        if (data) options.body = JSON.stringify(data);

        try {
            const res = await fetch(API_BASE + endpoint, options);
            const json = await res.json();
            if (!res.ok) throw { status: res.status, ...json };
            return json;
        } catch (err) {
            if (err.status) throw err;
            throw { message: 'تعذر الاتصال بالخادم. يرجى التحقق من اتصالك بالإنترنت.' };
        }
    },
    get: (endpoint, auth = false) => Api.request('GET', endpoint, null, auth),
    post: (endpoint, data, auth = false) => Api.request('POST', endpoint, data, auth),
    put: (endpoint, data, auth = false) => Api.request('PUT', endpoint, data, auth),
    patch: (endpoint, data, auth = false) => Api.request('PATCH', endpoint, data, auth),
    delete: (endpoint, auth = false) => Api.request('DELETE', endpoint, null, auth),
};
