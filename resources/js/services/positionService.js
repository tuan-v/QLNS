import axios from "axios";
const API_BASE = "/api/v1/positions";

export default {
    list(params = {}) {
        return axios.get(API_BASE, { params });
    },
};
