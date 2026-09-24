import { ref } from 'vue';
import { defineStore } from 'pinia';
import { getEcho } from '../echo';

// Trạng thái online/offline toàn hệ thống (2026-09-24) — kết nối 1 LẦN DUY
// NHẤT khi đăng nhập (App.vue gọi connect()/disconnect() theo authStore, xem
// AGENTS.md/CODE_MAP mục 32), KHÔNG gắn theo vòng đời của riêng trang Nhân
// viên — nếu không, user chỉ "online" khi đúng lúc đang đứng ở trang đó.
export const usePresenceStore = defineStore('presence', () => {
    const onlineEmployeeIds = ref(new Set());
    let connected = false;

    function isOnline(employeeId) {
        return onlineEmployeeIds.value.has(employeeId);
    }

    function connect() {
        if (connected) {
            return;
        }
        connected = true;

        getEcho()
            .join('presence.online-employees')
            .here((users) => {
                onlineEmployeeIds.value = new Set(
                    users.map((u) => u.employee_id).filter((id) => id != null),
                );
            })
            .joining((user) => {
                if (user.employee_id == null) {
                    return;
                }
                onlineEmployeeIds.value = new Set([
                    ...onlineEmployeeIds.value,
                    user.employee_id,
                ]);
            })
            .leaving((user) => {
                if (user.employee_id == null) {
                    return;
                }
                const next = new Set(onlineEmployeeIds.value);
                next.delete(user.employee_id);
                onlineEmployeeIds.value = next;
            })
            .error((error) => {
                console.error('Không thể kết nối trạng thái online:', error);
            });
    }

    function disconnect() {
        if (!connected) {
            return;
        }
        connected = false;
        // CHỈ rời kênh này — KHÔNG gọi disconnectEcho() (phá cả kết nối
        // WebSocket dùng CHUNG cho toàn app). App.vue là nơi DUY NHẤT được
        // phép đóng hẳn kết nối, sau khi mọi store đã rời kênh của mình.
        getEcho().leave('presence.online-employees');
        onlineEmployeeIds.value = new Set();
    }

    return { onlineEmployeeIds, isOnline, connect, disconnect };
});
