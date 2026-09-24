import axios from "axios";
const API_BASE = "/api/v1/roles";
export default {
    list() {
        return axios.get(`${API_BASE}`);
    },
    show(id) {
        return axios.get(`${API_BASE}/${id}`);
    },
    create(role) {
        return axios.post(`${API_BASE}`, role);
    },
    update(id, role) {
        return axios.put(`${API_BASE}/${id}`, role);
    },
    remove(id) {
        return axios.delete(`${API_BASE}/${id}`);
    },
    updatePermissions(id, permissionIds) {
        return axios.put(`${API_BASE}/${id}/permissions`, { permission_ids: permissionIds });
    },
};
