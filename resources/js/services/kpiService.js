import axios from "axios";

// KPI tháng từ công việc dự án — xem routes/api/v1/kpi.php.
const API_BASE = "/api/v1/kpi";

export default {
    filters() {
        return axios.get(`${API_BASE}/filters`);
    },
    monthly(params) {
        return axios.get(API_BASE, { params });
    },
    employee(employeeId, params) {
        return axios.get(`${API_BASE}/employees/${employeeId}`, { params });
    },
};
