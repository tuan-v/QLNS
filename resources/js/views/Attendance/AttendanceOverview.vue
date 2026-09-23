<template>
    <div>
        <PageHeader
            title="Tổng hợp chấm công"
            subtitle="Tình hình chấm công toàn công ty theo ngày — ai đã chấm công, ai đang trong ca, ai vắng, ai nghỉ phép."
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

        <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
            <v-row dense>
                <v-col cols="6" sm="4" md="3">
                    <div class="text-body-2 font-weight-medium mb-1">Ngày</div>
                    <InputDate v-model="date" hide-details />
                </v-col>
                <v-col cols="6" sm="4" md="3">
                    <div class="text-body-2 font-weight-medium mb-1">Phòng ban</div>
                    <SearchSelect
                        v-model="departmentId"
                        :items="departmentOptions"
                        placeholder="Tất cả phòng ban"
                        density="compact"
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="4" md="3">
                    <div class="text-body-2 font-weight-medium mb-1">Ca làm việc</div>
                    <SearchSelect
                        v-model="workShiftId"
                        :items="shiftOptions"
                        placeholder="Tất cả ca"
                        density="compact"
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="4" md="3">
                    <div class="text-body-2 font-weight-medium mb-1">Trạng thái ca</div>
                    <v-select
                        v-model="statusFilter"
                        :items="statusOptions"
                        density="compact"
                        hide-details
                    />
                </v-col>
            </v-row>
        </v-sheet>

        <StatCards :stats="summaryStats" />

        <v-sheet class="border rounded-lg glass-panel mt-4" color="transparent">
            <DataTable
                :headers="headers"
                :items="rows"
                :loading="loading"
                :actions="actions"
                :actions-width="150"
                @action-error="loadData"
            >
                <template #item.employee="{ item }">
                    <div class="font-weight-medium">{{ item.employee.full_name }}</div>
                    <div class="text-caption" style="opacity: 0.6">
                        {{ item.employee.code }} · {{ item.employee.department?.name ?? "—" }}
                    </div>
                </template>
                <template #item.work_shift="{ item }">
                    {{ item.work_shift.name }}
                    <div class="text-caption" style="opacity: 0.6">
                        {{ item.work_shift.start_time?.slice(0, 5) }}-{{ item.work_shift.end_time?.slice(0, 5) }}
                    </div>
                </template>
                <template #item.times="{ item }">
                    {{ formatTime(item.attendance?.first_check_in_at) }} - {{ formatTime(item.attendance?.last_check_out_at) }}
                </template>
                <template #item.device="{ item }">
                    <template v-if="firstLog(item)">
                        <div>{{ firstLog(item).device_name ?? "—" }}</div>
                        <div class="text-caption" style="opacity: 0.6">
                            {{ firstLog(item).attendance_location?.name ?? "Không khớp điểm nào" }}
                        </div>
                    </template>
                    <span v-else style="opacity: 0.5">—</span>
                </template>
                <template #item.status="{ item }">
                    <StatusChip :status="item.status" :map="OVERVIEW_STATUS_MAP" />
                </template>
                <template #item.approval_status="{ item }">
                    <StatusChip
                        v-if="item.attendance"
                        :status="item.attendance.approval_status"
                        :map="APPROVAL_STATUS_MAP"
                    />
                    <span v-else style="opacity: 0.5">—</span>
                    <div
                        v-if="item.attendance?.approval_status === 'rejected' && item.attendance.approval_note"
                        class="text-caption mt-1"
                        style="opacity: 0.7"
                    >
                        {{ item.attendance.approval_note }}
                    </div>
                </template>
            </DataTable>
        </v-sheet>

        <v-dialog v-model="detailDialog" max-width="640">
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    {{ detailTarget?.employee?.full_name }} — {{ formatDate(date) }}
                </v-card-title>
                <v-card-text class="px-5">
                    <div v-if="!detailTarget?.attendance" class="text-center py-4" style="opacity: 0.6">
                        Chưa có nhật ký chấm công cho ca này.
                    </div>
                    <template v-else>
                        <div class="d-flex flex-wrap align-center ga-2 mb-3">
                            <span class="text-body-2" style="opacity: 0.75">Duyệt chấm công:</span>
                            <StatusChip :status="detailTarget.attendance.approval_status" :map="APPROVAL_STATUS_MAP" />
                            <span v-if="detailTarget.attendance.approval_note" class="text-body-2" style="opacity: 0.75">
                                — {{ detailTarget.attendance.approval_note }}
                            </span>
                        </div>
                        <AttendanceLogList :logs="detailTarget.attendance.logs ?? []" />
                    </template>
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
// "Tổng hợp chấm công trong ngày" (2026-09-23, theo yêu cầu người dùng — thay
// cho AttendanceApprovals.vue cũ, vốn chỉ liệt kê bản ghi ĐÃ CÓ trong
// attendances nên không thấy được ai CHƯA chấm công/đang nghỉ phép). Trang
// này gộp 2 việc: (1) XEM tình hình chấm công toàn công ty 1 ngày (quyền
// attendance.view_all — cả HR lẫn Manager), (2) DUYỆT/TỪ CHỐI ngay tại đó
// (quyền attendance.approve — chỉ HR/Admin, nút tự ẩn theo quyền, Backend
// vẫn tự chặn ở API dù nút có lỡ hiện ra).
import { computed, onMounted, ref, watch } from "vue";
import attendanceService from "../../services/attendanceService";
import workShiftService from "../../services/workShiftService";
import { useAuthStore } from "../../stores/authStore";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate, { todayIso } from "../../components/common/InputDate.vue";
import AttendanceLogList from "../../components/attendance/AttendanceLogList.vue";
import { APPROVAL_STATUS_MAP, formatDate, formatTime } from "../../composables/useCheckIn";
import { useToastStore } from "../../stores/useToastStore";

