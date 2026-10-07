<template>
    <v-menu
        v-model="open"
        :close-on-content-click="false"
        location="bottom end"
        offset="8"
        @update:model-value="onToggle"
    >
        <template #activator="{ props }">
            <v-btn
                v-bind="props"
                icon
                variant="text"
                class="qlns-icon-btn"
                aria-label="Thông báo"
            >
                <v-badge
                    :model-value="store.unreadCount > 0"
                    :content="
                        store.unreadCount > 99 ? '99+' : store.unreadCount
                    "
                    color="error"
                    class="bell-badge"
                    offset-x="-2"
                    offset-y="-2"
                >
                    <v-icon size="20">mdi-bell-outline</v-icon>
                </v-badge>
            </v-btn>
        </template>

        <v-card
            width="400"
            max-width="calc(100vw - 24px)"
            rounded="xl"
            elevation="8"
        >
            <div class="d-flex align-center ga-2 px-5 pt-4 pb-3">
                <span class="text-h6 font-weight-bold">Thông báo</span>
                <v-spacer />
                <v-btn
                    v-if="store.unreadCount > 0"
                    variant="text"
                    size="small"
                    color="primary"
                    class="text-none"
                    @click="store.markAllRead()"
                >
                    Đánh dấu tất cả đã đọc
                </v-btn>
                <v-btn
                    icon
                    variant="text"
                    size="small"
                    aria-label="Đóng"
                    @click="open = false"
                >
                    <v-icon size="20">mdi-close</v-icon>
                </v-btn>
            </div>
            <v-divider />

            <div
                v-if="store.notifications.length === 0"
                class="text-center py-10 text-medium-emphasis"
            >
                <v-icon size="30" class="mb-2">mdi-bell-sleep-outline</v-icon>
                <div class="text-body-2">Chưa có thông báo nào</div>
            </div>

            <div v-else class="notif-scroll">
                <div
                    v-for="item in store.notifications"
                    :key="item.id"
                    class="notif-row d-flex align-start ga-3 px-5 py-4"
                    :class="{ 'notif-row--unread': !item.read_at }"
                    @click="onClickItem(item)"
                >
                    <!-- Có người gây ra (vd nhân viên vừa nộp đơn) -> ảnh/chữ cái đầu +
                         chấm online; thông báo hệ thống -> icon theo loại. -->
                    <v-badge
                        v-if="item.actor"
                        dot
                        location="bottom end"
                        offset-x="4"
                        offset-y="4"
                        :color="
                            presence.isOnline(item.actor.employee_id)
                                ? 'success'
                                : 'grey'
                        "
                    >
                        <v-avatar
                            size="44"
                            :color="
                                item.actor.avatar_url
                                    ? undefined
                                    : avatarColor(item.actor.full_name)
                            "
                            variant="tonal"
                        >
                            <v-img
                                v-if="item.actor.avatar_url"
                                :src="item.actor.avatar_url"
                                cover
                            />
                            <span v-else class="text-body-2 font-weight-bold">
                                {{ initials(item.actor.full_name) }}
                            </span>
                        </v-avatar>
                    </v-badge>
                    <v-avatar
                        v-else
                        size="44"
                        :color="systemIcon(item).color"
                        variant="tonal"
                    >
                        <v-icon :icon="systemIcon(item).icon" size="22" />
                    </v-avatar>

                    <div class="flex-grow-1 min-w-0">
                        <div v-if="item.actor" class="text-body-2 notif-text">
                            <template
                                v-for="(part, index) in messageParts(item)"
                                :key="index"
                            >
                                <span
                                    v-if="part.bold"
                                    class="font-weight-bold text-ink"
                                    >{{ part.text }}</span
                                >
                                <span v-else>{{ part.text }}</span>
                            </template>
                        </div>
                        <template v-else>
                            <div class="text-body-2 font-weight-bold text-ink">
                                {{ item.title }}
                            </div>
                            <div
                                class="text-body-2 text-medium-emphasis notif-text"
                            >
                                {{ item.message }}
                            </div>
                        </template>
                        <div class="text-caption text-medium-emphasis mt-1">
                            {{ TYPE_CATEGORY[item.type] ?? "Hệ thống" }}
                            <span class="mx-1">•</span>
                            {{ timeAgo(item.created_at) }}
                        </div>
                    </div>

                    <span
                        v-if="!item.read_at"
                        class="unread-dot bg-primary flex-shrink-0 mt-2"
                    />
                </div>
            </div>

            <v-divider />
            <div class="pa-4">
                <v-btn
                    block
                    variant="outlined"
                    rounded="lg"
                    class="text-none"
                    :to="{ name: 'notifications' }"
                    @click="open = false"
                >
                    Xem tất cả thông báo
                </v-btn>
            </div>
        </v-card>
    </v-menu>
