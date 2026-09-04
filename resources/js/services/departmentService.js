import axios from "axios";
const API_BASE = "/api/v1/departments";
export default {
    list(page = 1) {
        return axios.get(`${API_BASE}`, { params: { page } });
    },
    tree() {
        return axios.get(`${API_BASE}/tree`);
    },
    create(department) {
        return axios.post(`${API_BASE}`, department);
    },
    update(id, department) {
        return axios.put(`${API_BASE}/${id}`, department);
    },
    remove(id) {
        return axios.delete(`${API_BASE}/${id}`);
    },
};