const toast = useToastStore();
const auth = useAuthStore();
const departmentStore = useDepartmentStore();

// Trạng thái CA riêng cho màn "hôm nay" — pending/completed/needs_review lấy
// thẳng từ attendances.status (bản ghi ĐÃ có), absent/on_leave suy ra khi
// CHƯA có bản ghi. Khác HISTORY_STATUS_MAP ở AttendanceHistoryPanel.vue (đó
// là full/late/insufficient — hợp cho nhìn lại quá khứ, không hợp cho "đang
// trong ca thì tính sao" — xem AttendanceService::dailyOverview()).
const OVERVIEW_STATUS_MAP = {
    pending: { label: "Đang trong ca", color: "info" },
    completed: { label: "Hoàn tất", color: "success" },
    needs_review: { label: "Cần xem lại", color: "warning" },
    absent: { label: "Vắng", color: "default" },
    on_leave: { label: "Nghỉ phép", color: "purple" },
};

const statusOptions = [
    { title: "Tất cả", value: null },
    { title: "Đang trong ca", value: "pending" },
    { title: "Hoàn tất", value: "completed" },
    { title: "Cần xem lại", value: "needs_review" },
    { title: "Vắng", value: "absent" },
    { title: "Nghỉ phép", value: "on_leave" },
];

const date = ref(todayIso());
const departmentId = ref(null);
const workShiftId = ref(null);
const statusFilter = ref(null);

function flattenDepartments(nodes) {
    return (nodes ?? []).flatMap((node) => [node, ...(node.children?.length ? flattenDepartments(node.children) : [])]);
}
const departmentOptions = computed(() =>
    flattenDepartments(departmentStore.tree).map((dept) => ({ title: dept.name, value: dept.id })),
);

const shiftOptions = ref([]);
async function loadShiftOptions() {
    try {
        const response = await workShiftService.list({ per_page: 1000 });
        shiftOptions.value = response.data.data.map((s) => ({ title: s.name, value: s.id }));
    } catch {
        shiftOptions.value = [];
    }
}

const headers = [
    { title: "Nhân viên", key: "employee" },
    { title: "Ca", key: "work_shift", width: 140 },
    { title: "Giờ vào - ra", key: "times", width: 130 },
    { title: "Thiết bị / Điểm chấm công", key: "device" },
    { title: "Trạng thái ca", key: "status", width: 130 },
    { title: "Duyệt công", key: "approval_status", width: 160 },
];

function firstLog(item) {
    return item.attendance?.logs?.[0] ?? null;
}

const summary = ref({
    total: 0, completed: 0, pending: 0, needs_review: 0, absent: 0, on_leave: 0, awaiting_approval: 0,
});
const rows = ref([]);
const loading = ref(false);
const loadError = ref("");

