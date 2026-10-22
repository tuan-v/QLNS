import axios from "axios";

// Phụ cấp & trợ cấp — xem routes/api/v1/allowances.php.
export default {
    catalog() {
        return axios.get("/api/v1/allowances");
    },
    create(data) {
        return axios.post("/api/v1/allowances", data);
    },
    update(id, data) {
        return axios.put(`/api/v1/allowances/${id}`, data);
    },
    remove(id) {
        return axios.delete(`/api/v1/allowances/${id}`);
    },
    applyToAll(id, effectiveFrom) {
        return axios.post(`/api/v1/allowances/${id}/apply-suggested`, { effective_from: effectiveFrom });
    },
    employees(params) {
        return axios.get("/api/v1/allowances/employees", { params });
    },
    bulkSet(data) {
        return axios.post("/api/v1/allowances/bulk-set", data);
    },
    forEmployee(employeeId) {
        return axios.get(`/api/v1/employees/${employeeId}/allowances`);
    },
    mine() {
        return axios.get("/api/v1/employees/me/allowances");
    },
    set(employeeId, data) {
        return axios.post(`/api/v1/employees/${employeeId}/allowances`, data);
    },
    applySuggested(employeeId, effectiveFrom) {
        return axios.post(`/api/v1/employees/${employeeId}/allowances/apply-suggested`, {
            effective_from: effectiveFrom,
        });
    },
    // Đề xuất thay đổi phụ cấp (HR gửi, Admin duyệt).
    requests(params) {
        return axios.get("/api/v1/allowance-requests", { params });
    },
    approveRequests(ids, note) {
        return axios.post("/api/v1/allowance-requests/approve", { ids, note });
    },
    rejectRequests(ids, note) {
        return axios.post("/api/v1/allowance-requests/reject", { ids, note });
    },
    cancelRequest(id) {
        return axios.delete(`/api/v1/allowance-requests/${id}`);
    },
};
