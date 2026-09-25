<template>
    <div>
        <PageHeader
            title="Tổng quan"
            :subtitle="dashboard?.scope === 'personal'
                ? 'Thông tin chấm công và nghỉ phép của bạn.'
                : 'Số liệu nhân sự cập nhật tới hôm nay.'"
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

        <template v-if="dashboard">
            <!-- ===== Company-wide (HR/Manager/Admin — có quyền employee.view) ===== -->
            <template v-if="dashboard.scope === 'company'">
                <StatCards :stats="companyStats" />

                <!-- "Cần bạn xử lý" (2026-09-25, demo theo ý tưởng gộp mọi việc chờ
                     duyệt vào 1 khối — khớp Ke-hoach Ngày 59) — mỗi mục đã lọc theo
                     đúng quyền của user ở DashboardService::actionItems(), tự ẩn
                     nếu rỗng nên không cần v-if riêng cho từng mục con. -->
                <v-row v-if="dashboard.action_items.length > 0" class="mt-2">
                    <v-col cols="12">
                        <v-sheet class="border rounded-lg glass-panel pa-4">
                            <div class="d-flex align-center ga-2 mb-3">
                                <v-icon size="18" color="warning">mdi-clipboard-alert-outline</v-icon>
                                <span class="text-body-1 font-weight-bold">Cần bạn xử lý</span>
                            </div>
                            <div class="d-flex flex-wrap ga-3">
                                <v-sheet
                                    v-for="item in dashboard.action_items"
                                    :key="item.type"
                                    class="action-item-card border rounded-lg pa-3 d-flex align-center ga-3"
                                    @click="router.push({ name: item.route, query: item.route_query })"
                                >
                                    <v-avatar color="warning" variant="tonal" rounded="lg" size="40">
                                        <v-icon :icon="ACTION_ICON[item.type]" size="20" />
                                    </v-avatar>
                                    <div>
                                        <div class="text-h6 font-weight-bold">{{ item.count }}</div>
                                        <div class="text-caption" style="opacity: 0.7">{{ item.label }}</div>
                                    </div>
                                </v-sheet>
                            </div>
                        </v-sheet>
                    </v-col>
                </v-row>

                <!-- Bộ lọc ngày (2026-09-25, theo yêu cầu người dùng) — CHỈ ảnh hưởng
                     3 widget bên dưới ("Tỷ lệ đi làm"/"Xu hướng chấm công"/"Phân bổ
                     phòng ban"), KHÔNG ảnh hưởng "Cần bạn xử lý" hay Thông báo (2 cái
                     đó luôn tính theo hiện tại — xem DashboardService::forUser()). -->
                <div class="d-flex align-center justify-space-between mt-4 mb-1 flex-wrap ga-3">
                    <div class="text-caption" style="opacity: 0.65">
                        3 biểu đồ bên dưới theo ngày đã chọn — không ảnh hưởng "Cần bạn xử lý"/thông báo.
                    </div>
                    <!-- 170px trước đây quá hẹp — ô nhập kiểu VDateInput cần chỗ cho
                         icon lịch + "dd/mm/yyyy" + nút xóa, bị cắt chữ mất số cuối
                         (2026-09-25, người dùng chụp màn hình chỉ ra). `flex-shrink:
                         0` để ô không bị bóp nhỏ lại khi caption bên trái dài. -->
                    <div style="width: 190px; flex-shrink: 0">
                        <InputDate v-model="statsDate" :max="todayIso()" hide-details />
                    </div>
                </div>

                <v-row class="mt-2" align="stretch">
                    <v-col cols="12" md="3">
                        <v-sheet class="border rounded-lg glass-panel pa-5 h-100 d-flex align-center justify-center">
                            <GaugeChart
                                :title="statsDate === todayIso() ? 'Tỷ lệ đi làm hôm nay' : `Tỷ lệ đi làm ngày ${formatDate(statsDate)}`"
                                :value="dashboard.attendance_today.rate"
                                :sub="`${dashboard.attendance_today.present}/${dashboard.attendance_today.total} nhân viên`"
                                color="primary"
                            />
                        </v-sheet>
                    </v-col>

                    <v-col cols="12" md="3">
                        <v-sheet class="border rounded-lg glass-panel pa-5 h-100">
                            <div class="text-body-2 font-weight-bold mb-1">Phân bổ theo phòng ban</div>
                            <DonutChart
                                v-if="dashboard.department_distribution.length > 0"
                                :labels="dashboard.department_distribution.map((d) => d.name)"
                                :data="dashboard.department_distribution.map((d) => d.count)"
                                total-label="Nhân viên"
                            />
                            <div v-else class="text-center py-8" style="opacity: 0.6">Chưa có dữ liệu</div>
                        </v-sheet>
                    </v-col>

                    <v-col cols="12" md="6">
                        <v-sheet class="border rounded-lg glass-panel pa-0 h-100 d-flex flex-column">
                            <div class="d-flex align-center justify-space-between px-5 pt-4 pb-3">
                                <div class="d-flex align-center ga-2">
                                    <v-icon size="18" style="opacity: 0.7">mdi-bell-outline</v-icon>
                                    <span class="text-body-1 font-weight-bold">Thông báo gần đây</span>
                                </div>
                                <v-chip v-if="notificationStore.unreadCount > 0" size="small" color="primary" variant="tonal">
                                    {{ notificationStore.unreadCount }} mới
                                </v-chip>
                            </div>
                            <v-divider />

                            <div v-if="notificationStore.notifications.length === 0" class="text-center py-8" style="opacity: 0.6">
                                Chưa có thông báo nào
                            </div>
                            <div v-else style="overflow-y: auto; max-height: 260px;">
                                <div
                                    v-for="item in notificationStore.notifications.slice(0, 5)"
                                    :key="item.id"
                                    class="px-5 py-3 border-b"
                                >
                                    <div class="d-flex ga-2">
                                        <span
                                            class="mt-2 flex-shrink-0"
                                            style="width:7px; height:7px; border-radius:50%;"
                                            :class="item.read_at ? 'bg-grey' : 'bg-primary'"
                                        />
                                        <div style="min-width: 0">
                                            <div class="text-body-2" :class="{ 'font-weight-bold': !item.read_at }">
                                                {{ item.title }}
                                            </div>
                                            <div class="text-caption text-truncate" style="opacity: 0.65">{{ item.message }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <v-spacer />
                            <v-btn variant="text" size="small" class="ma-2" :to="{ name: 'notifications' }">
                                Xem tất cả thông báo
                            </v-btn>
                        </v-sheet>
                    </v-col>
                </v-row>

                <v-row class="mt-2">
                    <v-col cols="12">
                        <v-sheet class="border rounded-lg glass-panel pa-5">
                            <div class="d-flex align-center justify-space-between mb-2">
                                <div>
                                    <div class="text-body-1 font-weight-bold">Xu hướng chấm công</div>
                                    <div class="text-caption" style="opacity: 0.6">Tỷ lệ đi làm — 5 ngày làm việc gần nhất, tính tới ngày đã chọn</div>
                                </div>
                            </div>
                            <AreaTrendChart
                                :categories="dashboard.attendance_trend.map((d) => d.label)"
                                :data="dashboard.attendance_trend.map((d) => d.rate)"
                            />
                        </v-sheet>
                    </v-col>
                </v-row>
            </template>

            <!-- ===== Cá nhân (Employee — không có employee.view) ===== -->
            <!-- 2026-09-25, theo yêu cầu người dùng (kèm mockup cụ thể): mở
                 rộng từ 3 thẻ số liệu + thông báo thành đủ mảng lịch làm việc/
                 công tháng này/lương/đơn của tôi — xem DashboardService::personal(). -->
            <template v-else>
                <div class="text-h6 font-weight-bold mb-3">
                    Xin chào {{ dashboard.employee_name }}
                </div>

                <v-row align="stretch">
                    <v-col cols="12" md="5">
                        <PersonalCheckInCard />
                    </v-col>
                    <v-col cols="12" md="7">
                        <StatCards :stats="personalStats" />
                        <v-sheet class="border rounded-lg glass-panel pa-4 mt-2 d-flex align-center justify-space-between flex-wrap ga-3">
                            <div>
                                <div class="text-caption" style="opacity: 0.65">Lương gần nhất</div>
                                <div class="text-h6 font-weight-bold">
                                    {{ dashboard.latest_payslip
                                        ? `${formatMoney(dashboard.latest_payslip.net_salary)} (kỳ ${dashboard.latest_payslip.period_month}/${dashboard.latest_payslip.period_year})`
                                        : "Chưa có phiếu lương" }}
                                </div>
                            </div>
                            <v-btn
                                v-if="dashboard.latest_payslip"
                                color="primary"
                                variant="tonal"
                                :to="{ name: 'my-profile', query: { tab: 'payslips' } }"
                            >
                                Xem phiếu lương
                            </v-btn>
                        </v-sheet>
                    </v-col>
                </v-row>

                <v-row class="mt-2">
                    <v-col cols="12">
                        <v-sheet class="border rounded-lg glass-panel pa-4">
                            <div class="text-body-1 font-weight-bold mb-3">Lịch làm việc tuần này</div>
                            <div class="d-flex ga-2" style="overflow-x: auto">
                                <v-sheet
                                    v-for="day in dashboard.week_schedule"
                                    :key="day.date"
                                    class="border rounded-lg pa-3 text-center flex-shrink-0"
                                    :class="day.is_today ? 'bg-primary' : ''"
                                    style="min-width: 108px"
                                >
                                    <div class="text-caption font-weight-bold">{{ day.label }}</div>
                                    <div class="text-caption" :style="{ opacity: day.is_today ? 0.85 : 0.6 }">
                                        {{ formatDate(day.date) }}
                                    </div>
                                    <div v-if="day.shifts.length" class="text-caption mt-1">
                                        <div v-for="shift in day.shifts" :key="shift.name">
                                            {{ shift.start_time?.slice(0, 5) }}-{{ shift.end_time?.slice(0, 5) }}
                                        </div>
                                    </div>
                                    <div v-else class="text-caption mt-1" style="opacity: 0.5">Nghỉ</div>
                                </v-sheet>
                            </div>
                        </v-sheet>
                    </v-col>
                </v-row>

                <v-row class="mt-2" align="stretch">
                    <v-col cols="12" md="6">
                        <v-sheet class="border rounded-lg glass-panel pa-0 h-100">
                            <div class="px-4 pt-4 pb-3 text-body-1 font-weight-bold">Bảng công gần nhất</div>
                            <v-divider />
                            <div v-if="dashboard.recent_attendance.length === 0" class="text-center py-8" style="opacity: 0.6">
                                Chưa có dữ liệu.
                            </div>
                            <div v-else>
                                <div
                                    v-for="row in dashboard.recent_attendance"
                                    :key="row.date"
                                    class="d-flex align-center justify-space-between px-4 py-3 border-b"
                                >
                                    <div>
                                        <div class="text-body-2 font-weight-medium">{{ formatDate(row.date) }}</div>
                                        <div class="text-caption" style="opacity: 0.65">{{ row.work_shift_name }}</div>
                                    </div>
                                    <StatusChip :status="row.status" :map="HISTORY_STATUS_MAP" />
                                </div>
                            </div>
                        </v-sheet>
                    </v-col>

                    <v-col cols="12" md="6">
                        <v-sheet class="border rounded-lg glass-panel pa-0 h-100">
                            <div class="px-4 pt-4 pb-3 text-body-1 font-weight-bold">Đơn của tôi</div>
                            <v-divider />
                            <div v-if="dashboard.recent_requests.length === 0" class="text-center py-8" style="opacity: 0.6">
                                Chưa có đơn nào.
                            </div>
                            <div v-else>
                                <div
                                    v-for="(item, index) in dashboard.recent_requests"
                                    :key="index"
                                    class="d-flex align-center justify-space-between px-4 py-3 border-b"
                                >
                                    <div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ item.kind === "leave" ? item.label : ADJUSTMENT_TYPE_LABELS[item.adjustment_type] ?? item.adjustment_type }}
                                        </div>
                                        <div class="text-caption" style="opacity: 0.65">{{ formatDate(item.date) }}</div>
                                    </div>
                                    <StatusChip :status="item.status" :map="REQUEST_STATUS_MAP" />
                                </div>
                            </div>
                        </v-sheet>
                    </v-col>
                </v-row>

                <v-row class="mt-2">
                    <v-col cols="12">
                        <v-sheet class="border rounded-lg glass-panel pa-0">
                            <div class="d-flex align-center justify-space-between px-5 pt-4 pb-3">
                                <div class="d-flex align-center ga-2">
                                    <v-icon size="18" style="opacity: 0.7">mdi-bell-outline</v-icon>
                                    <span class="text-body-1 font-weight-bold">Thông báo gần đây</span>
                                </div>
                                <v-chip v-if="notificationStore.unreadCount > 0" size="small" color="primary" variant="tonal">
                                    {{ notificationStore.unreadCount }} mới
                                </v-chip>
                            </div>
                            <v-divider />

                            <div v-if="notificationStore.notifications.length === 0" class="text-center py-8" style="opacity: 0.6">
                                Chưa có thông báo nào
                            </div>
                            <div v-else>
                                <div
                                    v-for="item in notificationStore.notifications.slice(0, 6)"
                                    :key="item.id"
                                    class="px-5 py-3 border-b"
                                >
                                    <div class="d-flex ga-2">
                                        <span
                                            class="mt-2 flex-shrink-0"
                                            style="width:7px; height:7px; border-radius:50%;"
                                            :class="item.read_at ? 'bg-grey' : 'bg-primary'"
                                        />
                                        <div style="min-width: 0">
                                            <div class="text-body-2" :class="{ 'font-weight-bold': !item.read_at }">
                                                {{ item.title }}
                                            </div>
                                            <div class="text-caption" style="opacity: 0.65">{{ item.message }}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <v-btn variant="text" size="small" class="ma-2" :to="{ name: 'notifications' }">
                                Xem tất cả thông báo
                            </v-btn>
                        </v-sheet>
                    </v-col>
                </v-row>
            </template>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import dashboardService from "../services/dashboardService";
