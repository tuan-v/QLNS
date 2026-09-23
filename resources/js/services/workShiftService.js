import axios from "axios";
const API_BASE = "/api/v1/work-shifts";

export default {
    list(params = {}) {
        return axios.get(API_BASE, { params });
    },
    // Ca mặc định (2026-09-23) — trang Cài đặt (Settings.vue) đọc/sửa NGAY
    // bản ghi này. Trả về { data: null } nếu công ty CHƯA cấu hình.
    getDefault() {
        return axios.get(`${API_BASE}/default`);
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
