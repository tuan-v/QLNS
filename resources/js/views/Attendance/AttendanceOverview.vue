<template>
    <div>
        <PageHeader
            title="Tổng hợp chấm công"
            subtitle="Tình hình chấm công toàn công ty theo ngày — ai đã chấm công, ai đang trong ca, ai vắng, ai nghỉ phép."
        >
            <template #actions>
                <v-btn
                    color="success"
                    variant="flat"
                    prepend-icon="mdi-microsoft-excel"
                    @click="exportDialog = true"
                >
                    Xuất bảng chấm công
                </v-btn>
            </template>
        </PageHeader>

        <AttendanceSheetExportDialog
            v-model="exportDialog"
            :date="date"
            :department-id="departmentId"
            :department-options="departmentOptions"
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

        <!-- Live-feed chấm công (mục "Thông báo" CODE_MAP) — chỉ hiện khi có
             dữ liệu (rỗng lúc mới vào trang, chưa ai chấm công realtime nào).
             Không lưu DB, chỉ tham khảo tức thời — xem useAttendanceFeedStore.js. -->
        <v-sheet
            v-if="attendanceFeed.entries.length > 0"
            class="border rounded-lg pa-3 mb-4 glass-panel d-flex flex-wrap align-center ga-2"
            color="transparent"
        >
            <span
                class="text-caption font-weight-bold text-medium-emphasis mr-1"
            >
                <v-icon size="14" class="mr-1">mdi-access-point</v-icon>Vừa chấm
                công:
            </span>
            <v-chip
                v-for="(entry, index) in attendanceFeed.entries"
                :key="index"
                size="small"
                :color="entry.type === 'in' ? 'success' : 'default'"
                variant="tonal"
            >
                {{ entry.full_name }} —
                {{ entry.type === "in" ? "Vào" : "Ra" }} lúc
                {{ formatTime(entry.at) }}
            </v-chip>
        </v-sheet>

        <v-sheet
            class="border rounded-lg pa-4 mb-4 glass-panel"
            color="transparent"
        >
            <v-row dense>
                <v-col cols="6" sm="4" md="3">
                    <div class="text-body-2 font-weight-medium mb-1">Ngày</div>
                    <InputDate v-model="date" hide-details />
                </v-col>
                <v-col cols="6" sm="4" md="3">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Phòng ban
                    </div>
                    <SearchSelect
                        v-model="departmentId"
                        :items="departmentOptions"
                        placeholder="Tất cả phòng ban"
                        density="compact"
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="4" md="3">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Ca làm việc
                    </div>
                    <SearchSelect
                        v-model="workShiftId"
                        :items="shiftOptions"
                        placeholder="Tất cả ca"
                        density="compact"
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="4" md="3">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Trạng thái
                    </div>
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
                :actions-width="230"
                :selectable="canApprove"
                :item-value="(item) => item.attendance?.id"
                :item-selectable="itemSelectableForBulk"
                :bulk-actions="bulkActions"
                @action-error="loadData"
                @bulk-action-error="loadData"
            >
                <template #item.employee="{ item }">
                    <div class="font-weight-medium">
                        {{ item.employee.full_name }}
                    </div>
                    <div class="text-caption" style="opacity: 0.6">
                        {{ item.employee.code }} ·
                        {{ item.employee.department?.name ?? "—" }}
                    </div>
                </template>
                <template #item.work_shift="{ item }">
                    {{ item.work_shift.name }}
                    <div class="text-caption" style="opacity: 0.6">
                        {{ item.work_shift.start_time?.slice(0, 5) }}-{{
                            item.work_shift.end_time?.slice(0, 5)
                        }}
                    </div>
                </template>
                <template #item.times="{ item }">
                    <div class="d-flex align-center ga-1">
                        <span>Vào {{ formatTime(item.attendance?.first_check_in_at) }}</span>
                        <v-chip
                            v-if="item.attendance?.first_check_in_at"
                            size="x-small"
                            variant="tonal"
                            :color="PART_CHIP[partStatus(item, 'check_in')].color"
                        >
                            {{ PART_CHIP[partStatus(item, "check_in")].label }}
                        </v-chip>
                    </div>
                    <div class="d-flex align-center ga-1">
                        <span>Ra {{ formatTime(item.attendance?.last_check_out_at) }}</span>
                        <v-chip
                            v-if="item.attendance?.last_check_out_at"
                            size="x-small"
                            variant="tonal"
                            :color="PART_CHIP[partStatus(item, 'check_out')].color"
                        >
                            {{ PART_CHIP[partStatus(item, "check_out")].label }}
                        </v-chip>
                    </div>
                </template>
                <template #item.device="{ item }">
                    <template v-if="firstLog(item)">
                        <div>{{ firstLog(item).device_name ?? "—" }}</div>
                    </template>
                    <span v-else style="opacity: 0.5">—</span>
                </template>
                <template #item.status="{ item }">
                    <div class="d-flex align-center ga-1">
                        <StatusChip
                            :status="item.status"
                            :map="ATTENDANCE_STATUS_MAP"
                        />
                    </div>
                    <div
                        v-if="
                            item.status === 'rejected' &&
                            item.attendance?.approval_note
                        "
                        class="text-caption mt-1"
                        style="opacity: 0.7"
                    >
                        {{ item.attendance.approval_note }}
                    </div>
                    <div
                        v-if="item.status === 'holiday' && item.holiday_name"
                        class="text-caption mt-1"
                        style="opacity: 0.7"
                    >
                        {{ item.holiday_name }}
                    </div>
                </template>
            </DataTable>
        </v-sheet>

        <v-dialog v-model="detailDialog" max-width="640">
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    {{ detailTarget?.employee?.full_name }} —
                    {{ formatDate(date) }}
                </v-card-title>
                <v-card-text class="px-5">
                    <div
                        v-if="!detailTarget?.attendance"
                        class="text-center py-4"
                        style="opacity: 0.6"
                    >
                        Chưa có nhật ký chấm công cho ca này.
                    </div>
                    <template v-else>
                        <div class="d-flex flex-wrap align-center ga-2 mb-3">
                            <span class="text-body-2" style="opacity: 0.75"
                                >Duyệt chấm công:</span
                            >
                            <StatusChip
                                :status="
                                    detailTarget.attendance.approval_status
                                "
                                :map="APPROVAL_STATUS_MAP"
                            />
                            <span
                                v-if="detailTarget.attendance.approval_note"
                                class="text-body-2"
                                style="opacity: 0.75"
                            >
                                — {{ detailTarget.attendance.approval_note }}
                            </span>
                        </div>
                        <AttendanceLogList
                            :logs="detailTarget.attendance.logs ?? []"
                        />
                    </template>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn variant="text" @click="detailDialog = false"
                        >Đóng</v-btn
                    >
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
import { useRoute } from "vue-router";
import attendanceService from "../../services/attendanceService";
import workShiftService from "../../services/workShiftService";
import { useAuthStore } from "../../stores/authStore";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import { useAttendanceFeedStore } from "../../stores/useAttendanceFeedStore";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate, { todayIso } from "../../components/common/InputDate.vue";
import AttendanceLogList from "../../components/attendance/AttendanceLogList.vue";
import AttendanceSheetExportDialog from "./AttendanceSheetExportDialog.vue";
import {
    APPROVAL_STATUS_MAP,
    formatDate,
    formatTime,
} from "../../composables/useCheckIn";
import {
    ATTENDANCE_STATUS_MAP,
    ATTENDANCE_STATUS_OPTIONS,
} from "../../composables/attendanceStatus";
import { useToastStore } from "../../stores/useToastStore";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