import { useAuthStore } from "../stores/authStore";
import { useNotificationStore } from "../stores/useNotificationStore";
import { useAttendanceFeedStore } from "../stores/useAttendanceFeedStore";
import { useLeaveFeedStore } from "../stores/useLeaveFeedStore";
import { useResourceSyncStore } from "../stores/useResourceSyncStore";
import { formatDate } from "../composables/useCheckIn";
import PageHeader from "../components/common/PageHeader.vue";
import StatCards from "../components/dashboard/StatCards.vue";
import GaugeChart from "../components/dashboard/GaugeChart.vue";
import DonutChart from "../components/dashboard/DonutChart.vue";
import AreaTrendChart from "../components/dashboard/AreaTrendChart.vue";
import PersonalCheckInCard from "../components/dashboard/PersonalCheckInCard.vue";
import StatusChip from "../components/common/StatusChip.vue";
import InputDate, { todayIso } from "../components/common/InputDate.vue";

// "Bảng công gần nhất" — cùng nhãn/màu với AttendanceHistoryPanel.vue (không
// có sẵn 1 nơi export dùng chung, chấp nhận lặp lại 1 object nhỏ).
const HISTORY_STATUS_MAP = {
    full: { label: "Đủ công", color: "success" },
    late: { label: "Đi muộn", color: "warning" },
    insufficient: { label: "Thiếu công", color: "error" },
    absent: { label: "Vắng", color: "default" },
    on_leave: { label: "Nghỉ phép", color: "info" },
};

