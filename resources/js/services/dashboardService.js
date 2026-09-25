import axios from "axios";

export default {
    get(date) {
        return axios.get("/api/v1/dashboard", { params: date ? { date } : undefined });
    },
};
