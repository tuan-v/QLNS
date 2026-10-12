import axios from "axios";
const API_BASE = "/api/v1/holidays";

export default {
    list(year) {
        return axios.get(API_BASE, { params: { year } });
    },
    convertLunar(params) {
        return axios.get(`${API_BASE}/convert`, { params });
    },
    create(payload) {
        return axios.post(API_BASE, payload);
    },
    generate(year) {
        return axios.post(`${API_BASE}/generate`, { year });
    },
    showGroup(groupCode) {
        return axios.get(`${API_BASE}/groups/${groupCode}`);
    },
    confirmGroup(groupCode, confirmed = true) {
        return axios.post(`${API_BASE}/groups/${groupCode}/confirm`, { confirmed });
    },
    setInternPaid(groupCode, internPaid) {
        return axios.post(`${API_BASE}/groups/${groupCode}/intern-paid`, { intern_paid: internPaid });
    },
    applyChanges(groupCode, payload) {
        return axios.post(`${API_BASE}/groups/${groupCode}/apply`, payload);
    },
    addDay(groupCode, payload) {
        return axios.post(`${API_BASE}/groups/${groupCode}/days`, payload);
    },
    addSwap(groupCode, payload) {
        return axios.post(`${API_BASE}/groups/${groupCode}/swaps`, payload);
    },
    removeDay(id) {
        return axios.delete(`${API_BASE}/days/${id}`);
    },
    removeGroup(groupCode) {
        return axios.delete(`${API_BASE}/groups/${groupCode}`);
    },
    notifyGroup(groupCode) {
        return axios.post(`${API_BASE}/groups/${groupCode}/notify`);
    },
    // Văn bản thông báo nghỉ lễ (mẫu Word) của một dịp.
    announcement(groupCode) {
        return axios.get(`${API_BASE}/groups/${groupCode}/announcement`);
    },
    saveAnnouncement(groupCode, payload) {
        return axios.put(`${API_BASE}/groups/${groupCode}/announcement`, payload);
    },
    // Chốt nội dung để in trình ký (khóa sửa, cấp số văn bản).
    prepareSigning(groupCode, payload) {
        return axios.post(`${API_BASE}/groups/${groupCode}/announcement/prepare-signing`, payload);
    },
    // Bản scan PDF đã ký + đóng dấu.
    uploadSigned(groupCode, file) {
        const data = new FormData();
        data.append("signed_file", file);
        return axios.post(`${API_BASE}/groups/${groupCode}/announcement/signed`, data);
    },
    signedUrl(groupCode) {
        return `${API_BASE}/groups/${groupCode}/announcement/signed`;
    },
    publishAnnouncement(groupCode) {
        return axios.post(`${API_BASE}/groups/${groupCode}/announcement/publish`);
    },
    // Về nháp để sửa (bỏ bản đã ký — phải ký lại).
    reopenAnnouncement(groupCode) {
        return axios.post(`${API_BASE}/groups/${groupCode}/announcement/reopen`);
    },
    // File .docx của nội dung đang soạn (chưa lưu) — để xem trước / tải về.
    previewAnnouncement(groupCode, payload) {
        return axios.post(`${API_BASE}/groups/${groupCode}/announcement/preview`, payload, { responseType: "arraybuffer" });
    },
    // File .docx đã lưu (chỉ người quản lý) — để in trình ký.
    announcementDocx(groupCode) {
        return axios.get(`${API_BASE}/groups/${groupCode}/announcement/docx`, { responseType: "arraybuffer" });
    },
    rules() {
        return axios.get(`${API_BASE}/rules`);
    },
    createRule(payload) {
        return axios.post(`${API_BASE}/rules`, payload);
    },
    updateRule(id, payload) {
        return axios.put(`${API_BASE}/rules/${id}`, payload);
    },
    removeRule(id) {
        return axios.delete(`${API_BASE}/rules/${id}`);
    },
};
