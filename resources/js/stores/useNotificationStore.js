import { ref } from "vue";
import { defineStore } from "pinia";
import { getEcho } from "../echo";
import notificationService from "../services/notificationService";

// Thông báo cá nhân (mục "Thông báo" CODE_MAP) — kết nối dùng CHUNG cơ chế
// vòng đời với usePresenceStore.js (mở khi đăng nhập, đóng khi đăng xuất,
// do App.vue điều phối — xem ghi chú ở đó), NHƯNG cần biết user_id để join
// đúng kênh riêng "notifications.{userId}", nên connect() nhận userId làm
// tham số thay vì tự đọc authStore (tách 2 mối quan tâm: store này không
// cần biết gì về cấu trúc authStore).
export const useNotificationStore = defineStore("notification", () => {
    const notifications = ref([]);
    const unreadCount = ref(0);
    let connected = false;
    let joinedUserId = null;

    async function fetchList() {
        try {
            const response = await notificationService.list();
            notifications.value = response.data.data;
            unreadCount.value = response.data.unread_count;
        } catch {
            // Thông báo là tính năng phụ — lỗi tải không nên chặn cả trang.
        }
    }

    async function markRead(id) {
        try {
            await notificationService.markRead(id);
            const item = notifications.value.find((n) => n.id === id);
            if (item && !item.read_at) {
                item.read_at = new Date().toISOString();
                unreadCount.value = Math.max(0, unreadCount.value - 1);
            }
        } catch {
            //
        }
    }

    async function markAllRead() {
        try {
            await notificationService.markAllRead();
            const now = new Date().toISOString();
            notifications.value.forEach((n) => {
                n.read_at = n.read_at ?? now;
            });
            unreadCount.value = 0;
        } catch {
            //
        }
    }

    function connect(userId) {
        if (connected || !userId) {
            return;
        }
        connected = true;
        joinedUserId = userId;

        fetchList();

        getEcho()
            .private(`notifications.${userId}`)
            .listen(".notification.created", (payload) => {
                notifications.value = [payload, ...notifications.value];
                if (!payload.read_at) {
                    unreadCount.value += 1;
                }
            })
            .error((error) => {
                console.error("Không thể kết nối thông báo realtime:", error);
            });
    }

    function disconnect() {
        if (!connected) {
            return;
        }
        connected = false;
        if (joinedUserId != null) {
            getEcho().leave(`notifications.${joinedUserId}`);
        }
        joinedUserId = null;
        notifications.value = [];
        unreadCount.value = 0;
    }

    return { notifications, unreadCount, fetchList, markRead, markAllRead, connect, disconnect };
});
