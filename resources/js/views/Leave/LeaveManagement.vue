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
            selectable
            :item-selectable="itemSelectableForBulk"
            :bulk-actions="bulkActions"
            @action-error="loadData"
            @bulk-action-error="loadData"
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
import { useLeaveFeedStore } from "../../stores/useLeaveFeedStore";

const toast = useToastStore();
const leaveFeed = useLeaveFeedStore();

const LEAVE_STATUS_MAP = {
    pending: { label: "Chờ duyệt", color: "warning" },
    manager_approved: { label: "Chờ HR duyệt", color: "info" },
    approved: { label: "Đã duyệt", color: "success" },
    rejected: { label: "Từ chối", color: "default" },
};

const statusOptions = [
    { title: "Tất cả", value: null },
    { title: "Chờ duyệt (Quản lý)", value: "pending" },
    { title: "Chờ HR duyệt", value: "manager_approved" },
    { title: "Đã duyệt", value: "approved" },
    { title: "Từ chối", value: "rejected" },
];

const statusFilter = ref(null);
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

// Chọn nhiều đơn để Duyệt/Từ chối hàng loạt (2026-09-25, theo yêu cầu người
// dùng, kèm ảnh tham khảo "Bulk Actions") — CÙNG điều kiện với `hidden` của 2
// nút duyệt từng dòng ở trên (đơn đã ở trạng thái cuối thì không cho chọn).
function itemSelectableForBulk(item) {
    return ["pending", "manager_approved"].includes(item.status);
}

const bulkActions = computed(() => [
    {
        icon: "mdi-check-all",
        label: "Duyệt tất cả",
        tooltip: "Duyệt tất cả",
        color: "success",
        confirm: {
            title: "Duyệt đơn nghỉ phép hàng loạt",
            message: "Duyệt TẤT CẢ đơn đã chọn?",
            confirmText: "Duyệt tất cả",
            input: { required: false, label: "Ghi chú (tùy chọn, áp dụng cho tất cả)" },
        },
        onClick: (selectedItems, { input }) => runBulkDecide(selectedItems, "approved", input || null),
    },
    {
        icon: "mdi-close-box-multiple-outline",
        label: "Từ chối tất cả",
        tooltip: "Từ chối tất cả",
        color: "error",
        confirm: {
            title: "Từ chối đơn nghỉ phép hàng loạt",
            message: "Từ chối TẤT CẢ đơn đã chọn?",
            confirmText: "Từ chối tất cả",
            input: { required: false, label: "Lý do từ chối (tùy chọn, áp dụng cho tất cả)" },
        },
        onClick: (selectedItems, { input }) => runBulkDecide(selectedItems, "rejected", input || null),
    },
]);

// KHÔNG throw khi có đơn lỗi — bulk-decide API luôn trả 200 kèm
// succeeded/failed (xem LeaveApprovalService::bulkDecide() — vd 1 đơn không
// phải cấp dưới của Manager đang bấm, hoặc đơn đã ở cấp khác), tự báo kết
// quả qua toast thay vì coi thất bại 1 phần là lỗi cả thao tác.
async function runBulkDecide(selectedItems, status, comment) {
    const response = await leaveRequestService.bulkDecide({
        leave_request_ids: selectedItems.map((item) => item.id),
        status,
        comment,
    });
    const { succeeded, failed } = response.data;

    if (failed.length === 0) {
        toast.success(`Đã ${status === "approved" ? "duyệt" : "từ chối"} ${succeeded.length} đơn.`);
    } else {
        toast.warning(
            `${succeeded.length} đơn thành công, ${failed.length} đơn lỗi: ${failed[0].message}`,
        );
    }
    await loadData();
}

watch(statusFilter, loadData);

// Phiên Manager/HR KHÁC vừa nộp đơn mới hoặc Duyệt/Từ chối — xem
// LeaveRequestChanged (2026-09-25, theo yêu cầu người dùng).
watch(
    () => leaveFeed.signal,
    () => loadData(),
);

onMounted(() => {
    loadData();
});
</script>
