<template>
    <div>
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
                <v-col cols="12" sm="6" md="3">
                    <div class="text-body-2 font-weight-medium mb-1">Tháng</div>
                    <v-select
                        v-model="monthValue"
                        :items="monthOptions"
                        density="compact"
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="3" md="2">
                    <div class="text-body-2 font-weight-medium mb-1">Từ ngày</div>
                    <InputDate v-model="dateFrom" hide-details />
                </v-col>
                <v-col cols="6" sm="3" md="2">
                    <div class="text-body-2 font-weight-medium mb-1">Đến ngày</div>
                    <InputDate v-model="dateTo" hide-details />
                </v-col>
                <v-col cols="6" sm="6" md="3">
                    <div class="text-body-2 font-weight-medium mb-1">Ca</div>
                    <SearchSelect
                        v-model="workShiftId"
                        :items="shiftOptions"
                        placeholder="Tất cả"
                        density="compact"
                        hide-details
                    />
                </v-col>
                <v-col cols="6" sm="6" md="2">
                    <div class="text-body-2 font-weight-medium mb-1">Trạng thái</div>
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
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Ngày</th>
                        <th>Ca</th>
                        <th>Lịch làm</th>
                        <th>Thực tế</th>
                        <th>Trạng thái</th>
                        <th v-if="!readOnly" class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loading">
                        <td :colspan="readOnly ? 5 : 6" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!rows.length">
                        <td :colspan="readOnly ? 5 : 6" class="text-center py-6" style="opacity: 0.6">
                            Không có dữ liệu trong khoảng đã chọn.
                        </td>
                    </tr>
                    <tr v-for="row in rows" v-else :key="`${row.date}-${row.work_shift.id}`">
                        <td>{{ formatDate(row.date) }}</td>
                        <td>{{ row.work_shift.name }}</td>
                        <td>{{ row.work_shift.start_time?.slice(0, 5) }}-{{ row.work_shift.end_time?.slice(0, 5) }}</td>
                        <td>
                            {{ formatTime(row.attendance?.first_check_in_at) }}-{{ formatTime(row.attendance?.last_check_out_at) }}
                        </td>
                        <td>
                            <StatusChip :status="row.status" :map="HISTORY_STATUS_MAP" />
                        </td>
                        <td v-if="!readOnly" class="text-end">
                            <v-btn
                                v-if="row.attendance"
                                icon="mdi-eye-outline"
                                variant="tonal"
                                size="small"
                                rounded="lg"
                                @click="openDetail(row)"
                            >
                                <v-icon icon="mdi-eye-outline" />
                                <v-tooltip activator="parent" location="top">Chi tiết</v-tooltip>
                            </v-btn>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>

        <v-dialog v-model="detailDialog" max-width="520">
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Chi tiết chấm công {{ detailTarget ? formatDate(detailTarget.date) : "" }}
                </v-card-title>
                <v-card-text class="px-5">
                    <v-table density="comfortable">
                        <thead>
                            <tr>
                                <th>Thời điểm</th>
                                <th>Sự kiện</th>
                                <th>Phương thức</th>
                                <th>Điểm chấm công</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!detailTarget?.attendance?.logs?.length">
                                <td colspan="4" class="text-center py-4" style="opacity: 0.6">
                                    Không có nhật ký nào.
                                </td>
                            </tr>
                            <tr v-for="log in detailTarget?.attendance?.logs ?? []" v-else :key="log.id">
                                <td>{{ formatDateTime(log.occurred_at) }}</td>
                                <td>{{ log.event_type === "check_in" ? "Vào" : "Ra" }}</td>
                                <td>{{ METHOD_LABEL[log.method] ?? log.method }}</td>
                                <td>{{ log.attendance_location?.name ?? "Không khớp điểm" }}</td>
                            </tr>
                        </tbody>
                    </v-table>
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
// Panel dùng chung cho 2 chỗ: (1) nhân viên tự xem lịch sử CHÍNH MÌNH
// (employeeId=null, readOnly=true — AttendanceHistory.vue), (2) Admin/quản
// lý xem theo TỪNG nhân viên cụ thể kèm nút "Chi tiết" (employeeId=id,
// readOnly=false — tab "Chấm công" trong EmployeeDetail.vue). Chỉ đọc dữ
// liệu, không có thao tác sửa/xóa nào ở đây.
import { computed, onMounted, ref, watch } from "vue";
import attendanceService from "../../services/attendanceService";
import employeeService from "../../services/employeeService";
import StatCards from "../../components/dashboard/StatCards.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate from "../../components/common/InputDate.vue";

const props = defineProps({
    employeeId: {
        type: [Number, String],
        default: null,
    },
    readOnly: {
        type: Boolean,
        default: true,
    },
});

