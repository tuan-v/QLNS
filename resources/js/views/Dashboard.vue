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

                <v-row class="mt-2" align="stretch">
                    <v-col cols="12" md="3">
                        <v-sheet class="border rounded-lg glass-panel pa-5 h-100 d-flex align-center justify-center">
                            <GaugeChart
                                title="Tỷ lệ đi làm hôm nay"
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
                                    <div class="text-caption" style="opacity: 0.6">Tỷ lệ đi làm — 5 ngày làm việc gần nhất</div>
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
            <template v-else>
                <StatCards :stats="personalStats" />

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
import { computed, onMounted, ref } from "vue";
import dashboardService from "../services/dashboardService";
import { useNotificationStore } from "../stores/useNotificationStore";
import { formatTime } from "../composables/useCheckIn";
import PageHeader from "../components/common/PageHeader.vue";
import StatCards from "../components/dashboard/StatCards.vue";
import GaugeChart from "../components/dashboard/GaugeChart.vue";
import DonutChart from "../components/dashboard/DonutChart.vue";
import AreaTrendChart from "../components/dashboard/AreaTrendChart.vue";

const notificationStore = useNotificationStore();

const dashboard = ref(null);
const loadError = ref("");

async function loadDashboard() {
    loadError.value = "";
    try {
        const response = await dashboardService.get();
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
            label: "Ngày phép còn lại",
            value: d.leave_balance ? `${d.leave_balance.remaining_days}/${d.leave_balance.allocated_days}` : "—",
            icon: "mdi-calendar-check-outline",
            color: "success",
        },
        {
            label: "Đơn đang chờ xử lý",
            value: d.my_pending_leave_count,
            icon: "mdi-calendar-clock-outline",
            color: "warning",
        },
        {
            label: "Trạng thái hôm nay",
            value: d.checked_in_today ? `Đã vào lúc ${formatTime(d.checked_in_at)}` : "Chưa chấm công",
            icon: d.checked_in_today ? "mdi-check-circle-outline" : "mdi-clock-alert-outline",
            color: d.checked_in_today ? "success" : "default",
        },
    ];
});

onMounted(() => {
    loadDashboard();
    notificationStore.fetchList();
});
</script>
