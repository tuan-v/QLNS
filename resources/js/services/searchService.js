import axios from "axios";

export default {
    search(q) {
        return axios.get("/api/v1/search", { params: { q } });
    },
};
