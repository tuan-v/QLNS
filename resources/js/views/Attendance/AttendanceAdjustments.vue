<template>
    <div>
        <PageHeader
            title="Duyệt điều chỉnh công"
            subtitle="Xem xét các yêu cầu điều chỉnh giờ chấm công của nhân viên."
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

        <v-sheet
            class="border rounded-lg pa-4 mb-4 glass-panel"
            color="transparent"
        >
            <v-select
                v-model="statusFilter"
                :items="statusOptions"
                density="compact"
                hide-details
                style="max-width: 260px"
            />
        </v-sheet>

        <DataTable
            :headers="headers"
            :items="adjustments"
            :loading="loading"
            :actions="actions"
            @action-error="loadData"
        >
            <template #item.type="{ item }">
                <StatusChip :status="item.type" :map="ADJUSTMENT_TYPE_MAP" />
            </template>
            <template #item.employee="{ item }">
                {{ item.employee?.full_name ?? "—" }}
            </template>
            <template #item.work_shift="{ item }">
                {{ item.work_shift?.name ?? "—" }}
            </template>
            <template #item.attendance_date="{ item }">
                {{ formatDate(item.attendance_date) }}
            </template>
            <template #item.proposed="{ item }">
                <div v-if="item.type === 'excuse'" style="opacity: 0.75">
                    Xin miễn trừ đi muộn ({{ item.attendance?.late_minutes ?? "?" }} phút)
                </div>
                <div v-if="item.proposed_check_in_at">
                    Vào: {{ formatDateTime(item.proposed_check_in_at) }}
                </div>
                <div v-if="item.proposed_check_out_at">
                    Ra: {{ formatDateTime(item.proposed_check_out_at) }}
                </div>
            </template>
            <template #item.reason="{ item }">
                <span class="text-body-2">{{ item.reason }}</span>
            </template>
            <template #item.status="{ item }">
                <StatusChip
                    :status="item.status"
                    :map="ADJUSTMENT_STATUS_MAP"
                />
            </template>
        </DataTable>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import attendanceService from "../../services/attendanceService";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import { useToastStore } from "../../stores/useToastStore";

const toast = useToastStore();

const ADJUSTMENT_STATUS_MAP = {
    pending: { label: "Chờ duyệt", color: "warning" },
    approved: { label: "Đã duyệt", color: "success" },
    rejected: { label: "Đã từ chối", color: "default" },
};

const ADJUSTMENT_TYPE_MAP = {
    correction: { label: "Điều chỉnh", color: "info" },
    supplement: { label: "Bổ sung", color: "purple" },
    excuse: { label: "Miễn trừ đi muộn", color: "secondary" },
};

const statusOptions = [
    { title: "Chờ duyệt", value: "pending" },
    { title: "Đã duyệt", value: "approved" },
    { title: "Đã từ chối", value: "rejected" },
    { title: "Tất cả", value: null },
];

const statusFilter = ref("pending");
const adjustments = ref([]);
const loading = ref(false);
const loadError = ref("");

const headers = [
    { title: "Loại", key: "type", width: 110 },
    { title: "Nhân viên", key: "employee" },
    { title: "Ca", key: "work_shift", width: 140 },
    { title: "Ngày công", key: "attendance_date", width: 120 },
    { title: "Đề xuất", key: "proposed" },
    { title: "Lý do", key: "reason" },
    { title: "Trạng thái", key: "status", width: 130 },
];

function formatDate(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

function formatDateTime(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleString("vi-VN");
}

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await attendanceService.listAdjustments({
            status: statusFilter.value || undefined,
            per_page: 100,
        });
        adjustments.value = response.data.data;
    } catch (e) {
        adjustments.value = [];
        loadError.value =
            e.response?.data?.message ??
            "Không thể tải danh sách yêu cầu điều chỉnh.";
    } finally {
        loading.value = false;
    }
}

// Duyệt/Từ chối dùng thẳng cơ chế "confirm.input" có sẵn của DataTable.vue
// (đúng yêu cầu mục 2 tài liệu: "Duyệt/Từ chối kèm lý do") — không tự dựng
// dialog riêng.
const actions = computed(() => [
    {
        icon: "mdi-check",
        tooltip: "Duyệt",
        color: "success",
        hidden: (item) => item.status !== "pending",
        confirm: {
            title: "Duyệt điều chỉnh công",
            message: (item) =>
                `Áp dụng giờ đề xuất vào ngày công ${formatDate(item.attendance_date)}?`,
            confirmText: "Duyệt",
            input: { required: false, label: "Ghi chú (tùy chọn)" },
        },
        onClick: async (item, { input }) => {
            await attendanceService.decideAdjustment(item.id, {
                status: "approved",
                decision_note: input || null,
            });
            toast.success("Đã duyệt yêu cầu điều chỉnh công.");
            await loadData();
        },
    },
    {
        icon: "mdi-close",
        tooltip: "Từ chối",
        color: "error",
        hidden: (item) => item.status !== "pending",
        confirm: {
            title: "Từ chối điều chỉnh công",
            message: () => "Bạn có chắc muốn từ chối yêu cầu này không?",
            confirmText: "Từ chối",
            input: { required: false, label: "Lý do từ chối (tùy chọn)" },
        },
        onClick: async (item, { input }) => {
            await attendanceService.decideAdjustment(item.id, {
                status: "rejected",
                decision_note: input || null,
            });
            toast.success("Đã từ chối yêu cầu điều chỉnh công.");
            await loadData();
        },
    },
]);

watch(statusFilter, loadData);

onMounted(() => {
    loadData();
});
</script>