// Đếm theo NHÂN VIÊN, không theo ca (2026-09-23, theo phản hồi người dùng) —
// 1 người có 2 ca cùng ngày (mục 14) chỉ tính 1 lần ở đây, xem quy tắc gộp ở
// AttendanceService::summarizeDailyOverview(). Bảng bên dưới vẫn 1 dòng/ca.
const summaryStats = computed(() => [
    { label: "Tổng số nhân viên", value: `${summary.value.total}`, color: "primary", icon: "mdi-account-group-outline" },
    { label: "Hoàn tất", value: `${summary.value.completed}`, color: "success", icon: "mdi-check-circle-outline" },
    { label: "Đang trong ca", value: `${summary.value.pending}`, color: "info", icon: "mdi-clock-outline" },
    { label: "Cần xem lại", value: `${summary.value.needs_review}`, color: "warning", icon: "mdi-alert-circle-outline" },
    { label: "Vắng", value: `${summary.value.absent}`, color: "default", icon: "mdi-account-off-outline" },
    { label: "Nghỉ phép", value: `${summary.value.on_leave}`, color: "purple", icon: "mdi-calendar-remove-outline" },
    { label: "Chờ duyệt", value: `${summary.value.awaiting_approval}`, color: "warning", icon: "mdi-clipboard-clock-outline" },
]);

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await attendanceService.dailyOverview({
            date: date.value,
            department_id: departmentId.value || undefined,
            work_shift_id: workShiftId.value || undefined,
            status: statusFilter.value || undefined,
        });
        summary.value = response.data.data.summary;
        rows.value = response.data.data.rows;
    } catch (e) {
        rows.value = [];
        loadError.value = e.response?.data?.message ?? "Không thể tải dữ liệu chấm công.";
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

const canApprove = computed(() => auth.permissions.includes("attendance.approve"));
// Duyệt được NGAY LÚC CHẤM CÔNG VÀO (2026-09-23, theo yêu cầu người dùng) —
// mục đích là xác nhận lượt chấm công vào có thật hay không (giờ vào/thiết
// bị/vị trí), không cần chờ chấm công ra. Xem cùng lý do ở
// AttendanceService::decideApproval().
const canDecide = (item) => Boolean(item.attendance?.first_check_in_at);

// Duyệt/Từ chối dùng thẳng cơ chế "confirm.input" của DataTable.vue, giống
// màn Duyệt điều chỉnh công. Từ chối BẮT BUỘC có lý do (backend cũng yêu cầu).
const actions = computed(() => [
    {
        icon: "mdi-eye-outline",
        tooltip: "Chi tiết thiết bị / vị trí",
        color: "primary",
        hidden: (item) => !item.attendance,
        onClick: (item) => openDetail(item),
    },
    {
        icon: "mdi-check",
        tooltip: "Duyệt",
        color: "success",
        hidden: (item) => !canApprove.value || item.attendance?.approval_status === "approved" || !canDecide(item),
        confirm: {
            title: "Duyệt chấm công",
            message: (item) =>
                `Duyệt chấm công của ${item.employee.full_name} ngày ${formatDate(date.value)}? Bản ghi được duyệt sẽ được tính công và lương.`,
            confirmText: "Duyệt",
            input: { required: false, label: "Ghi chú (tùy chọn)" },
        },
        onClick: async (item, { input }) => {
            await attendanceService.decideApproval(item.attendance.id, {
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
        hidden: (item) => !canApprove.value || item.attendance?.approval_status === "rejected" || !canDecide(item),
        confirm: {
            title: "Từ chối chấm công",
            message: (item) =>
                `Từ chối chấm công của ${item.employee.full_name} ngày ${formatDate(date.value)}? Bản ghi bị từ chối sẽ không được tính công và lương.`,
            confirmText: "Từ chối",
            input: { required: true, label: "Lý do từ chối" },
        },
        onClick: async (item, { input }) => {
            await attendanceService.decideApproval(item.attendance.id, {
                status: "rejected",
                decision_note: input,
            });
            toast.success("Đã từ chối chấm công.");
            await loadData();
        },
    },
]);

watch([date, departmentId, workShiftId, statusFilter], loadData);

onMounted(() => {
    departmentStore.fetchTree();
    loadShiftOptions();
    loadData();
});
</script>
