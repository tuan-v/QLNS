import axios from "axios";
const API_BASE = "/api/v1/onboarding";

// Onboarding / Offboarding — xem ChecklistService (backend) cho quy tắc.
export default {
    list(params = {}) {
        return axios.get(`${API_BASE}/checklists`, { params });
    },
    mine() {
        return axios.get(`${API_BASE}/me`);
    },
    show(id) {
        return axios.get(`${API_BASE}/checklists/${id}`);
    },
    create(payload) {
        return axios.post(`${API_BASE}/checklists`, payload);
    },
    cancel(id) {
        return axios.post(`${API_BASE}/checklists/${id}/cancel`);
    },
    addItem(id, payload) {
        return axios.post(`${API_BASE}/checklists/${id}/items`, payload);
    },
    // payload: { done: boolean, note?: string }
    updateItem(itemId, payload) {
        return axios.put(`${API_BASE}/items/${itemId}`, payload);
    },
    removeItem(itemId) {
        return axios.delete(`${API_BASE}/items/${itemId}`);
    },
    templates(params = {}) {
        return axios.get(`${API_BASE}/templates`, { params });
    },
    createTemplate(payload) {
        return axios.post(`${API_BASE}/templates`, payload);
    },
    updateTemplate(id, payload) {
        return axios.put(`${API_BASE}/templates/${id}`, payload);
    },
    removeTemplate(id) {
        return axios.delete(`${API_BASE}/templates/${id}`);
    },
};
