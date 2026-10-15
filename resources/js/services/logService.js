import axios from "axios";

// Nhật ký hoạt động — API /api/v1/logs (tài liệu yêu cầu mục 9 "Audit Log").
export default {
    list(params = {}) {
        return axios.get("/api/v1/logs", { params });
    },
    show(id) {
        return axios.get(`/api/v1/logs/${id}`);
    },
    filters() {
        return axios.get("/api/v1/logs/filters");
    },
};
