import axios from "axios";
const API_BASE = "/api/v1/attendance-locations";

export default {
    list(params = {}) {
        return axios.get(API_BASE, { params });
    },
    create(location) {
        return axios.post(API_BASE, location);
    },
    update(id, location) {
        return axios.put(`${API_BASE}/${id}`, location);
    },
    remove(id) {
        return axios.delete(`${API_BASE}/${id}`);
    },
};
