import axios from "axios";
const API_BASE = "/api/v1/roles";

export default {
    list() {
        return axios.get(API_BASE);
    },
};
