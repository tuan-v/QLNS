import axios from "axios";
import { useLoadingStore } from "./stores/useLoadingStore";
import { isPublicPath } from "./publicPaths";
window.axios = axios;

window.axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";
axios.interceptors.request.use((config) => {
    useLoadingStore().start();
    const token = localStorage.getItem("access_token");
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

// Làm mới token an toàn khi mở NHIỀU TAB. Backend xoay vòng refresh token (mỗi
// lần làm mới thì token cũ bị thu hồi), mà các tab dùng chung localStorage: nếu
// 2 tab cùng gặp 401 và cùng gửi 1 refresh token, tab chậm hơn bị từ chối rồi xóa
// token -> đẩy TẤT CẢ tab ra đăng nhập. Nên:
//  - Mọi tab xếp hàng qua 1 khóa chung (Web Locks API), chỉ 1 tab làm mới 1 lúc.
//  - Vào được khóa thì xem token trong localStorage đã khác token request vừa
//    dùng chưa — khác nghĩa là tab khác (hoặc request khác trong tab này) vừa
//    làm mới xong -> chỉ gửi lại request bằng token mới, KHÔNG làm mới lần nữa.
//  - Trong 1 tab, các request 401 cùng lúc dùng chung 1 promise làm mới.
const REFRESH_LOCK = "qlns-token-refresh";
let refreshPromise = null;

function withRefreshLock(task) {
    if (navigator.locks?.request) {
        return navigator.locks.request(REFRESH_LOCK, task);
    }
    return task();
}

function bearerOf(config) {
    const header = config.headers?.Authorization ?? config.headers?.authorization ?? "";
    return String(header).replace(/^Bearer\s+/i, "");
}

async function refreshAccessToken(failedToken) {
    return withRefreshLock(async () => {
        const currentToken = localStorage.getItem("access_token");
        if (currentToken && currentToken !== failedToken) {
            return currentToken;
        }

        const refreshToken = localStorage.getItem("refresh_token");
        if (!refreshToken) {
            throw new Error("Không còn refresh token.");
        }

        try {
            const response = await axios.post("/api/v1/auth/refresh", {
                refresh_token: refreshToken,
            });
            localStorage.setItem("access_token", response.data.access_token);
            localStorage.setItem("refresh_token", response.data.refresh_token);
            return response.data.access_token;
        } catch (error) {
            // Phòng trình duyệt không có Web Locks: tab khác có thể vừa xoay token
            // trong lúc request này chạy -> dùng token mới đó thay vì đăng xuất.
            const latestToken = localStorage.getItem("access_token");
            if (latestToken && latestToken !== failedToken && localStorage.getItem("refresh_token") !== refreshToken) {
                return latestToken;
            }
            throw error;
        }
    });
}

function redirectToLogin() {
    localStorage.removeItem("access_token");
    localStorage.removeItem("refresh_token");
    // Trang công khai (ứng viên mở link thư mời) không bao giờ bị đẩy về đăng nhập.
    if (isPublicPath()) {
        return;
    }
    if (window.location.pathname !== "/dang-nhap") {
        window.location.href = "/dang-nhap";
    }
}

axios.interceptors.response.use(
    (response) => {
        useLoadingStore().stop();
        return response;
    },
    async (error) => {
        useLoadingStore().stop();

        const originalRequest = error.config;
        const isLoginRequest = originalRequest?.url?.includes("/auth/login");
        const isRefreshRequest = originalRequest?.url?.includes("/auth/refresh");

        if (
            error.response?.status === 401 &&
            originalRequest &&
            !isLoginRequest &&
            !isRefreshRequest &&
            !originalRequest._retried
        ) {
            originalRequest._retried = true;
            const failedToken = bearerOf(originalRequest);

            try {
                refreshPromise ??= refreshAccessToken(failedToken).finally(() => {
                    refreshPromise = null;
                });
                const newToken = await refreshPromise;

                originalRequest.headers.Authorization = `Bearer ${newToken}`;
                return axios(originalRequest);
            } catch (refreshError) {
                redirectToLogin();
                return Promise.reject(refreshError);
            }
        }

        // Đã làm mới + gửi lại mà vẫn 401 (tài khoản bị khóa, bị thu hồi phiên...).
        if (error.response?.status === 401 && originalRequest?._retried && !isRefreshRequest) {
            redirectToLogin();
            return Promise.reject(error);
        }

        if (error.response?.status === 403) {
            console.warn("Không đủ quyền truy cập.");
        }

        return Promise.reject(error);
    },
);
