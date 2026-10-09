import axios from "axios";
const API_BASE = "/api/v1/employees";

// Tài khoản ngân hàng nhận lương. employeeId = null -> API "của tôi" (/me/...).
const base = (employeeId) => (employeeId ? `${API_BASE}/${employeeId}/bank-accounts` : `${API_BASE}/me/bank-accounts`);

export default {
    banks() {
        return axios.get(`${API_BASE}/bank-list`);
    },
    list(employeeId) {
        return axios.get(base(employeeId));
    },
    create(employeeId, payload) {
        return axios.post(base(employeeId), payload);
    },
    update(employeeId, id, payload) {
        return axios.put(`${base(employeeId)}/${id}`, payload);
    },
    remove(employeeId, id) {
        return axios.delete(`${base(employeeId)}/${id}`);
    },
    setPrimary(employeeId, id) {
        return axios.post(`${base(employeeId)}/${id}/primary`);
    },
    // Chỉ HR: { status: "verified" | "rejected", review_note? }
    review(employeeId, id, payload) {
        return axios.post(`${base(employeeId)}/${id}/review`, payload);
    },
};
