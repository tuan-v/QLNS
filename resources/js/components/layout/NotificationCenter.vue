<template>
    <v-menu :close-on-content-click="false" @update:model-value="onToggle">
        <template #activator="{ props }">
            <v-btn v-bind="props" icon variant="text">
                <v-badge
                    :model-value="store.unreadCount > 0"
                    :content="store.unreadCount > 99 ? '99+' : store.unreadCount"
                    color="error"
                    offset-x="2"
                    offset-y="2"
                >
                    <v-icon size="20">mdi-bell-outline</v-icon>
                </v-badge>
            </v-btn>
        </template>

        <v-list width="360" density="comfortable" class="pa-0">
            <div class="d-flex align-center justify-space-between px-4 py-3">
                <span class="text-subtitle-2 font-weight-bold">Thông báo</span>
                <v-btn
                    v-if="store.unreadCount > 0"
                    variant="text"
                    size="small"
                    density="compact"
                    @click="store.markAllRead()"
                >
                    Đánh dấu tất cả đã đọc
                </v-btn>
            </div>
            <v-divider />

            <div v-if="store.notifications.length === 0" class="text-center py-8 text-medium-emphasis">
                <v-icon size="32" class="mb-2">mdi-bell-sleep-outline</v-icon>
                <div class="text-body-2">Chưa có thông báo nào</div>
            </div>

            <v-list-item
                v-for="item in store.notifications"
                :key="item.id"
                :active="!item.read_at"
                class="notification-item"
                @click="onClickItem(item)"
            >
                <template #prepend>
                    <v-icon
                        :color="item.read_at ? 'medium-emphasis' : 'primary'"
                        size="18"
                    >
                        {{ item.read_at ? "mdi-email-open-outline" : "mdi-email-outline" }}
                    </v-icon>
                </template>
                <v-list-item-title
                    class="text-wrap"
                    :class="{ 'font-weight-bold': !item.read_at }"
                >
                    {{ item.title }}
                </v-list-item-title>
                <v-list-item-subtitle class="text-wrap">
                    {{ item.message }}
                </v-list-item-subtitle>
                <div class="text-caption text-medium-emphasis mt-1">
                    {{ timeAgo(item.created_at) }}
                </div>
            </v-list-item>

            <v-divider />
            <v-list-item
                class="text-center"
                title="Xem tất cả thông báo"
                :to="{ name: 'notifications' }"
            />
        </v-list>
    </v-menu>
</template>

<script setup>
import { useRouter } from "vue-router";
import { useNotificationStore } from "../../stores/useNotificationStore";

const store = useNotificationStore();
const router = useRouter();

// Chỉ tải lại danh sách khi MỞ dropdown (không cần liên tục poll — thông báo
// mới đã tự đẩy vào qua kênh realtime, xem useNotificationStore::connect()).
function onToggle(isOpen) {
    if (isOpen) {
        store.fetchList();
    }
}

// leave.decided -> nhân viên xem đơn CỦA MÌNH; leave.pending_manager/
// leave.pending_hr -> người NHẬN thông báo là người phải DUYỆT, đưa thẳng
// sang trang duyệt đơn (2 trang khác nhau, gate quyền khác nhau — không
// dùng chung 1 route cho mọi loại thông báo nghỉ phép).
const LEAVE_TYPE_ROUTES = {
    "leave.decided": "leave-requests",
    "leave.pending_manager": "leave-management",
    "leave.pending_hr": "leave-management",
};

function onClickItem(item) {
    if (!item.read_at) {
        store.markRead(item.id);
    }
    if (item.data?.leave_request_id && LEAVE_TYPE_ROUTES[item.type]) {
        router.push({ name: LEAVE_TYPE_ROUTES[item.type] });
    }
}

function timeAgo(isoString) {
    const diffSeconds = Math.floor((Date.now() - new Date(isoString).getTime()) / 1000);
    if (diffSeconds < 60) {
        return "Vừa xong";
    }
    const diffMinutes = Math.floor(diffSeconds / 60);
    if (diffMinutes < 60) {
        return `${diffMinutes} phút trước`;
    }
    const diffHours = Math.floor(diffMinutes / 60);
    if (diffHours < 24) {
        return `${diffHours} giờ trước`;
    }
    const diffDays = Math.floor(diffHours / 24);
    return `${diffDays} ngày trước`;
}
</script>

<style scoped>
.notification-item {
    cursor: pointer;
    white-space: normal;
}
</style>
