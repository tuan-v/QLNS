import axios from "axios";

// Sao lưu dữ liệu — xem routes/api/v1/backups.php (quyền system.backup).
export default {
    list() {
        return axios.get("/api/v1/backups");
    },
    // Chạy nền, trả 202 ngay — xong sẽ có thông báo "backup.completed".
    create() {
        return axios.post("/api/v1/backups");
    },
    download(file) {
        return axios.get(`/api/v1/backups/${encodeURIComponent(file)}/download`, { responseType: "blob" });
    },
};
