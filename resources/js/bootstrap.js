import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.interceptors.request.use((config) => {
    const token = localStorage.getItem('access_token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

let isRefreshing = false;
let refreshSubscribers = [];

function subscribeTokenRefresh(callback) {
    refreshSubscribers.push(callback);
}

function onTokenRefreshed(newToken) {
    refreshSubscribers.forEach((callback) => callback(newToken));
    refreshSubscribers = [];
}

axios.interceptors.response.use(
    (response) => response,
    async (error) => {
        const originalRequest = error.config;
        const isLoginRequest = originalRequest.url?.includes('/auth/login');
        const isRefreshRequest = originalRequest.url?.includes('/auth/refresh');

        if (error.response?.status === 401 && !isLoginRequest && !isRefreshRequest) {
            if (isRefreshing) {
                return new Promise((resolve) => {
                    subscribeTokenRefresh((newToken) => {
                        originalRequest.headers.Authorization = `Bearer ${newToken}`;
                        resolve(axios(originalRequest));
                    });
                });
            }

            isRefreshing = true;

            try {
                const refreshToken = localStorage.getItem('refresh_token');
                const response = await axios.post('/api/v1/auth/refresh', { refresh_token: refreshToken });

                localStorage.setItem('access_token', response.data.access_token);
                localStorage.setItem('refresh_token', response.data.refresh_token);
                onTokenRefreshed(response.data.access_token);

                originalRequest.headers.Authorization = `Bearer ${response.data.access_token}`;
                return axios(originalRequest);
            } catch (refreshError) {
                localStorage.removeItem('access_token');
                localStorage.removeItem('refresh_token');
                window.location.href = '/login';
                return Promise.reject(refreshError);
            } finally {
                isRefreshing = false;
            }
        }

        if (error.response?.status === 403) {
            console.warn('Không đủ quyền truy cập.');
        }

        return Promise.reject(error);
    },
);