const toast = useToastStore();
const auth = useAuthStore();
const route = useRoute();
const departmentStore = useDepartmentStore();
const attendanceFeed = useAttendanceFeedStore();

// Trạng thái 1 cột (vẫn gộp bước duyệt như yêu cầu 2026-09-24: "chưa được
// duyệt thì là chờ duyệt, duyệt rồi mới hiện trạng thái ca") — từ 2026-09-29
// Backend tự suy ra (AttendanceService::displayStatusFor()), DÙNG CHUNG quy
// tắc + nhãn với "Lịch sử chấm công"/"Bảng công gần nhất" của nhân viên
// (composables/attendanceStatus.js), nên HR và nhân viên luôn thấy cùng 1
// trạng thái cho cùng 1 ca.
const statusOptions = ATTENDANCE_STATUS_OPTIONS;

// Cho phép chỗ khác (vd khối "Cần bạn xử lý" ở Dashboard.vue) đưa thẳng tới
// đúng ngày còn bản ghi chờ duyệt qua query ?date=... — mặc định hôm nay như
// cũ nếu không có (2026-09-25, sửa bug thật: bấm vào mục "Chấm công chờ
// duyệt" luôn rơi vào hôm nay, có thể KHÔNG thấy bản ghi cần xử lý nếu nó từ
// ngày khác).
const date = ref(
    typeof route.query.date === "string" ? route.query.date : todayIso(),
);
const departmentId = ref(null);
const exportDialog = ref(false);
const workShiftId = ref(null);
const statusFilter = ref(null);

