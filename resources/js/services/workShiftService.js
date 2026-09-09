import axios from "axios";
const API_BASE = "/api/v1/work-shifts";

export default {
    list(params = {}) {
        return axios.get(API_BASE, { params });
    },
    create(workShift) {
        return axios.post(API_BASE, workShift);
    },
    update(id, workShift) {
        return axios.put(`${API_BASE}/${id}`, workShift);
    },
    remove(id) {
        return axios.delete(`${API_BASE}/${id}`);
    },
};
