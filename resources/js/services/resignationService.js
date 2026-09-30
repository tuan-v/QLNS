import axios from "axios";

const API_BASE = "/api/v1/resignations";

// Đơn xin nghỉ việc (2026-09-29) — xem app/Services/ResignationService.php.
export default {
    // Nhân viên (chính mình)
    create(payload) {
        return axios.post(API_BASE, payload);
    },
    // Số ngày báo trước tối thiểu + ngày làm việc cuối sớm nhất để chỉ cần "thông báo".
    policy() {
        return axios.get(`${API_BASE}/policy`);
    },
    mine() {
        return axios.get(`${API_BASE}/me`);
    },
    cancel(id) {
        return axios.post(`${API_BASE}/${id}/cancel`);
    },
    // HR/Manager
    list(params = {}) {
        return axios.get(API_BASE, { params });
    },
    show(id) {
        return axios.get(`${API_BASE}/${id}`);
    },
    decide(id, payload) {
        return axios.put(`${API_BASE}/${id}/decide`, payload);
    },
};