function flattenDepartments(nodes) {
    return (nodes ?? []).flatMap((node) => [
        node,
        ...(node.children?.length ? flattenDepartments(node.children) : []),
    ]);
}
const departmentOptions = computed(() =>
    flattenDepartments(departmentStore.tree).map((dept) => ({
        title: dept.name,
        value: dept.id,
    })),
);

const shiftOptions = ref([]);
async function loadShiftOptions() {
    try {
        const response = await workShiftService.list({ per_page: 1000 });
        shiftOptions.value = response.data.data.map((s) => ({
            title: s.name,
            value: s.id,
        }));
    } catch {
        shiftOptions.value = [];
    }
}

const headers = [
    { title: "Nhân viên", key: "employee" },
    { title: "Ca", key: "work_shift", width: 140 },
    { title: "Giờ vào - ra", key: "times", width: 190 },
    { title: "Thiết bị", key: "device" },
    // Gộp trạng thái ca + duyệt công vào 1 cột (xem AttendanceService::displayStatusFor()).
    { title: "Trạng thái", key: "status", width: 190 },
];

function firstLog(item) {
    return item.attendance?.logs?.[0] ?? null;
}

const summary = ref({ total: 0 });
const rows = ref([]);
const loading = ref(false);
const loadError = ref("");

// Đếm theo NHÂN VIÊN, không theo ca (2026-09-23, theo phản hồi người dùng) —
// 1 người có 2 ca cùng ngày (mục 14) chỉ tính 1 lần ở đây, xem quy tắc gộp ở
// AttendanceService::summarizeDailyOverview(). Bảng bên dưới vẫn 1 dòng/ca.
// Nhãn/màu lấy thẳng từ ATTENDANCE_STATUS_MAP (dùng chung) — chỉ icon là
// riêng của thẻ tổng quan. "Từ chối" và "Nghỉ lễ" chỉ hiện khi có (hiếm, tránh
// thêm thẻ số 0 thường trực).
const SUMMARY_ICONS = {
    pending_approval: "mdi-clipboard-clock-outline",
    rejected: "mdi-close-circle-outline",
    in_progress: "mdi-clock-outline",
    full: "mdi-check-circle-outline",
    late: "mdi-clock-alert-outline",
    insufficient: "mdi-alert-circle-outline",
    absent: "mdi-account-off-outline",
    on_leave: "mdi-calendar-remove-outline",
    holiday: "mdi-party-popper",
    overtime: "mdi-clock-plus-outline",
};

const summaryStats = computed(() => [
    {
        label: "Tổng số nhân viên",
        value: `${summary.value.total ?? 0}`,
        color: "primary",
        icon: "mdi-account-group-outline",
    },
    ...Object.entries(ATTENDANCE_STATUS_MAP)
        .filter(
            ([status]) =>
                !["rejected", "holiday", "overtime"].includes(status) ||
                summary.value[status] > 0,
        )
        .map(([status, { label, color }]) => ({
            label,
            value: `${summary.value[status] ?? 0}`,
            color,
            icon: SUMMARY_ICONS[status],
        })),
]);