// "Đơn của tôi" — 2 nguồn khác nhau (đơn nghỉ phép/điều chỉnh công) dùng
// CHUNG 1 vùng trạng thái hiển thị, dù đơn nghỉ phép có thêm 1 trạng thái
// trung gian (manager_approved) mà điều chỉnh công không có.
const REQUEST_STATUS_MAP = {
    pending: { label: "Chờ duyệt", color: "warning" },
    manager_approved: { label: "Chờ HR duyệt", color: "info" },
    approved: { label: "Đã duyệt", color: "success" },
    rejected: { label: "Từ chối", color: "default" },
};

// Nhãn hiển thị theo type — cùng nội dung ADJUSTMENT_TYPE_MAP ở
// AttendanceAdjustments.vue (mục 17), chỉ lấy phần label vì màu đã dùng cho
// trạng thái duyệt ở REQUEST_STATUS_MAP thay vì loại đơn.
const ADJUSTMENT_TYPE_LABELS = {
    correction: "Điều chỉnh công",
    supplement: "Bổ sung chấm công",
    excuse: "Miễn trừ đi muộn",
    overtime: "Xin duyệt OT",
    extra_shift: "Làm ngoài lịch",
};

function formatMoney(value) {
    return new Intl.NumberFormat("vi-VN").format(Number(value)) + " ₫";
}

