<template>
    <div>
        <PageHeader
            title="Duyệt chấm công"
            subtitle="Duyệt các lượt chấm công của nhân viên. Bản ghi chưa duyệt hoặc bị từ chối sẽ không được tính công và lương."
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

        <v-alert
            v-if="total > attendances.length"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-information-outline"
        >
            Đang hiển thị {{ attendances.length }}/{{ total }} bản ghi mới nhất. Duyệt xong rồi tải lại để xem phần còn lại.
        </v-alert>

        <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
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
            :items="attendances"
            :loading="loading"
            :actions="actions"
            :actions-width="150"
            @action-error="loadData"
        >
            <template #item.employee="{ item }">
                <div class="font-weight-medium">{{ item.employee?.full_name ?? "—" }}</div>
                <div class="text-caption" style="opacity: 0.6">{{ item.employee?.code }}</div>
            </template>
            <template #item.attendance_date="{ item }">
                {{ formatDate(item.attendance_date) }}
            </template>
            <template #item.work_shift="{ item }">
                {{ item.work_shift?.name ?? "—" }}
            </template>
            <template #item.times="{ item }">
                {{ formatTime(item.first_check_in_at) }} - {{ formatTime(item.last_check_out_at) }}
                <div v-if="!item.last_check_out_at" class="text-caption" style="opacity: 0.6">
                    Chưa chấm công ra
                </div>
            </template>
            <template #item.device="{ item }">
                <!-- Tóm tắt lượt VÀO (log đầu tiên); xem đủ cả vào/ra ở nút Chi tiết. -->
                <template v-if="firstLog(item)">
                    <div>{{ firstLog(item).device_name ?? "—" }}</div>
                    <div class="text-caption" style="opacity: 0.6">
                        {{ firstLog(item).attendance_location?.name ?? "Không khớp điểm nào" }}
                    </div>
                </template>
                <span v-else style="opacity: 0.5">—</span>
            </template>
            <template #item.status="{ item }">
                <StatusChip :status="item.status" :map="ATTENDANCE_STATUS_MAP" />
            </template>
            <template #item.approval_status="{ item }">
                <StatusChip :status="item.approval_status" :map="APPROVAL_STATUS_MAP" />
                <div
                    v-if="item.approval_status === 'rejected' && item.approval_note"
                    class="text-caption mt-1"
                    style="opacity: 0.7"
                >
                    {{ item.approval_note }}
                </div>
            </template>
        </DataTable>

        <v-dialog v-model="detailDialog" max-width="640">
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    {{ detailTarget?.employee?.full_name }} — {{ formatDate(detailTarget?.attendance_date) }}
                </v-card-title>
                <v-card-text class="px-5">
                    <div class="d-flex flex-wrap align-center ga-2 mb-3">
                        <span class="text-body-2" style="opacity: 0.75">Duyệt chấm công:</span>
                        <StatusChip :status="detailTarget?.approval_status" :map="APPROVAL_STATUS_MAP" />
                        <span v-if="detailTarget?.approval_note" class="text-body-2" style="opacity: 0.75">
                            — {{ detailTarget.approval_note }}
                        </span>
                    </div>
                    <AttendanceLogList :logs="detailTarget?.logs ?? []" />
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn variant="text" @click="detailDialog = false">Đóng</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
// Màn HR duyệt/từ chối từng bản ghi chấm công (ca + ngày) — 2026-09-21, theo
// yêu cầu người dùng: mọi lượt chấm công phải được duyệt, chưa duyệt thì không
// tính công/lương. Khác "Duyệt điều chỉnh công" (AttendanceAdjustments.vue):
// đó là duyệt YÊU CẦU nhân viên gửi lên; đây là duyệt chính BẢN GHI chấm công.
import { computed, onMounted, ref, watch } from "vue";
import attendanceService from "../../services/attendanceService";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import AttendanceLogList from "../../components/attendance/AttendanceLogList.vue";
import {
    APPROVAL_STATUS_MAP,
    ATTENDANCE_STATUS_MAP,
    formatDate,
    formatTime,
} from "../../composables/useCheckIn";
import { useToastStore } from "../../stores/useToastStore";

