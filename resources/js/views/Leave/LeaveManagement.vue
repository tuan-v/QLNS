<template>
    <div>
        <PageHeader
            title="Duyệt nghỉ phép"
            subtitle="Xem xét và duyệt các đơn xin nghỉ phép của nhân viên."
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
            :items="leaveRequests"
            :loading="loading"
            :actions="actions"
            @action-error="loadData"
        >
            <template #item.employee="{ item }">
                {{ item.employee?.full_name ?? "—" }}
            </template>
            <template #item.leave_type="{ item }">
                {{ item.leave_type?.name ?? "—" }}
            </template>
            <template #item.from_date="{ item }">
                {{ formatDate(item.from_date) }}
            </template>
            <template #item.to_date="{ item }">
                {{ formatDate(item.to_date) }}
            </template>
            <template #item.total_days="{ item }">
                {{ item.total_days }}
            </template>
            <template #item.reason="{ item }">
                <span class="text-body-2">{{ item.reason }}</span>
            </template>
            <template #item.status="{ item }">
                <StatusChip :status="item.status" :map="LEAVE_STATUS_MAP" />
            </template>
        </DataTable>
    </div>
</template>

<script setup>
// Manager/HR duyệt đơn nghỉ phép — dùng thẳng cơ chế "confirm.input" có sẵn
// của DataTable.vue (đúng yêu cầu "1-Click Duyệt/Từ chối kèm lý do"), giống
// hệt AttendanceAdjustments.vue (mục 17). Backend (LeaveApprovalService::decide(),
// mục 20) tự phân biệt "Manager cấp 1" hay "HR cấp 2" dựa trên trạng thái đơn
// + danh tính người gọi — Frontend không tự đoán, chỉ hiện nút khi đơn CHƯA ở
// trạng thái cuối (pending/manager_approved), backend từ chối (422/403) nếu
// người bấm không đúng thẩm quyền ở đúng cấp đó.
import { computed, onMounted, ref, watch } from "vue";
import leaveRequestService from "../../services/leaveRequestService";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import { useToastStore } from "../../stores/useToastStore";

const toast = useToastStore();

const LEAVE_STATUS_MAP = {
    pending: { label: "Chờ duyệt", color: "warning" },
    manager_approved: { label: "Chờ HR duyệt", color: "info" },
    approved: { label: "Đã duyệt", color: "success" },
    rejected: { label: "Từ chối", color: "default" },
};

const statusOptions = [
    { title: "Chờ duyệt (Quản lý)", value: "pending" },
    { title: "Chờ HR duyệt", value: "manager_approved" },
    { title: "Đã duyệt", value: "approved" },
    { title: "Từ chối", value: "rejected" },
    { title: "Tất cả", value: null },
];

const statusFilter = ref("pending");
const leaveRequests = ref([]);
const loading = ref(false);
const loadError = ref("");

const headers = [
    { title: "Nhân viên", key: "employee" },
    { title: "Loại phép", key: "leave_type", width: 150 },
    { title: "Từ ngày", key: "from_date", width: 110 },
    { title: "Đến ngày", key: "to_date", width: 110 },
    { title: "Số ngày", key: "total_days", width: 90 },
    { title: "Lý do", key: "reason" },
    { title: "Trạng thái", key: "status", width: 140 },
];

function formatDate(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await leaveRequestService.list({
            status: statusFilter.value || undefined,
            per_page: 100,
        });
        leaveRequests.value = response.data.data;
    } catch (e) {
        leaveRequests.value = [];
        loadError.value =
            e.response?.data?.message ?? "Không thể tải danh sách đơn nghỉ phép.";
    } finally {
        loading.value = false;
    }
}

const actions = computed(() => [
    {
        icon: "mdi-check",
        tooltip: "Duyệt",
        color: "success",
        hidden: (item) => !["pending", "manager_approved"].includes(item.status),
        confirm: {
            title: "Duyệt đơn nghỉ phép",
            message: (item) =>
                `Duyệt đơn nghỉ phép của ${item.employee?.full_name ?? "nhân viên này"}?`,
            confirmText: "Duyệt",
            input: { required: false, label: "Ghi chú (tùy chọn)" },
        },
        onClick: async (item, { input }) => {
            await leaveRequestService.decide(item.id, {
                status: "approved",
                comment: input || null,
            });
            toast.success("Đã duyệt đơn nghỉ phép.");
            await loadData();
        },
    },
    {
        icon: "mdi-close",
        tooltip: "Từ chối",
        color: "error",
        hidden: (item) => !["pending", "manager_approved"].includes(item.status),
        confirm: {
            title: "Từ chối đơn nghỉ phép",
            message: () => "Bạn có chắc muốn từ chối đơn này không?",
            confirmText: "Từ chối",
            input: { required: false, label: "Lý do từ chối (tùy chọn)" },
        },
        onClick: async (item, { input }) => {
            await leaveRequestService.decide(item.id, {
                status: "rejected",
                comment: input || null,
            });
            toast.success("Đã từ chối đơn nghỉ phép.");
            await loadData();
        },
    },
]);

watch(statusFilter, loadData);

onMounted(() => {
    loadData();
});
</script>
