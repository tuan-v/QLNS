import axios from "axios";
const API_BASE = "/api/v1/addresses";

export default {
    provinces() {
        return axios.get(`${API_BASE}/provinces`);
    },
    communes(provinceCode) {
        return axios.get(`${API_BASE}/communes`, {
            params: { province_code: provinceCode },
        });
    },
};
