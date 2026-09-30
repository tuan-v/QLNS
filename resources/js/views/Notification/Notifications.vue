<template>
    <div>
        <PageHeader
            title="Thông báo"
            subtitle="Xem lại thông báo của bạn, hoặc toàn bộ thông báo trong công ty nếu có quyền."
        />

        <v-sheet class="border rounded-lg glass-panel overflow-hidden">
            <!-- Thanh đầu card: tabs bên trái, thao tác của tab bên phải -->
            <div class="notif-head d-flex align-center flex-wrap ga-2 px-2 border-b">
                <v-tabs v-model="tab" class="notif-tabs">
                    <v-tab value="mine">
                        Thông báo của tôi
                        <v-chip
                            v-if="mine.unreadCount > 0"
                            color="primary"
                            size="x-small"
                            class="ml-2"
                        >
                            {{ mine.unreadCount }}
                        </v-chip>
                    </v-tab>
                    <v-tab v-if="canViewAll" value="all">Toàn công ty</v-tab>
                </v-tabs>

                <v-spacer />

                <v-btn
                    v-if="tab === 'mine' && mine.unreadCount > 0"
                    variant="text"
                    size="small"
                    class="mr-3 text-primary"
                    prepend-icon="mdi-check-all"
                    @click="markAllMineRead"
                >
                    Đánh dấu tất cả đã đọc
                </v-btn>
            </div>

            <v-window v-model="tab">
                <v-window-item value="mine">
                    <DataTable
                        flush
                        :headers="mineHeaders"
                        :items="mine.items"
                        :loading="mine.loading"
                        :server-items-length="mine.total"
                        :actions="mineActions"
                        no-data-text="Bạn chưa có thông báo nào."
                        v-model:page="mine.page"
                        v-model:items-per-page="mine.perPage"
                    >
                        <template #item.title="{ item }">
                            <div
                                class="d-flex align-start ga-3 py-2"
                                :class="{ 'notif-link': canOpen(item) }"
                                :role="canOpen(item) ? 'link' : undefined"
                                :tabindex="canOpen(item) ? 0 : undefined"
                                @click="canOpen(item) && openMine(item)"
                                @keydown.enter="canOpen(item) && openMine(item)"
                            >
                                <span
                                    class="notif-dot mt-2 flex-shrink-0"
                                    :class="item.read_at ? 'notif-dot--read' : 'bg-primary'"
                                />
                                <div class="min-w-0">
                                    <div
                                        class="text-body-2"
                                        :class="item.read_at ? 'font-weight-regular' : 'font-weight-medium text-ink'"
                                    >
                                        {{ item.title }}
                                    </div>
                                    <div class="text-caption text-medium-emphasis">
                                        {{ item.message }}
                                    </div>
                                </div>
                            </div>
                        </template>
                        <template #item.read_at="{ item }">
                            <StatusChip
                                :status="item.read_at ? 'read' : 'unread'"
                                :map="READ_STATUS_MAP"
                            />
                        </template>
                        <template #item.created_at="{ item }">
                            <span class="text-body-2 text-medium-emphasis text-no-wrap">
                                {{ formatDateTime(item.created_at) }}
                            </span>
                        </template>
                    </DataTable>
                </v-window-item>

                <v-window-item v-if="canViewAll" value="all">
                    <!-- Bộ lọc nằm trong card, ngay trên bảng -->
                    <div class="d-flex flex-wrap ga-3 px-5 py-4 border-b">
                        <SearchField
                            v-model="all.search"
                            placeholder="Tìm theo tên nhân viên nhận..."
                        />
                        <v-select
                            v-model="all.type"
                            :items="typeOptions"
                            placeholder="Tất cả loại thông báo"
                            clearable
                            hide-details
                            style="max-width: 280px; min-width: 220px"
                        />
                    </div>

                    <DataTable
                        flush
                        :headers="allHeaders"
                        :items="all.items"
                        :loading="all.loading"
                        :server-items-length="all.total"
                        no-data-text="Không có thông báo phù hợp."
                        v-model:page="all.page"
                        v-model:items-per-page="all.perPage"
                    >
                        <template #item.recipient="{ item }">
                            <div class="text-body-2 font-weight-medium">
                                {{ item.recipient?.employee_name ?? item.recipient?.user_name ?? "—" }}
                            </div>
                            <div class="text-caption text-medium-emphasis">
                                {{ item.recipient?.employee_code ?? "" }}
                            </div>
                        </template>
                        <template #item.type="{ item }">
                            <v-chip variant="outlined" size="small" class="type-chip">
                                {{ TYPE_LABELS[item.type] ?? item.type }}
                            </v-chip>
                        </template>
                        <template #item.title="{ item }">
                            <div class="py-2">
                                <div class="text-body-2">{{ item.title }}</div>
                                <div class="text-caption text-medium-emphasis">{{ item.message }}</div>
                            </div>
                        </template>
                        <template #item.created_at="{ item }">
                            <span class="text-body-2 text-medium-emphasis text-no-wrap">
                                {{ formatDateTime(item.created_at) }}
                            </span>
                        </template>
                    </DataTable>
                </v-window-item>
            </v-window>
        </v-sheet>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import notificationService from "../../services/notificationService";
