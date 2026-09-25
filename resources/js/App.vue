<script setup>
import { onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppLayout from './components/layout/AppLayout.vue';
import AppLoadingBar from './components/common/AppLoadingBar.vue';
import AppToast from './components/common/AppToast.vue';
import { useAuthStore } from './stores/authStore';
import { usePresenceStore } from './stores/usePresenceStore';
import { useNotificationStore } from './stores/useNotificationStore';
import { useAttendanceFeedStore } from './stores/useAttendanceFeedStore';
import { useLeaveFeedStore } from './stores/useLeaveFeedStore';
import { useResourceSyncStore } from './stores/useResourceSyncStore';
import { disconnectEcho } from './echo';

const route = useRoute();
const auth = useAuthStore();
const presence = usePresenceStore();
const notifications = useNotificationStore();
const attendanceFeed = useAttendanceFeedStore();
const leaveFeed = useLeaveFeedStore();
const resourceSync = useResourceSyncStore();

onMounted(() => {
    if (auth.accessToken && !auth.user) {
        auth.fetchMe();
    }
});

// Trạng thái online/offline (mục 32 CODE_MAP) đi theo TOÀN APP, không theo
// riêng trang Nhân viên — kết nối presence ngay khi đã đăng nhập (kể cả lúc
// mở app đã sẵn có token), ngắt khi đăng xuất. `immediate: true` để bắt đúng
// case app vừa mount mà token đã có sẵn trong localStorage.
//
// App.vue là nơi DUY NHẤT được gọi disconnectEcho() (đóng hẳn kết nối
// WebSocket dùng chung) — luôn gọi SAU KHI mọi store realtime đã tự rời
// kênh của mình (mỗi store.disconnect() chỉ rời kênh của nó, không đóng kết
// nối). Store realtime mới thêm sau này phải disconnect() ở đây TRƯỚC dòng
// disconnectEcho(), không được tự gọi disconnectEcho() bên trong store.
watch(
    () => auth.isAuthenticated,
    (loggedIn) => {
        if (loggedIn) {
            // Không cần thông tin gì thêm ngoài đã đăng nhập — kết nối ngay,
            // không đợi fetchMe() (khác notifications/attendanceFeed bên dưới).
            presence.connect();
        } else {
            presence.disconnect();
            notifications.disconnect();
            attendanceFeed.disconnect();
            leaveFeed.disconnect();
            // resourceSync là store PAGE-SCOPED (mỗi trang tự connect/
            // disconnect theo vòng đời mounted/unmounted của chính nó,
            // xem useResourceSyncStore.js) — resetAll() chỉ để dọn sạch
            // phòng trang hiện tại chưa kịp unmount trước khi mất kết nối.
            resourceSync.resetAll();
            disconnectEcho();
        }
    },
    { immediate: true },
);

// notifications/attendanceFeed cần user_id/quyền — dữ liệu này chỉ có SAU
// KHI fetchMe() (onMounted ở trên) tải xong, nên phải theo dõi auth.user
// riêng (đợi tải xong mới join, không join hụt lúc user còn null — vd sau
// khi F5 lại trang mà token đã có sẵn trong localStorage).
watch(
    () => auth.user,
    (user) => {
        if (user) {
            notifications.connect(user.id);
            attendanceFeed.connect(auth.permissions);
            leaveFeed.connect(auth.permissions);
        }
    },
    { immediate: true },
);
</script>

<template>
  <v-app>
    <AppLoadingBar />
    <AppLayout v-if="route.meta.layout !== 'blank'">
      <router-view />
    </AppLayout>
    <router-view v-else />
    <AppToast />
  </v-app>
</template>