const HISTORY_STATUS_MAP = {
    full: { label: "Đủ công", color: "success" },
    late: { label: "Đi muộn", color: "warning" },
    insufficient: { label: "Thiếu công", color: "error" },
    absent: { label: "Vắng", color: "default" },
};

const METHOD_LABEL = { wifi: "Wifi", gps: "GPS", qr: "Mã QR" };

const statusOptions = [
    { title: "Tất cả", value: null },
    { title: "Đủ công", value: "full" },
    { title: "Đi muộn", value: "late" },
    { title: "Thiếu công", value: "insufficient" },
    { title: "Vắng", value: "absent" },
];

function formatDate(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

function formatTime(value) {
    if (!value) {
        return "--:--";
    }
    return new Date(value).toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" });
}

function formatDateTime(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleString("vi-VN");
}

function pad2(n) {
    return String(n).padStart(2, "0");
}

// Vài tháng gần đây để chọn nhanh — chọn xong tự set lại dateFrom/dateTo,
// vẫn sửa tay được qua 2 ô InputDate cạnh bên nếu cần khoảng khác.
function buildMonthOptions() {
    const now = new Date();
    const options = [];
    for (let i = 0; i < 12; i += 1) {
        const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
        const value = `${d.getFullYear()}-${pad2(d.getMonth() + 1)}`;
        options.push({ title: `Tháng ${pad2(d.getMonth() + 1)}/${d.getFullYear()}`, value });
    }
    return options;
}

function monthBounds(yyyymm) {
    const [y, m] = yyyymm.split("-").map(Number);
    const lastDay = new Date(y, m, 0).getDate();
    return { from: `${yyyymm}-01`, to: `${yyyymm}-${pad2(lastDay)}` };
}

const monthOptions = buildMonthOptions();
const monthValue = ref(monthOptions[0].value);
const initialBounds = monthBounds(monthValue.value);
const dateFrom = ref(initialBounds.from);
const dateTo = ref(initialBounds.to);
const workShiftId = ref(null);
const statusFilter = ref(null);

watch(monthValue, (value) => {
    const bounds = monthBounds(value);
    dateFrom.value = bounds.from;
    dateTo.value = bounds.to;
});

const shiftOptions = ref([]);

async function loadShiftOptions() {
    try {
        const response = props.employeeId
            ? await employeeService.shiftAssignments(props.employeeId)
            : await employeeService.myShiftAssignments();
        const seen = new Set();
        shiftOptions.value = response.data
            .filter((a) => {
                if (seen.has(a.work_shift_id)) {
                    return false;
                }
                seen.add(a.work_shift_id);
                return true;
            })
            .map((a) => ({ title: a.work_shift.name, value: a.work_shift_id }));
    } catch {
        shiftOptions.value = [];
    }
}

const summary = ref({ total_work_days: 0, total_work_minutes: 0, late_count: 0, early_leave_count: 0 });
const rows = ref([]);
const loading = ref(false);
const loadError = ref("");

const summaryStats = computed(() => [
    {
        label: "Tổng ngày công",
        value: `${summary.value.total_work_days} ngày`,
        color: "primary",
        icon: "mdi-calendar-check-outline",
    },
    {
        label: "Giờ làm",
        value: `${Math.round((summary.value.total_work_minutes / 60) * 10) / 10}h`,
        color: "info",
        icon: "mdi-clock-outline",
    },
    {
        label: "Đi muộn",
        value: `${summary.value.late_count} lần`,
        color: "warning",
        icon: "mdi-clock-alert-outline",
    },
    {
        label: "Về sớm",
        value: `${summary.value.early_leave_count} lần`,
        color: "error",
        icon: "mdi-exit-run",
    },
]);

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const params = {
            date_from: dateFrom.value,
            date_to: dateTo.value,
            work_shift_id: workShiftId.value || undefined,
            status: statusFilter.value || undefined,
        };
        const response = props.employeeId
            ? await attendanceService.history(props.employeeId, params)
            : await attendanceService.historyMine(params);
        summary.value = response.data.data.summary;
        rows.value = response.data.data.rows;
    } catch (e) {
        rows.value = [];
        loadError.value = e.response?.data?.message ?? "Không thể tải lịch sử chấm công.";
    } finally {
        loading.value = false;
    }
}

const detailDialog = ref(false);
const detailTarget = ref(null);

function openDetail(row) {
    detailTarget.value = row;
    detailDialog.value = true;
}

watch([dateFrom, dateTo, workShiftId, statusFilter], loadData);

onMounted(() => {
    loadShiftOptions();
    loadData();
});
</script>