import { useAuthStore } from "../../stores/authStore";
import { useNotificationStore } from "../../stores/useNotificationStore";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import { useNotificationNavigation } from "../../composables/useNotificationNavigation";
import SearchField from "../../components/common/SearchField.vue";
import StatusChip from "../../components/common/StatusChip.vue";

const auth = useAuthStore();
const notificationStore = useNotificationStore();
const { openNotification, canOpen } = useNotificationNavigation();

const canViewAll = computed(() => auth.permissions.includes("notification.view_all"));

const tab = ref("mine");

const TYPE_LABELS = {
    "leave.pending_manager": "Đơn phép chờ quản lý duyệt",
    "leave.pending_hr": "Đơn phép chờ HR duyệt",
    "leave.decided": "Kết quả duyệt phép",
    "attendance.pending_approval": "Chấm công chờ duyệt",
    "attendance_adjustment.pending": "Đơn điều chỉnh công chờ duyệt",
    "resignation.pending": "Đơn nghỉ việc chờ duyệt",
    "resignation.notice": "Thông báo nghỉ việc",
    "resignation.decided": "Kết quả duyệt đơn nghỉ việc",
};
const typeOptions = Object.entries(TYPE_LABELS).map(([value, title]) => ({ title, value }));

const READ_STATUS_MAP = {
    unread: { label: "Chưa đọc", color: "warning" },
    read: { label: "Đã đọc", color: "default" },
};

function formatDateTime(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleString("vi-VN", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    });
}

// --- Tab "Thông báo của tôi" — danh sách phân trang RIÊNG của trang này,
// KHÔNG dùng chung mảng của useNotificationStore.js (mảng đó phục vụ chuông
// realtime, luôn là trang 1 mới nhất — nếu dùng chung, sang trang 2 ở đây sẽ
// làm hỏng luôn nội dung chuông). Chỉ mượn store để cập nhật unreadCount cho
// đồng bộ với chuông sau khi đánh dấu đọc.
const mine = reactive({
    items: [],
    total: 0,
    loading: false,
    page: 1,
    // 10 — khớp itemsPerPage mặc định + đúng 1 trong 4 lựa chọn cố định của ô
    // "Số dòng" (DataTable.vue::perPageOptions = [10, 25, 50, 100]). Backend
    // giờ nhận per_page thật (2026-09-29, theo yêu cầu người dùng — trước đó
    // cố định 20, ẩn hẳn ô "Số dòng" vì chọn cũng không có tác dụng).
    perPage: 10,
    unreadCount: 0,
});

const mineHeaders = [
    { title: "Thông báo", key: "title" },
    { title: "Trạng thái", key: "read_at", width: 140 },
    { title: "Thời gian", key: "created_at", width: 180 },
];

const mineActions = computed(() => [
    {
        icon: "mdi-open-in-new",
        tooltip: "Mở nội dung được thông báo",
        color: "primary",
        hidden: (item) => !canOpen(item),
        onClick: openMine,
    },
    {
        icon: "mdi-email-open-outline",
        tooltip: "Đánh dấu đã đọc",
        color: "primary",
        hidden: (item) => Boolean(item.read_at),
        onClick: markMineRead,
    },
]);