</template>

<script setup>
import { ref } from "vue";
import { useNotificationStore } from "../../stores/useNotificationStore";
import { usePresenceStore } from "../../stores/usePresenceStore";
import { avatarColor, initials } from "../../composables/avatar";
import { useNotificationNavigation } from "../../composables/useNotificationNavigation";

const store = useNotificationStore();
const presence = usePresenceStore();
const { openNotification } = useNotificationNavigation();

const open = ref(false);

// Chỉ tải lại danh sách khi MỞ dropdown (không cần liên tục poll — thông báo
// mới đã tự đẩy vào qua kênh realtime, xem useNotificationStore::connect()).
function onToggle(isOpen) {
    if (isOpen) {
        store.fetchList();
    }
}

// Nhãn nhóm ở dòng phụ "Nhóm • thời gian" (giống mẫu người dùng gửi).
const TYPE_CATEGORY = {
    "leave.pending_manager": "Nghỉ phép",
    "leave.pending_hr": "Nghỉ phép",
    "leave.decided": "Nghỉ phép",
    "holiday.upcoming": "Ngày nghỉ lễ",
    "holiday.confirm_needed": "Ngày nghỉ lễ",
    "attendance.pending_approval": "Chấm công",
    "attendance.checkout_reminder": "Nhắc nhở",
    "attendance_adjustment.pending": "Điều chỉnh công",
    "resignation.pending": "Nghỉ việc",
    "resignation.notice": "Nghỉ việc",
    "resignation.decided": "Nghỉ việc",
    "recruitment.cv_pending": "Tuyển dụng",
    "recruitment.cv_reviewed": "Tuyển dụng",
    "recruitment.interview_assigned": "Tuyển dụng",
    "checklist.mine": "Onboarding",
    "checklist.team": "Onboarding",
};

// Thông báo hệ thống (không có actor) — icon + màu theo loại.
function systemIcon(item) {
    if (item.type === "leave.decided" || item.type === "resignation.decided") {
        return item.data?.status === "approved"
            ? { icon: "mdi-check-circle-outline", color: "success" }
            : { icon: "mdi-close-circle-outline", color: "error" };
    }
    if (item.type === "holiday.confirm_needed") {
        return { icon: "mdi-calendar-alert", color: "warning" };
    }
    if (item.type === "holiday.upcoming") {
        return { icon: "mdi-party-popper", color: "primary" };
    }
    if (item.type === "checklist.mine" || item.type === "checklist.team") {
        return { icon: "mdi-clipboard-check-outline", color: "primary" };
    }
    if (item.type === "attendance.checkout_reminder") {
        return { icon: "mdi-clock-alert-outline", color: "warning" };
    }
    return { icon: "mdi-bell-outline", color: "primary" };
}

// Tô đậm tên người gây ra ngay trong câu thông báo (vd "**Vũ Tuấn Anh** vừa
// gửi đơn...") — tách chuỗi rồi render bằng text thường, KHÔNG dùng v-html
// (nội dung có tên/lý do do người dùng nhập, tránh chèn HTML).
function messageParts(item) {
    const name = item.actor?.full_name;
    const index = name ? item.message.indexOf(name) : -1;
    if (index === -1) {
        return [{ text: item.message, bold: false }];
    }
    return [
        { text: item.message.slice(0, index), bold: false },
        { text: name, bold: true },
        { text: item.message.slice(index + name.length), bold: false },
    ].filter((part) => part.text);
}

function onClickItem(item) {
    if (openNotification(item)) {
        open.value = false;
    }
}

function timeAgo(isoString) {
    const diffSeconds = Math.floor(
        (Date.now() - new Date(isoString).getTime()) / 1000,
    );
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
/* Huy hiệu số chưa đọc trên chuông: nhỏ gọn, đẩy lên góc phải-trên để không che
   biểu tượng chuông (mặc định của Vuetify cao 20px, chữ 12px). */
.bell-badge :deep(.v-badge__badge) {
    min-width: 16px;
    height: 16px;
    padding: 0 4px;
    font-size: 10px;
    font-weight: 600;
    line-height: 1;
}
.notif-scroll {
    max-height: 440px;
    overflow-y: auto;
}
.notif-row {
    cursor: pointer;
    border-bottom: 1px solid
        rgba(var(--v-border-color), var(--v-border-opacity));
    transition: background-color 0.15s ease;
}
.notif-row:last-child {
    border-bottom: 0;
}
.notif-row:hover {
    background: rgba(var(--v-theme-on-surface), 0.04);
}
.notif-row--unread {
    background: rgba(var(--v-theme-primary), 0.05);
}
.notif-text {
    line-height: 1.45;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.unread-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
}
.min-w-0 {
    min-width: 0;
}
</style>