const router = useRouter();
const auth = useAuthStore();
const notificationStore = useNotificationStore();
const attendanceFeed = useAttendanceFeedStore();
const leaveFeed = useLeaveFeedStore();
const resourceSync = useResourceSyncStore();

const ACTION_ICON = {
    attendance_approval: "mdi-clock-check-outline",
    leave_approval: "mdi-calendar-alert-outline",
    attendance_adjustment: "mdi-file-document-edit-outline",
};

const dashboard = ref(null);
const loadError = ref("");
// Chỉ ảnh hưởng 3 widget số liệu — xem chú thích trên đầu khối 3 widget
// trong template (2026-09-25, theo yêu cầu người dùng).
const statsDate = ref(todayIso());

async function loadDashboard() {
    loadError.value = "";
    try {
        const response = await dashboardService.get(statsDate.value);
        dashboard.value = response.data;
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không thể tải dữ liệu tổng quan.";
    }
}

const companyStats = computed(() => {
    if (!dashboard.value) {
        return [];
    }
    const d = dashboard.value;
    return [
        { label: "Tổng nhân viên", value: d.employee_stats.total, icon: "mdi-account-group-outline", color: "primary" },
        { label: "Đang làm việc", value: d.employee_stats.active, icon: "mdi-check-circle-outline", color: "success" },
        { label: "Nhân viên mới tháng này", value: d.employee_stats.new_this_month, icon: "mdi-account-plus-outline", color: "info" },
        { label: "Đơn nghỉ phép chờ duyệt", value: d.leave_pending.total, icon: "mdi-calendar-alert-outline", color: "warning" },
    ];
});

