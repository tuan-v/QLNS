import { ref } from "vue";
import { defineStore } from "pinia";
import { getEcho } from "../echo";

const MAX_ENTRIES = 20;

// Live-feed check-in/out cho HR xem trực tiếp trên trang Tổng quan chấm công
// (mục "Thông báo" CODE_MAP) — CHỈ giữ trong bộ nhớ, KHÔNG gọi API/lưu gì cả
// (khác useNotificationStore.js, đây không phải hộp thư đọc/chưa đọc).
export const useAttendanceFeedStore = defineStore("attendanceFeed", () => {
    const entries = ref([]);
    // Không mang dữ liệu gì cả — chỉ là 1 "tiếng chuông" báo trang "Tổng hợp
    // chấm công" tự loadData() lại (2026-09-25, theo yêu cầu người dùng: "duyệt
    // chấm công ở admin nhưng bên tài khoản nhân sự phải F5 lại mới thấy").
    // Tăng dần (không phải boolean/timestamp) để watch() ở trang LUÔN bắt
    // được thay đổi, kể cả 2 lần bắn liên tiếp trong cùng 1 tick.
    const approvalSignal = ref(0);
    let connected = false;

    // Nhận sẵn danh sách quyền thay vì tự đọc authStore — tránh mọi user
    // thường đều cố join kênh rồi bị 403 vô ích (kênh chỉ cho phép
    // attendance.view_all, xem routes/channels.php).
    function connect(permissions) {
        if (connected || !permissions?.includes("attendance.view_all")) {
            return;
        }
        connected = true;

        getEcho()
            .private("attendance.live-feed")
            .listen(".attendance.checked", (payload) => {
                entries.value = [payload, ...entries.value].slice(0, MAX_ENTRIES);
            })
            .listen(".attendance.approval-decided", () => {
                approvalSignal.value++;
            })
            .error((error) => {
                console.error("Không thể kết nối live-feed chấm công:", error);
            });
    }

    function disconnect() {
        if (!connected) {
            return;
        }
        connected = false;
        getEcho().leave("attendance.live-feed");
        entries.value = [];
    }

    return { entries, approvalSignal, connect, disconnect };
});
