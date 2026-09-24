import axios from "axios";

export default {
    get() {
        return axios.get("/api/v1/dashboard");
    },
};
