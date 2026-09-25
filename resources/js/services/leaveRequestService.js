import axios from "axios";
const API_BASE = "/api/v1/leave-requests";

export default {
    create(payload) {
        return axios.post(API_BASE, payload);
    },
    mine() {
        return axios.get(`${API_BASE}/me`);
    },
    balancesMine() {
        return axios.get(`${API_BASE}/balances/me`);
    },
    balancesForEmployee(employeeId) {
        return axios.get(`${API_BASE}/balances/${employeeId}`);
    },
    list(params = {}) {
        return axios.get(API_BASE, { params });
    },
    decide(id, payload) {
        return axios.put(`${API_BASE}/${id}/decide`, payload);
    },
    // Duyệt/Từ chối hàng loạt (2026-09-25) — payload: { leave_request_ids, status, comment }.
    bulkDecide(payload) {
        return axios.put(`${API_BASE}/bulk-decide`, payload);
    },
};
