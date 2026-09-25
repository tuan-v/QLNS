<template>
    <div>
        <PageHeader
            title="Thông báo"
            subtitle="Xem lại thông báo của bạn, hoặc toàn bộ thông báo trong công ty nếu có quyền."
        />

        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <v-tabs v-model="tab">
                <v-tab value="mine">Thông báo của tôi</v-tab>
                <v-tab v-if="canViewAll" value="all">Toàn công ty</v-tab>
            </v-tabs>

            <v-divider />

            <v-window v-model="tab">
                <v-window-item value="mine">
                    <div class="pa-4">
                        <div class="d-flex justify-end mb-3">
                            <v-btn
                                v-if="mine.unreadCount > 0"
                                variant="tonal"
                                size="small"
                                @click="markAllMineRead"
                            >
                                Đánh dấu tất cả đã đọc ({{ mine.unreadCount }})
                            </v-btn>
                        </div>

                        <DataTable
                            :headers="mineHeaders"
                            :items="mine.items"
                            :loading="mine.loading"
                            :server-items-length="mine.total"
                            :actions="mineActions"
                            v-model:page="mine.page"
                            v-model:items-per-page="mine.perPage"
                        >
                            <template #item.title="{ item }">
                                <div :class="{ 'font-weight-bold': !item.read_at }">
                                    {{ item.title }}
                                </div>
                                <div class="text-caption" style="opacity: 0.7">{{ item.message }}</div>
                            </template>
                            <template #item.read_at="{ item }">
                                <StatusChip
                                    :status="item.read_at ? 'read' : 'unread'"
                                    :map="READ_STATUS_MAP"
                                />
                            </template>
                            <template #item.created_at="{ item }">
                                {{ formatDateTime(item.created_at) }}
                            </template>
                        </DataTable>
                    </div>
                </v-window-item>

                <v-window-item v-if="canViewAll" value="all">
                    <div class="pa-4">
                        <div class="d-flex flex-wrap ga-3 mb-3">
                            <SearchField
                                v-model="all.search"
                                placeholder="Tìm theo tên nhân viên nhận..."
                            />
                            <v-select
                                v-model="all.type"
                                :items="typeOptions"
                                placeholder="Tất cả loại thông báo"
                                clearable
                                density="compact"
                                hide-details
                                style="max-width: 260px"
                            />
                        </div>

                        <DataTable
                            :headers="allHeaders"
                            :items="all.items"
                            :loading="all.loading"
                            :server-items-length="all.total"
                            v-model:page="all.page"
                            v-model:items-per-page="all.perPage"
                        >
                            <template #item.recipient="{ item }">
                                <div class="font-weight-medium">
                                    {{ item.recipient?.employee_name ?? item.recipient?.user_name ?? "—" }}
                                </div>
                                <div class="text-caption" style="opacity: 0.6">
                                    {{ item.recipient?.employee_code ?? "" }}
                                </div>
                            </template>
                            <template #item.type="{ item }">
                                {{ TYPE_LABELS[item.type] ?? item.type }}
                            </template>
                            <template #item.title="{ item }">
                                <div>{{ item.title }}</div>
                                <div class="text-caption" style="opacity: 0.7">{{ item.message }}</div>
                            </template>
                            <template #item.created_at="{ item }">
                                {{ formatDateTime(item.created_at) }}
                            </template>
                        </DataTable>
                    </div>
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
import SearchField from "../../components/common/SearchField.vue";
import StatusChip from "../../components/common/StatusChip.vue";

const auth = useAuthStore();
const notificationStore = useNotificationStore();

const canViewAll = computed(() => auth.permissions.includes("notification.view_all"));

const tab = ref("mine");

const TYPE_LABELS = {
    "leave.pending_manager": "Đơn phép chờ quản lý duyệt",
    "leave.pending_hr": "Đơn phép chờ HR duyệt",
    "leave.decided": "Kết quả duyệt phép",
    "attendance.pending_approval": "Chấm công chờ duyệt",
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
    perPage: 10,
    unreadCount: 0,
});

const mineHeaders = [
    { title: "Thông báo", key: "title" },
    { title: "Trạng thái", key: "read_at", width: 140 },
    { title: "Thời gian", key: "created_at", width: 170 },
];

const mineActions = computed(() => [
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
        const response = await notificationService.list(mine.page);
        mine.items = response.data.data;
        mine.total = response.data.meta?.total ?? mine.items.length;
        mine.unreadCount = response.data.unread_count ?? 0;
    } catch {
        mine.items = [];
    } finally {
        mine.loading = false;
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

// --- Tab "Toàn công ty" — chỉ Admin (mặc định, quyền notification.view_all)
// — chỉ xem, không có thao tác đánh dấu đọc (không hợp lý để 1 người khác tự
// đánh dấu đã đọc thông báo của người khác thay họ).
const all = reactive({
    items: [],
    total: 0,
    loading: false,
    page: 1,
    perPage: 20,
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

// Đổi bộ lọc (search/type) -> về lại trang 1 rồi tải (khớp mẫu đã dùng ở
// Employees.vue) — đứng nguyên trang cũ dễ rơi vào trang trống nếu bộ lọc
// mới cho ít kết quả hơn.
watch([() => all.search, () => all.type], () => {
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
