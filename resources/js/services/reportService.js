import axios from "axios";

// Module Báo cáo (Kế hoạch Ngày 51-58) — xem routes/api/v1/reports.php.
const API_BASE = "/api/v1/reports";

export default {
    filters() {
        return axios.get(`${API_BASE}/filters`);
    },
    workforce(params) {
        return axios.get(`${API_BASE}/workforce`, { params });
    },
    attendance(params) {
        return axios.get(`${API_BASE}/attendance`, { params });
    },
    payrollCost(params) {
        return axios.get(`${API_BASE}/payroll-cost`, { params });
    },
    export(params) {
        return axios.get(`${API_BASE}/export`, { params, responseType: "blob" });
    },
};
