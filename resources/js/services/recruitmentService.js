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
    // Lịch sử ứng tuyển của 1 người trên mọi đợt tuyển: { email?, phone?, exclude_id? }.
    candidateHistory(params) {
        return axios.get(`${API_BASE}/candidates/history`, { params });
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
    // Trang "Lịch phỏng vấn": { month: "YYYY-MM", confirmation_status?, opening_id? }
    // -> { data: [...buổi PV], summary: { total, pending, confirmed, reschedule_requested, withdrawn, completed } }.
    listInterviews(params) {
        return axios.get(`${API_BASE}/interviews`, { params });
    },
    // HR ghi nhận phản hồi ứng viên: { status: "confirmed" | "reschedule_requested" | "withdrawn", note? }.
    confirmInterview(interviewId, payload) {
        return axios.post(`${API_BASE}/interviews/${interviewId}/confirmation`, payload);
    },
    // Bộ câu hỏi phỏng vấn (Admin soạn) + gắn cho đợt tuyển.
    questionSets() {
        return axios.get(`${API_BASE}/question-sets`);
    },
    createQuestionSet(payload) {
        return axios.post(`${API_BASE}/question-sets`, payload);
    },
    updateQuestionSet(id, payload) {
        return axios.put(`${API_BASE}/question-sets/${id}`, payload);
    },
    removeQuestionSet(id) {
        return axios.delete(`${API_BASE}/question-sets/${id}`);
    },
    assignQuestionSet(openingId, questionSetId) {
        return axios.put(`${API_BASE}/openings/${openingId}/question-set`, { interview_question_set_id: questionSetId });
    },
    // Phiếu chấm phỏng vấn: { interview, question_set, scorecard, start_blocker }.
    scorecard(interviewId) {
        return axios.get(`${API_BASE}/interviews/${interviewId}/scorecard`);
    },
    startScorecard(interviewId) {
        return axios.post(`${API_BASE}/interviews/${interviewId}/scorecard`);
    },
    // payload: { items: [{ id, score (1-5|null), note }], overall_note }
    saveScorecard(scorecardId, payload) {
        return axios.put(`${API_BASE}/scorecards/${scorecardId}`, payload);
    },
    submitScorecard(scorecardId, payload) {
        return axios.post(`${API_BASE}/scorecards/${scorecardId}/submit`, payload);
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
