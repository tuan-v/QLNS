<template>
    <div>
        <PageHeader
            title="Tổng hợp nghỉ phép"
            subtitle="Quỹ phép và tình hình nghỉ của từng nhân viên — ai còn bao nhiêu ngày phép, ai đang nghỉ, ai có đơn chờ duyệt."
        />

        <v-alert
            v-if="loadError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ loadError }}
        </v-alert>

        <StatCards :stats="summaryStats" class="mb-2" />

        <v-sheet class="border rounded-lg pa-2 mb-4 glass-panel" color="transparent">
            <div class="d-flex flex-wrap align-center ga-3">
                <SearchField v-model="search" placeholder="Tìm theo tên hoặc mã nhân viên..." />
                <v-select
                    v-model="departmentId"
                    :items="departmentOptions"
                    placeholder="Tất cả phòng ban"
                    clearable
                    density="compact"
                    hide-details
                    style="max-width: 240px"
                />
                <v-select
                    v-model="year"
                    :items="yearOptions"
                    label="Năm"
                    density="compact"
                    hide-details
                    style="max-width: 120px"
                />
            </div>
        </v-sheet>

        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <DataTable
                :headers="headers"
                :items="rows"
                :loading="loading"
                :server-items-length="total"
                no-data-text="Không có nhân viên nào khớp bộ lọc."
                v-model:page="page"
                v-model:items-per-page="perPage"
            >
                <template #item.employee="{ item }">
                    <div class="font-weight-medium">{{ item.employee.full_name }}</div>
                    <div class="text-caption" style="opacity: 0.65">
                        {{ item.employee.code }}<template v-if="item.employee.department"> · {{ item.employee.department }}</template>
                    </div>
                </template>

                <template #item.annual="{ item }">
                    <template v-if="item.annual">
                        <div class="font-weight-medium" :class="remainingClass(item.annual)">
                            Còn {{ formatDays(item.annual.remaining_days) }} / {{ formatDays(item.annual.allocated_days) }} ngày
                        </div>
                        <div class="text-caption" style="opacity: 0.65">
                            Đã dùng {{ formatDays(item.annual.used_days) }}<template v-if="item.annual.pending_days > 0">
                                · chờ duyệt {{ formatDays(item.annual.pending_days) }}</template>
                        </div>
                    </template>
                    <span v-else style="opacity: 0.5">—</span>
                </template>

                <template #item.other_days="{ item }">{{ formatDays(item.other_days) }}</template>
                <template #item.unpaid_days="{ item }">{{ formatDays(item.unpaid_days) }}</template>

                <template #item.pending_requests="{ item }">
                    <v-chip v-if="item.pending_requests > 0" size="small" color="warning" variant="tonal">
                        {{ item.pending_requests }} đơn
                    </v-chip>
                    <span v-else style="opacity: 0.5">—</span>
                </template>

                <template #item.next_leave="{ item }">
                    <v-chip v-if="item.on_leave_today" size="small" color="info" variant="tonal" class="mb-1">
                        Đang nghỉ
                    </v-chip>
                    <div v-if="item.next_leave" class="text-body-2">
                        {{ formatDate(item.next_leave.from_date) }}
                        <template v-if="item.next_leave.to_date !== item.next_leave.from_date">
                            - {{ formatDate(item.next_leave.to_date) }}
                        </template>
                        <div class="text-caption" style="opacity: 0.65">{{ item.next_leave.leave_type }}</div>
                    </div>
                    <span v-else-if="!item.on_leave_today" style="opacity: 0.5">—</span>
                </template>
            </DataTable>
        </v-sheet>
    </div>
</template>

<script setup>
// "Tổng hợp nghỉ phép" (mục 52 CODE_MAP, 2026-09-30, theo yêu cầu người dùng):
// bức tranh nghỉ phép của TOÀN BỘ nhân viên cho HR/Manager — khác "Duyệt nghỉ
// phép" (LeaveManagement.vue, danh sách ĐƠN để duyệt) ở chỗ mỗi dòng là 1
// NHÂN VIÊN. Dữ liệu do GET /leave-requests/overview tổng hợp sẵn.
import { computed, onMounted, ref, watch } from "vue";
import leaveRequestService from "../../services/leaveRequestService";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import PageHeader from "../../components/common/PageHeader.vue";
import DataTable from "../../components/common/DataTable.vue";
import SearchField from "../../components/common/SearchField.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
import { formatDate } from "../../composables/useCheckIn";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