async function loadData(opts) {
    const silent = opts?.silent === true;
    if (!silent) {
        loading.value = true;
    }
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
        loadError.value =
            e.response?.data?.message ?? "Không thể tải dữ liệu chấm công.";
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

const canApprove = computed(() =>
    auth.permissions.includes("attendance.approve"),
);
// Duyệt được NGAY LÚC CHẤM CÔNG VÀO (2026-09-23, theo yêu cầu người dùng) —
// mục đích là xác nhận lượt chấm công vào có thật hay không (giờ vào/thiết
// bị/vị trí), không cần chờ chấm công ra. Xem cùng lý do ở
// AttendanceService::decideApproval().
const canDecide = (item) => Boolean(item.attendance?.first_check_in_at);

// Giờ VÀO và giờ RA được duyệt RIÊNG (2 nút Duyệt + 2 nút Từ chối, mỗi cặp
// áp dụng cho đúng 1 phần). Duyệt = xác nhận lượt chấm công có thật; từ chối
// (vào hoặc ra) thì bản ghi không có công. Giờ ra chỉ duyệt được khi nhân viên
// đã chấm ra. Xem AttendanceService::decideApproval().
const PART_CHIP = {
    pending: { label: "Chờ duyệt", color: "warning" },
    approved: { label: "Đã duyệt", color: "success" },
    rejected: { label: "Từ chối", color: "error" },
};
const PART_FIELD = {
    check_in: "check_in_approval_status",
    check_out: "check_out_approval_status",
};
const partStatus = (item, part) =>
    item.attendance?.[PART_FIELD[part]] ?? "pending";
const partExists = (item, part) =>
    part === "check_in"
        ? Boolean(item.attendance?.first_check_in_at)
        : Boolean(item.attendance?.last_check_out_at);

function partActions(part, label, icon) {
    const who = (item) => `${item.employee.full_name} ngày ${formatDate(date.value)}`;

    return [
        {
            icon,
            tooltip: (item) =>
                partStatus(item, part) === "rejected"
                    ? `Đổi sang Duyệt ${label}`
                    : `Duyệt ${label}`,
            color: "success",
            hidden: (item) =>
                !canApprove.value ||
                !partExists(item, part) ||
                partStatus(item, part) === "approved",
            confirm: {
                title: `Duyệt ${label}`,
                message: (item) =>
                    `Duyệt ${label} của ${who(item)}? Bản ghi chỉ được tính công và lương khi cả giờ vào và giờ ra đều được duyệt.`,
                confirmText: "Duyệt",
                input: { required: false, label: "Ghi chú (tùy chọn)" },
            },
            onClick: async (item, { input }) => {
                await attendanceService.decideApproval(item.attendance.id, {
                    status: "approved",
                    part,
                    decision_note: input || null,
                });
                toast.success(`Đã duyệt ${label}.`);
                await loadData();
            },
        },
        {
            icon: part === "check_in" ? "mdi-account-cancel-outline" : "mdi-close",
            tooltip: (item) =>
                partStatus(item, part) === "approved"
                    ? `Đổi sang Từ chối ${label}`
                    : `Từ chối ${label}`,
            color: "error",
            hidden: (item) =>
                !canApprove.value ||
                !partExists(item, part) ||
                partStatus(item, part) === "rejected",
            confirm: {
                title: `Từ chối ${label}`,
                message: (item) =>
                    `Từ chối ${label} của ${who(item)}? Bản ghi bị từ chối sẽ không được tính công và lương.`,
                confirmText: "Từ chối",
                input: { required: true, label: "Lý do từ chối" },
            },
            onClick: async (item, { input }) => {
                await attendanceService.decideApproval(item.attendance.id, {
                    status: "rejected",
                    part,
                    decision_note: input,
                });
                toast.success(`Đã từ chối ${label}.`);
                await loadData();
            },
        },
    ];
}

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
    ...partActions("check_in", "giờ vào", "mdi-login"),
    ...partActions("check_out", "giờ ra", "mdi-logout"),
]);

// Chọn nhiều dòng chỉ để DUYỆT/TỪ CHỐI hàng loạt (2026-09-25, theo yêu cầu
// người dùng, kèm ảnh tham khảo "Bulk Actions") — dòng nào không có bản ghi
// chấm công (Vắng/Nghỉ phép) hoặc người xem không có quyền duyệt thì không
// cho chọn, cùng điều kiện với canDecide() ở nút duyệt từng dòng.
function itemSelectableForBulk(item) {
    return canApprove.value && canDecide(item);
}

