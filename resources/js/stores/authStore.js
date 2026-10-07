import { ref, computed } from 'vue';
import { defineStore } from 'pinia';
import authService from '../services/authService';

export const useAuthStore = defineStore('auth', () => {
    const accessToken = ref(localStorage.getItem('access_token'));
    const refreshToken = ref(localStorage.getItem('refresh_token'));
    const user = ref(null);
    const permissions = ref([]);

    const isAuthenticated = computed(() => !!accessToken.value);

    function setTokens(tokens) {
        accessToken.value = tokens.access_token;
        refreshToken.value = tokens.refresh_token;
        localStorage.setItem('access_token', tokens.access_token);
        localStorage.setItem('refresh_token', tokens.refresh_token);
    }

    function clearTokens() {
        accessToken.value = null;
        refreshToken.value = null;
        user.value = null;
        permissions.value = [];
        localStorage.removeItem('access_token');
        localStorage.removeItem('refresh_token');
    }

    async function login(email, password) {
        const response = await authService.login(email, password);
        setTokens(response.data);
        await fetchMe();
    }

    async function fetchMe() {
        const response = await authService.me(accessToken.value);
        user.value = response.data;
        permissions.value = response.data.permissions;
    }

    async function refresh() {
        const response = await authService.refresh(localStorage.getItem('refresh_token'));
        setTokens(response.data);
    }

    async function logout() {
        try {
            // Đọc từ localStorage, không dùng bản sao trong store: interceptor
            // (bootstrap.js) đã xoay token mới vào localStorage, bản sao có thể là
            // token cũ đã bị thu hồi -> token thật đang dùng sẽ không được thu hồi.
            await authService.logout(
                localStorage.getItem('access_token'),
                localStorage.getItem('refresh_token'),
            );
        } finally {
            clearTokens();
        }
    }

    return {
        accessToken,
        refreshToken,
        user,
        permissions,
        isAuthenticated,
        login,
        fetchMe,
        refresh,
        logout,
    };
});
