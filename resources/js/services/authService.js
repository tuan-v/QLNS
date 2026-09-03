import axios from 'axios';

const API_BASE = '/api/v1/auth';

export default {
    login(email, password) {
        return axios.post(`${API_BASE}/login`, { email, password });
    },
    refresh(refreshToken) {
        return axios.post(`${API_BASE}/refresh`, { refresh_token: refreshToken });
    },
    me(accessToken) {
        return axios.get(`${API_BASE}/me`, {
            headers: { Authorization: `Bearer ${accessToken}` },
        });
    },
    logout(accessToken, refreshToken) {
        return axios.post(
            `${API_BASE}/logout`,
            { refresh_token: refreshToken },
            { headers: { Authorization: `Bearer ${accessToken}` } },
        );
    },
};