const departmentStore = useDepartmentStore();

const currentYear = new Date().getFullYear();
// Chỉ năm hiện tại và các năm trước — năm sau chưa được cấp quỹ phép nên không có gì để tổng hợp.
const yearOptions = Array.from({ length: 5 }, (_, i) => currentYear - i);

const search = ref("");
const departmentId = ref(null);
const year = ref(currentYear);
const page = ref(1);
const perPage = ref(10);

const rows = ref([]);
const total = ref(0);
const summary = ref({ total_employees: 0, on_leave_today: 0, pending_requests: 0, days_used: 0 });
const loading = ref(false);
const loadError = ref("");

const headers = [
    { title: "Nhân viên", key: "employee" },
    { title: "Phép năm", key: "annual", width: 210 },
    { title: "Nghỉ khác (ngày)", key: "other_days", width: 130 },
    { title: "Không lương (ngày)", key: "unpaid_days", width: 140 },
    { title: "Chờ duyệt", key: "pending_requests", width: 110 },
    { title: "Đang / sắp nghỉ", key: "next_leave", width: 200 },
];

function flattenDepartments(nodes) {
    return (nodes ?? []).flatMap((node) => [
        node,
        ...(node.children?.length ? flattenDepartments(node.children) : []),
    ]);
}
const departmentOptions = computed(() =>
    flattenDepartments(departmentStore.tree).map((dept) => ({ title: dept.name, value: dept.id })),
);

function formatDays(value) {
    return new Intl.NumberFormat("vi-VN", { maximumFractionDigits: 2 }).format(value ?? 0);
}

// Hết phép (còn <= 0) tô đỏ, sắp hết (còn <= 2) tô cam để quản lý nhìn ra ngay.
function remainingClass(annual) {
    if (annual.remaining_days <= 0) {
        return "text-error";
    }
    return annual.remaining_days <= 2 ? "text-warning" : "";
}

const summaryStats = computed(() => [
    { label: "Nhân viên", value: summary.value.total_employees, icon: "mdi-account-group-outline", color: "primary" },
    { label: "Đang nghỉ hôm nay", value: summary.value.on_leave_today, icon: "mdi-beach", color: "info" },
    { label: "Đơn chờ duyệt", value: summary.value.pending_requests, icon: "mdi-clock-outline", color: "warning" },
    { label: `Ngày đã nghỉ năm ${year.value}`, value: formatDays(summary.value.days_used), icon: "mdi-calendar-check-outline", color: "success" },
]);

async function loadData(opts) {
    const silent = opts?.silent === true;
    if (!silent) {
        loading.value = true;
    }
    loadError.value = "";
    try {
        const response = await leaveRequestService.overview({
            year: year.value,
            department_id: departmentId.value || undefined,
            search: search.value || undefined,
            per_page: perPage.value,
            page: page.value,
        });
        rows.value = response.data.data;
        total.value = response.data.meta.total;
        summary.value = response.data.summary;
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không thể tải tổng hợp nghỉ phép.";
    } finally {
        loading.value = false;
    }
}

// Đổi bộ lọc / số dòng -> về trang 1 rồi tải; đổi trang thì chỉ tải lại.
watch([search, departmentId, year, perPage], () => {
    if (page.value !== 1) {
        page.value = 1;
        return;
    }
    loadData();
});
watch(page, () => loadData());

// Đơn nghỉ phép / quỹ phép đổi (ai đó nộp, duyệt, cấp phép) -> tự làm mới.
useRealtimeRefresh(loadData, {
    shared: [
        {
            resource: "leave_requests",
            permission: ["leave.approve_manager", "leave.approve_hr", "leave.view_all"],
        },
        { resource: "employees", permission: "employee.view" },
    ],
});

onMounted(() => {
    departmentStore.fetchTree();
    loadData();
});
</script>
