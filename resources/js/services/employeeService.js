import axios from "axios";
const API_BASE = "/api/v1/employees";

export default {
    list(params = {}) {
        return axios.get(API_BASE, { params });
    },
    stats() {
        return axios.get(`${API_BASE}/stats`);
    },
    me() {
        return axios.get(`${API_BASE}/me`);
    },
    get(id) {
        return axios.get(`${API_BASE}/${id}`);
    },
    contracts(id) {
        return axios.get(`${API_BASE}/${id}/contracts`);
    },
    documents(id) {
        return axios.get(`${API_BASE}/${id}/documents`);
    },
    uploadDocument(id, formData) {
        // Không tự set Content-Type: trình duyệt tự thêm "multipart/form-data;
        // boundary=..." đúng chuẩn khi thấy body là FormData, tự set tay dễ
        // thiếu boundary làm backend không parse được file.
        return axios.post(`${API_BASE}/${id}/documents`, formData);
    },
    deleteDocument(id, documentId) {
        return axios.delete(`${API_BASE}/${id}/documents/${documentId}`);
    },
    transfers(id) {
        return axios.get(`${API_BASE}/${id}/transfers`);
    },
    createTransfer(id, formData) {
        return axios.post(`${API_BASE}/${id}/transfers`, formData);
    },
    create(employee) {
        return axios.post(API_BASE, employee);
    },
    update(id, employee) {
        return axios.put(`${API_BASE}/${id}`, employee);
    },
    createAccount(id, payload) {
        return axios.post(`${API_BASE}/${id}/account`, payload);
    },
    shiftAssignments(id) {
        return axios.get(`${API_BASE}/${id}/shift-assignments`);
    },
    createShiftAssignment(id, payload) {
        return axios.post(`${API_BASE}/${id}/shift-assignments`, payload);
    },
    updateShiftAssignment(id, assignmentId, payload) {
        return axios.put(`${API_BASE}/${id}/shift-assignments/${assignmentId}`, payload);
    },
    deleteShiftAssignment(id, assignmentId) {
        return axios.delete(`${API_BASE}/${id}/shift-assignments/${assignmentId}`);
    },
};