const toast = useToastStore();

const statusOptions = [
    { title: "Chờ duyệt", value: "pending" },
    { title: "Đã duyệt", value: "approved" },
    { title: "Bị từ chối", value: "rejected" },
    { title: "Tất cả", value: null },
];

const statusFilter = ref("pending");
const attendances = ref([]);
const total = ref(0);
const loading = ref(false);
const loadError = ref("");

const headers = [
    { title: "Nhân viên", key: "employee" },
    { title: "Ngày công", key: "attendance_date", width: 120 },
    { title: "Ca", key: "work_shift", width: 130 },
    { title: "Giờ vào - ra", key: "times", width: 130 },
    { title: "Thiết bị / Điểm chấm công", key: "device" },
    { title: "Trạng thái ca", key: "status", width: 130 },
    { title: "Duyệt công", key: "approval_status", width: 160 },
];

// Log đầu tiên theo thời gian = lượt vào (API trả logs theo thứ tự tạo).
function firstLog(item) {
    return item.logs?.[0] ?? null;
}

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await attendanceService.listForApproval({
            approval_status: statusFilter.value || undefined,
            per_page: 100,
        });
        attendances.value = response.data.data;
        total.value = response.data.total ?? attendances.value.length;
    } catch (e) {
        attendances.value = [];
        total.value = 0;
        loadError.value = e.response?.data?.message ?? "Không thể tải danh sách chấm công.";
    } finally {
        loading.value = false;
    }
}

const detailDialog = ref(false);
const detailTarget = ref(null);

function openDetail(item) {
    detailTarget.value = item;
    detailDialog.value = true;
}

// Bản ghi chưa chấm công ra thì chưa duyệt được (backend cũng chặn) — ẩn nút
// thay vì để bấm rồi mới báo lỗi.
const canDecide = (item) => Boolean(item.first_check_in_at && item.last_check_out_at);

// Duyệt/Từ chối dùng thẳng cơ chế "confirm.input" của DataTable.vue. Từ chối
// BẮT BUỘC có lý do (backend cũng yêu cầu) để nhân viên biết đường xin điều chỉnh.
const actions = computed(() => [
    {
        icon: "mdi-eye-outline",
        tooltip: "Chi tiết thiết bị / vị trí",
        color: "primary",
        onClick: (item) => openDetail(item),
    },
    {
        icon: "mdi-check",
        tooltip: "Duyệt",
        color: "success",
        hidden: (item) => item.approval_status === "approved" || !canDecide(item),
        confirm: {
            title: "Duyệt chấm công",
            message: (item) =>
                `Duyệt chấm công của ${item.employee?.full_name ?? "nhân viên"} ngày ${formatDate(item.attendance_date)}? Bản ghi được duyệt sẽ được tính công và lương.`,
            confirmText: "Duyệt",
            input: { required: false, label: "Ghi chú (tùy chọn)" },
        },
        onClick: async (item, { input }) => {
            await attendanceService.decideApproval(item.id, {
                status: "approved",
                decision_note: input || null,
            });
            toast.success("Đã duyệt chấm công.");
            await loadData();
        },
    },
    {
        icon: "mdi-close",
        tooltip: "Từ chối",
        color: "error",
        hidden: (item) => item.approval_status === "rejected" || !canDecide(item),
        confirm: {
            title: "Từ chối chấm công",
            message: (item) =>
                `Từ chối chấm công của ${item.employee?.full_name ?? "nhân viên"} ngày ${formatDate(item.attendance_date)}? Bản ghi bị từ chối sẽ không được tính công và lương.`,
            confirmText: "Từ chối",
            input: { required: true, label: "Lý do từ chối" },
        },
        onClick: async (item, { input }) => {
            await attendanceService.decideApproval(item.id, {
                status: "rejected",
                decision_note: input,
            });
            toast.success("Đã từ chối chấm công.");
            await loadData();
        },
    },
]);

watch(statusFilter, loadData);

onMounted(() => {
    loadData();
});
</script>
