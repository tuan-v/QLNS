import { reactive } from "vue";
import { defineStore } from "pinia";
import { getEcho } from "../echo";

// Store DÙNG CHUNG cho "trang quản lý tự làm mới realtime" (mục 34 CODE_MAP,
// mở rộng 2026-09-25 — theo yêu cầu người dùng "làm toàn bộ trang cũng có
// realtime"). Khác useNotificationStore.js/useAttendanceFeedStore.js (join
// kênh 1 LẦN DUY NHẤT ở App.vue theo vòng đời đăng nhập/đăng xuất) — store
// này join/rời kênh THEO TỪNG TRANG (page-scoped): mỗi trang tự connect()
// lúc mounted, disconnect() lúc unmounted, vì mỗi resource chỉ có Ý NGHĨA
// realtime khi đúng trang đó đang mở, không cần giữ kết nối nền cho MỌI
// resource suốt phiên đăng nhập (khác thông báo cá nhân — luôn cần nhận dù
// đang ở trang nào).
export const useResourceSyncStore = defineStore("resourceSync", () => {
    // { [resource]: number } — mỗi resource 1 bộ đếm riêng, tăng dần khi
    // nhận tín hiệu. Tăng dần (không phải boolean/timestamp) để watch() ở
    // trang LUÔN bắt được, kể cả 2 lần bắn liên tiếp trong cùng 1 tick.
    const signals = reactive({});
    const joined = new Set();

    function connect(resource) {
        if (joined.has(resource)) {
            return;
        }
        joined.add(resource);
        if (!(resource in signals)) {
            signals[resource] = 0;
        }

        getEcho()
            .private(`resource-sync.${resource}`)
            .listen(".resource.changed", () => {
                signals[resource]++;
            })
            .error((error) => {
                console.error(`Không thể kết nối resource-sync.${resource}:`, error);
            });
    }

    function disconnect(resource) {
        if (!joined.has(resource)) {
            return;
        }
        joined.delete(resource);
        getEcho().leave(`resource-sync.${resource}`);
    }

    // App.vue gọi lúc đăng xuất, SAU disconnect() của các store lifecycle
    // toàn app nhưng TRƯỚC disconnectEcho() — dọn sạch `joined` phòng trường
    // hợp trang hiện tại KHÔNG kịp unmount (nên không tự disconnect() được)
    // trước khi disconnectEcho() phá kết nối dùng chung. Không dọn thì lần
    // đăng nhập LẠI sau đó (không tải lại trang), connect() cho đúng resource
    // đó sẽ tưởng đã join rồi (Set cũ) và bỏ qua, không nghe lại được gì.
    function resetAll() {
        joined.clear();
    }

    return { signals, connect, disconnect, resetAll };
});
