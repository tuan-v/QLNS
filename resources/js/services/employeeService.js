import axios from "axios";
const API_BASE = "/api/v1/employees";

export default {
    list(params = {}) {
        return axios.get(API_BASE, { params });
    },
    get(id) {
        return axios.get(`${API_BASE}/${id}`);
    },
    contracts(id) {
        return axios.get(`${API_BASE}/${id}/contracts`);
    },
    create(employee) {
        return axios.post(API_BASE, employee);
    },
    update(id, employee) {
        return axios.put(`${API_BASE}/${id}`, employee);
    },
};