const personalStats = computed(() => {
    if (!dashboard.value) {
        return [];
    }
    const d = dashboard.value;
    return [
        {
            label: "Công tháng này",
            value: d.monthly_work ? `${d.monthly_work.actual_days}/${d.monthly_work.standard_days}` : "—",
            icon: "mdi-calendar-check-outline",
            color: "primary",
        },
        {
            label: "Ngày phép còn lại",
            value: d.leave_balance ? `${d.leave_balance.remaining_days}/${d.leave_balance.allocated_days}` : "—",
            icon: "mdi-beach-outline",
            color: "success",
        },
        {
            label: "Đơn đang chờ xử lý",
            value: d.my_pending_leave_count,
            icon: "mdi-calendar-clock-outline",
            color: "warning",
        },
    ];
});

// "Cần bạn xử lý" tự làm mới khi có người KHÁC vừa duyệt/nộp — tái dùng
// đúng 3 tín hiệu realtime đã có (mục 34 CODE_MAP), không cần thêm sự kiện
// mới nào. attendanceFeed/leaveFeed đã kết nối SẴN theo phiên đăng nhập
// (App.vue) nên chỉ cần watch(); resourceSync là store page-scoped, phải tự
// connect()/disconnect() ở đây — chỉ connect nếu user thật sự có quyền
// attendance.adjust (khớp điều kiện DashboardService::actionItems()), tránh
// join kênh vô ích cho người không có mục này.
watch(
    () => [attendanceFeed.approvalSignal, leaveFeed.signal, resourceSync.signals.attendance_adjustments],
    () => loadDashboard(),
);

watch(statsDate, loadDashboard);

onMounted(() => {
    loadDashboard();
    notificationStore.fetchList();
    if (auth.permissions.includes("attendance.adjust")) {
        resourceSync.connect("attendance_adjustments");
    }
});

onUnmounted(() => {
    resourceSync.disconnect("attendance_adjustments");
});
</script>

<style scoped>
.action-item-card {
    cursor: pointer;
    min-width: 220px;
    transition:
        transform 0.15s ease,
        box-shadow 0.15s ease;
}
.action-item-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.1);
}
</style>