// Cho phép chọn CHUNG cả dòng đã duyệt/đã từ chối vào cùng 1 lượt tick (đỡ
// phải bỏ chọn lại nếu lỡ tick nhầm), nhưng MỖI nút hàng loạt chỉ áp dụng lên
// đúng tập con dòng NÓ còn xử lý được — dòng đã ở đúng trạng thái đó rồi thì
// bỏ qua êm, không báo "lỗi" vô nghĩa (khác 1 dòng lỗi THẬT SỰ, vd bị người
// khác duyệt ngay trước đó — vẫn báo qua "failed" như cũ).
function isApprovable(item) {
    return canDecide(item) && item.attendance?.approval_status !== "approved";
}
function isRejectable(item) {
    return canDecide(item) && item.attendance?.approval_status !== "rejected";
}

const bulkActions = computed(() => {
    if (!canApprove.value) {
        return [];
    }

    return [
        {
            icon: "mdi-check-all",
            label: "Duyệt tất cả",
            tooltip: "Duyệt tất cả",
            color: "success",
            // Không có dòng nào TRONG SỐ đã chọn còn cần duyệt (vd đã chọn
            // toàn dòng đã duyệt rồi) — khóa nút thay vì để bấm ra vô nghĩa
            // (2026-09-25, theo phản hồi người dùng).
            disabled: (selectedItems) => !selectedItems.some(isApprovable),
            confirm: {
                title: "Duyệt chấm công hàng loạt",
                message:
                    "Duyệt TẤT CẢ nhân viên đã chọn (bỏ qua dòng đã duyệt sẵn)? Các nhân viên được duyệt sẽ được tính công và lương.",
                confirmText: "Duyệt tất cả",
                input: { required: false, label: "Ghi chú (tùy chọn)" },
            },
            onClick: (selectedItems, { input }) =>
                runBulkDecide(
                    selectedItems.filter(isApprovable),
                    "approved",
                    input || null,
                ),
        },
        {
            icon: "mdi-close-box-multiple-outline",
            label: "Từ chối tất cả",
            tooltip: "Từ chối tất cả",
            color: "error",
            disabled: (selectedItems) => !selectedItems.some(isRejectable),
            confirm: {
                title: "Từ chối chấm công hàng loạt",
                message:
                    "Từ chối TẤT CẢ nhân viên đã chọn (bỏ qua dòng đã từ chối sẵn)? Các nhân viên bị từ chối sẽ không được tính công và lương.",
                confirmText: "Từ chối tất cả",
                input: {
                    required: true,
                    label: "Lý do từ chối (áp dụng cho tất cả)",
                },
            },
            onClick: (selectedItems, { input }) =>
                runBulkDecide(
                    selectedItems.filter(isRejectable),
                    "rejected",
                    input,
                ),
        },
    ];
});

// KHÔNG throw khi có bản ghi lỗi — bulk-approval API luôn trả 200 kèm
// succeeded/failed (xem AttendanceService::bulkDecideApproval()), tự báo kết
// quả qua toast thay vì coi thất bại 1 phần là lỗi cả thao tác.
async function runBulkDecide(selectedItems, status, note) {
    const response = await attendanceService.bulkDecideApproval({
        attendance_ids: selectedItems.map((item) => item.attendance.id),
        status,
        decision_note: note,
    });
    const { succeeded, failed } = response.data;

    if (failed.length === 0) {
        toast.success(
            `Đã ${status === "approved" ? "duyệt" : "từ chối"} ${succeeded.length} nhân viên.`,
        );
    } else {
        toast.warning(
            `${succeeded.length} nhân viên thành công, ${failed.length} nhân viên lỗi: ${failed[0].message}`,
        );
    }
    await loadData();
}

watch([date, departmentId, workShiftId, statusFilter], loadData);

// Phiên HR/Manager KHÁC vừa Duyệt/Từ chối 1 bản ghi (nút "Duyệt"/"Từ chối" ở
// dưới tự loadData() sau khi CHÍNH MÌNH bấm rồi, watch này lo phần còn lại:
// những ai KHÁC đang mở sẵn trang này) — xem AttendanceApprovalDecided.
watch(
    () => attendanceFeed.approvalSignal,
    () => loadData(),
);

useRealtimeRefresh(loadData, {
    shared: [
        { resource: "attendances", permission: "attendance.view_all" },
        {
            resource: "leave_requests",
            permission: [
                "leave.approve_manager",
                "leave.approve_hr",
                "leave.view_all",
            ],
        },
    ],
});

onMounted(() => {
    departmentStore.fetchTree();
    loadShiftOptions();
    loadData();
});
</script>
