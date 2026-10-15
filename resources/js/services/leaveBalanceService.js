import axios from "axios";

// Quản lý số dư phép (2026-10-15) — xem LeaveBalanceController.
export default {
    detail(employeeId, year) {
        return axios.get(`/api/v1/leave-balances/${employeeId}`, { params: { year } });
    },
    mine(year) {
        return axios.get("/api/v1/leave-balances/me", { params: { year } });
    },
    adjust(employeeId, payload) {
        return axios.post(`/api/v1/leave-balances/${employeeId}/adjust`, payload);
    },
    leaveTypes() {
        return axios.get("/api/v1/leave-types");
    },
    updatePolicy(leaveTypeId, payload) {
        return axios.put(`/api/v1/leave-types/${leaveTypeId}/policy`, payload);
    },
};
