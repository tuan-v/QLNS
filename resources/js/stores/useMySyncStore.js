import { reactive } from "vue";
import { defineStore } from "pinia";
import { getEcho } from "../echo";

// Tín hiệu "dữ liệu CỦA TÔI vừa đổi" (mục 50 CODE_MAP) — nối 1 LẦN duy nhất
// theo vòng đời đăng nhập ở App.vue, dùng chung cho MỌI trang tự phục vụ
// (Dashboard, Chấm công, Nghỉ phép, Hồ sơ của tôi...) kể cả nhân viên thường.
// Backend bắn UserDataChanged { resource } mỗi khi 1 bản ghi thuộc về user
// này đổi (bất kể ai thao tác: chính họ, HR, Manager, job chạy nền).
// `signals[resource]` là bộ đếm tăng dần (không phải boolean) để watch() luôn
// bắt được kể cả 2 tín hiệu liên tiếp trong cùng 1 tick — cùng khuôn
// useResourceSyncStore.js.
export const useMySyncStore = defineStore("mySync", () => {
    const signals = reactive({});
    let joinedUserId = null;

    function connect(userId) {
        if (joinedUserId === userId) {
            return;
        }
        if (joinedUserId !== null) {
            disconnect();
        }
        joinedUserId = userId;

        getEcho()
            .private(`user-sync.${userId}`)
            .listen(".user-data.changed", (event) => {
                const resource = event?.resource;
                if (resource) {
                    signals[resource] = (signals[resource] ?? 0) + 1;
                }
            })
            .error((error) => {
                console.error(`Không thể kết nối user-sync.${userId}:`, error);
            });
    }

    // Chỉ rời kênh của mình, KHÔNG đóng kết nối chung — App.vue là nơi duy
    // nhất gọi disconnectEcho() (xem chú thích ở App.vue).
    function disconnect() {
        if (joinedUserId === null) {
            return;
        }
        getEcho().leave(`user-sync.${joinedUserId}`);
        joinedUserId = null;
    }

    return { signals, connect, disconnect };
});
