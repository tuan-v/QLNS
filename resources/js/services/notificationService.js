import axios from "axios";
const API_BASE = "/api/v1/notifications";
export default {
    list(page = 1) {
        return axios.get(`${API_BASE}`, { params: { page } });
    },
    // Toàn bộ thông báo trong công ty (gate notification.view_all) — filters:
    // { page, type, search }.
    listAll(filters = {}) {
        return axios.get(`${API_BASE}/all`, { params: filters });
    },
    markRead(id) {
        return axios.patch(`${API_BASE}/${id}/read`);
    },
    markAllRead() {
        return axios.patch(`${API_BASE}/read-all`);
    },
};
