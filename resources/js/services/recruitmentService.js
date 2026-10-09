import axios from "axios";
const API_BASE = "/api/v1/recruitment";

export default {
    listOpenings(params = {}) {
        return axios.get(`${API_BASE}/openings`, { params });
    },
    showOpening(id) {
        return axios.get(`${API_BASE}/openings/${id}`);
    },
    createOpening(payload) {
        return axios.post(`${API_BASE}/openings`, payload);
    },
    updateOpening(id, payload) {
        return axios.put(`${API_BASE}/openings/${id}`, payload);
    },
    closeOpening(id) {
        return axios.post(`${API_BASE}/openings/${id}/close`);
    },
    reopenOpening(id) {
        return axios.post(`${API_BASE}/openings/${id}/reopen`);
    },
    removeOpening(id) {
        return axios.delete(`${API_BASE}/openings/${id}`);
    },
    // FormData (có file CV) — để trình duyệt tự đặt Content-Type multipart.
    addCandidate(openingId, formData) {
        return axios.post(`${API_BASE}/openings/${openingId}/candidates`, formData);
    },
    // Kiểm tra trùng email/SĐT khi rời ô: { field: "email"|"phone", value }.
    checkDuplicate(openingId, params) {
        return axios.get(`${API_BASE}/openings/${openingId}/check-duplicate`, { params });
    },
    // Hàng loạt: trả { done, failed: [{ id, full_name, message }] }.
    bulkReview(payload) {
        return axios.post(`${API_BASE}/candidates/bulk-review`, payload);
    },
    bulkScheduleInterviews(payload) {
        return axios.post(`${API_BASE}/candidates/bulk-interview`, payload);
    },
    reviewCandidate(id, payload) {
        return axios.post(`${API_BASE}/candidates/${id}/review`, payload);
    },
    scheduleInterview(id, payload) {
        return axios.post(`${API_BASE}/candidates/${id}/interview`, payload);
    },
    recordResult(id, payload) {
        return axios.post(`${API_BASE}/candidates/${id}/result`, payload);
    },
    removeCandidate(id) {
        return axios.delete(`${API_BASE}/candidates/${id}`);
    },
    // Thư mời nhận việc (RecruitmentOfferService).
    createOffer(candidateId, payload) {
        return axios.post(`${API_BASE}/candidates/${candidateId}/offers`, payload);
    },
    // { status: "approved" | "rejected", review_note? } — chỉ Admin.
    reviewOffer(offerId, payload) {
        return axios.post(`${API_BASE}/offers/${offerId}/review`, payload);
    },
    withdrawOffer(offerId) {
        return axios.post(`${API_BASE}/offers/${offerId}/withdraw`);
    },
    // Trang công khai cho ứng viên (không đăng nhập).
    publicOffer(token) {
        return axios.get(`/api/v1/public/offers/${token}`);
    },
    respondOffer(token, payload) {
        return axios.post(`/api/v1/public/offers/${token}/respond`, payload);
    },
};
