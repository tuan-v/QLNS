import { ref } from "vue";
import { defineStore } from "pinia";
import { getEcho } from "../echo";

// Live-feed đơn nghỉ phép cho Manager/HR (mục "Thông báo" CODE_MAP, xem thêm
// useAttendanceFeedStore.js — cùng khuôn) — CHỈ là 1 "tiếng chuông" báo trang
// "Duyệt nghỉ phép" tự loadData() lại khi có đơn mới/đổi trạng thái, KHÔNG
// giữ dữ liệu gì (khác useNotificationStore.js, đây không phải hộp thư đọc/
// chưa đọc). 2026-09-25, theo yêu cầu người dùng: "duyệt ... nhưng bên tài
// khoản nhân sự phải F5 lại mới thấy".
export const useLeaveFeedStore = defineStore("leaveFeed", () => {
    const signal = ref(0);
    let connected = false;

    // Nhận sẵn danh sách quyền thay vì tự đọc authStore — tránh mọi user
    // thường đều cố join kênh rồi bị 403 vô ích (kênh chỉ cho phép
    // leave.approve_manager/leave.approve_hr, xem routes/channels.php).
    function connect(permissions) {
        const canApprove = permissions?.includes("leave.approve_manager") || permissions?.includes("leave.approve_hr");
        if (connected || !canApprove) {
            return;
        }
        connected = true;

        getEcho()
            .private("leave-requests.live-feed")
            .listen(".leave-request.changed", () => {
                signal.value++;
            })
            .error((error) => {
                console.error("Không thể kết nối live-feed nghỉ phép:", error);
            });
    }

    function disconnect() {
        if (!connected) {
            return;
        }
        connected = false;
        getEcho().leave("leave-requests.live-feed");
    }

    return { signal, connect, disconnect };
});
