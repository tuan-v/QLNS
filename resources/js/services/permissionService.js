import axios from "axios";
const API_BASE = "/api/v1/permissions";
export default {
    list() {
        return axios.get(`${API_BASE}`);
    },
    create(permission) {
        return axios.post(`${API_BASE}`, permission);
    },
    update(id, permission) {
        return axios.put(`${API_BASE}/${id}`, permission);
    },
    remove(id) {
        return axios.delete(`${API_BASE}/${id}`);
    },
};
