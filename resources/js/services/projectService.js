import axios from "axios";

// Dự án & công việc (2026-10-14) — xem routes/api/v1/projects.php.
export default {
    list(params = {}) {
        return axios.get("/api/v1/projects", { params });
    },
    show(id) {
        return axios.get(`/api/v1/projects/${id}`);
    },
    create(payload) {
        return axios.post("/api/v1/projects", payload);
    },
    update(id, payload) {
        return axios.put(`/api/v1/projects/${id}`, payload);
    },
    remove(id) {
        return axios.delete(`/api/v1/projects/${id}`);
    },
    leadCandidates(search) {
        return axios.get("/api/v1/projects/lead-candidates", { params: { search } });
    },
    memberCandidates(id, search) {
        return axios.get(`/api/v1/projects/${id}/member-candidates`, { params: { search } });
    },
    addMembers(id, employeeIds) {
        return axios.post(`/api/v1/projects/${id}/members`, { employee_ids: employeeIds });
    },
    removeMember(id, employeeId) {
        return axios.delete(`/api/v1/projects/${id}/members/${employeeId}`);
    },

    tasks(projectId, params = {}) {
        return axios.get(`/api/v1/projects/${projectId}/tasks`, { params });
    },
    myTasks(params = {}) {
        return axios.get("/api/v1/tasks/me", { params });
    },
    showTask(id) {
        return axios.get(`/api/v1/tasks/${id}`);
    },
    createTask(projectId, payload) {
        return axios.post(`/api/v1/projects/${projectId}/tasks`, payload);
    },
    updateTask(id, payload) {
        return axios.put(`/api/v1/tasks/${id}`, payload);
    },
    removeTask(id) {
        return axios.delete(`/api/v1/tasks/${id}`);
    },
    cancelTask(id) {
        return axios.post(`/api/v1/tasks/${id}/cancel`);
    },
    startTask(id) {
        return axios.post(`/api/v1/tasks/${id}/start`);
    },
    submitTask(id, note) {
        return axios.post(`/api/v1/tasks/${id}/submit`, { note });
    },
    reviewTask(id, payload) {
        return axios.post(`/api/v1/tasks/${id}/review`, payload);
    },
};
