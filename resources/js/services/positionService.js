import axios from "axios";
const API_BASE = "/api/v1/positions";

export default {
    list(params = {}) {
        return axios.get(API_BASE, { params });
    },
    create(position) {
        return axios.post(API_BASE, position);
    },
    update(id, position) {
        return axios.put(`${API_BASE}/${id}`, position);
    },
    remove(id) {
        return axios.delete(`${API_BASE}/${id}`);
    },
};