async function fetchMine() {
    mine.loading = true;
    try {
        const response = await notificationService.list(mine.page, mine.perPage);
        mine.items = response.data.data;
        mine.total = response.data.meta?.total ?? mine.items.length;
        mine.unreadCount = response.data.unread_count ?? 0;
    } catch {
        mine.items = [];
    } finally {
        mine.loading = false;
    }
}

// Mở nội dung được thông báo + đồng bộ trạng thái đã đọc của DANH SÁCH RIÊNG
// của trang (khác mảng trong store dùng cho chuông).
function openMine(item) {
    const wasUnread = !item.read_at;
    openNotification(item);
    if (wasUnread) {
        item.read_at = new Date().toISOString();
        mine.unreadCount = Math.max(0, mine.unreadCount - 1);
    }
}

async function markMineRead(item) {
    await notificationStore.markRead(item.id);
    item.read_at = new Date().toISOString();
    mine.unreadCount = Math.max(0, mine.unreadCount - 1);
}

async function markAllMineRead() {
    await notificationStore.markAllRead();
    mine.items.forEach((item) => {
        item.read_at = item.read_at ?? new Date().toISOString();
    });
    mine.unreadCount = 0;
}

watch(() => mine.page, fetchMine);
// Thông báo MỚI vừa tới qua WebSocket (store chèn lên đầu) — nếu đang xem
// trang 1 của tab "Thông báo của tôi" thì làm mới danh sách, không cần F5.
watch(
    () => notificationStore.notifications[0]?.id,
    () => {
        if (mine.page === 1) {
            fetchMine();
        }
    },
);
// Đổi "Số dòng" -> về lại trang 1 rồi tải, cùng mẫu Employees.vue (đứng
// nguyên trang cũ dễ rơi vào trang trống nếu trang hiện tại vượt quá tổng số
// trang mới sau khi tăng/giảm số dòng/trang).
watch(
    () => mine.perPage,
    () => {
        mine.page = 1;
        fetchMine();
    },
);

// --- Tab "Toàn công ty" — chỉ Admin (mặc định, quyền notification.view_all)
// — chỉ xem, không có thao tác đánh dấu đọc (không hợp lý để 1 người khác tự
// đánh dấu đã đọc thông báo của người khác thay họ).
const all = reactive({
    items: [],
    total: 0,
    loading: false,
    page: 1,
    perPage: 10,
    search: "",
    type: null,
});

const allHeaders = [
    { title: "Người nhận", key: "recipient", width: 200 },
    { title: "Loại", key: "type", width: 200 },
    { title: "Nội dung", key: "title" },
    { title: "Thời gian", key: "created_at", width: 170 },
];

async function fetchAll() {
    if (!canViewAll.value) {
        return;
    }
    all.loading = true;
    try {
        const response = await notificationService.listAll({
            page: all.page,
            per_page: all.perPage,
            search: all.search || undefined,
            type: all.type || undefined,
        });
        all.items = response.data.data;
        all.total = response.data.meta?.total ?? all.items.length;
    } catch {
        all.items = [];
    } finally {
        all.loading = false;
    }
}

// Đổi bộ lọc (search/type/số dòng mỗi trang) -> về lại trang 1 rồi tải (khớp
// mẫu đã dùng ở Employees.vue) — đứng nguyên trang cũ dễ rơi vào trang trống
// nếu bộ lọc mới/số dòng mới cho ít trang hơn.
watch([() => all.search, () => all.type, () => all.perPage], () => {
    all.page = 1;
    fetchAll();
});
watch(() => all.page, fetchAll);

watch(tab, (value) => {
    if (value === "all" && all.items.length === 0) {
        fetchAll();
    }
});

fetchMine();
</script>

<style scoped>
.notif-head {
    min-height: 56px;
}
/* Tabs trong đầu card: bỏ đường kẻ dưới riêng (đầu card đã có border-b) */
.notif-tabs {
    border-bottom: 0 !important;
    height: 56px;
}
.notif-tabs :deep(.v-tab) {
    height: 56px !important;
}
.notif-link {
    cursor: pointer;
}
.notif-link:hover .text-body-2 {
    text-decoration: underline;
}
.notif-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
}
.notif-dot--read {
    background: rgb(var(--v-theme-hairline));
}
.min-w-0 {
    min-width: 0;
}
.type-chip {
    border-color: rgb(var(--v-theme-hairline)) !important;
    color: rgb(var(--v-theme-link)) !important;
}
</style>
