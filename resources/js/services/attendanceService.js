import axios from "axios";
const API_BASE = "/api/v1/attendances";

export default {
    checkIn(payload) {
        return axios.post(`${API_BASE}/check-in`, payload);
    },
    checkOut(payload) {
        return axios.post(`${API_BASE}/check-out`, payload);
    },
    today() {
        return axios.get(`${API_BASE}/today`);
    },
    myHistory() {
        return axios.get(`${API_BASE}/me`);
    },
    historyMine(params = {}) {
        return axios.get(`${API_BASE}/history/me`, { params });
    },
    history(employeeId, params = {}) {
        return axios.get(`${API_BASE}/history/${employeeId}`, { params });
    },
    requestAdjustment(payload) {
        return axios.post(`${API_BASE}/adjustments`, payload);
    },
    myAdjustments() {
        return axios.get(`${API_BASE}/adjustments/me`);
    },
    listAdjustments(params = {}) {
        return axios.get(`${API_BASE}/adjustments`, { params });
    },
    decideAdjustment(id, payload) {
        return axios.put(`${API_BASE}/adjustments/${id}`, payload);
    },
};
