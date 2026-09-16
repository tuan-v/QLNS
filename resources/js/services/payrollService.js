import axios from "axios";
const API_BASE = "/api/v1/payrolls";

export default {
    list(params = {}) {
        return axios.get(API_BASE, { params });
    },
    generate(payload) {
        return axios.post(`${API_BASE}/generate`, payload);
    },
    close(id) {
        return axios.post(`${API_BASE}/${id}/close`);
    },
    markAsPaid(id) {
        return axios.post(`${API_BASE}/${id}/mark-paid`);
    },
    show(id) {
        return axios.get(`${API_BASE}/${id}`);
    },
    mine() {
        return axios.get(`${API_BASE}/me`);
    },
};
