import axios from "axios";

// Cài đặt hệ thống — xem routes/api/v1/settings.php.
export default {
    officeLocation() {
        return axios.get("/api/v1/settings/office-location");
    },
    updateOfficeLocation(data) {
        return axios.put("/api/v1/settings/office-location", data);
    },
};
