<template>
    <div>
        <PageHeader title="Báo cáo" :subtitle="subtitle">
            <template #actions>
                <v-btn
                    variant="flat"
                    prepend-icon="mdi-refresh"
                    :loading="loading"
                    :disabled="noScope"
                    @click="load(true)"
                    >Làm mới</v-btn
                >
                <v-btn
                    color="primary"
                    prepend-icon="mdi-microsoft-excel"
                    :loading="exporting"
                    :disabled="noScope || !data"
                    @click="exportExcel"
                    >Xuất Excel</v-btn
                >
            </template>
        </PageHeader>

        <!-- Manager có quyền xem báo cáo nhưng chưa được gán làm trưởng phòng ban nào -->
        <v-alert v-if="noScope" type="info" variant="tonal" class="mb-4">
            Tài khoản của bạn chưa được gán làm trưởng phòng ban nào nên chưa có số liệu để xem. Liên hệ bộ phận Nhân sự
            nếu bạn cần quản lý một phòng ban.
        </v-alert>

        <!-- Bộ lọc -->
        <v-sheet v-if="!noScope" class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
            <div class="filters">
                <v-select
                    v-model="preset"
                    :items="PRESETS"
                    label="Khoảng thời gian"
                    density="compact"
                    hide-details
                    class="filters__item"
                />
                <template v-if="preset === 'custom'">
                    <v-select
                        v-model="from"
                        :items="monthOptions"
                        label="Từ tháng"
                        density="compact"
                        hide-details
                        class="filters__month"
                    />
                    <v-select
                        v-model="to"
                        :items="monthOptions"
                        label="Đến tháng"
                        density="compact"
                        hide-details
                        class="filters__month"
                    />
                </template>
                <v-select
                    v-model="departmentId"
                    :items="departmentOptions"
                    :label="filterInfo.scope === 'company' ? 'Phòng ban' : 'Phòng ban bạn quản lý'"
                    density="compact"
                    hide-details
                    clearable
                    class="filters__item"
                />
                <v-btn-toggle v-model="group" mandatory density="compact" variant="outlined" divided color="primary">
                    <v-btn value="month">Theo tháng</v-btn>
                    <v-btn value="quarter">Theo quý</v-btn>
                </v-btn-toggle>
            </div>
            <div v-if="rangeError" class="text-error text-caption mt-2">{{ rangeError }}</div>
        </v-sheet>

        <v-tabs v-if="!noScope" v-model="tab" color="primary" class="mb-4">
            <v-tab value="workforce">Nhân sự</v-tab>
            <v-tab value="attendance">Chấm công & nghỉ phép</v-tab>
            <v-tab v-if="filterInfo.can_view_payroll" value="payroll">Chi phí lương</v-tab>
        </v-tabs>

        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">{{ error }}</v-alert>
        <v-progress-linear v-if="loading && !data" indeterminate color="primary" class="mb-4" />

        <!-- ================= Nhân sự ================= -->
        <template v-if="tab === 'workforce' && data">
            <StatCards :stats="workforceStats" />
            <v-row class="mt-2">
                <v-col cols="12" md="8">
                    <v-sheet class="border rounded-lg glass-panel pa-5 h-100">
                        <div class="card-title">Tuyển mới và nghỉ việc</div>
                        <ReportChart
                            :categories="labels"
                            :series="[
                                { name: 'Tuyển mới', data: data.periods.map((p) => p.hires) },
                                { name: 'Nghỉ việc', data: data.periods.map((p) => p.leavers) },
                            ]"
                            :colors="['#12B76A', '#F04438']"
                        />
                    </v-sheet>
                </v-col>
                <v-col cols="12" md="4">
                    <v-sheet class="border rounded-lg glass-panel pa-5 h-100">
                        <div class="card-title">Nhân viên hiện tại theo phòng ban</div>
                        <DonutChart
                            v-if="data.by_department.length"
                            :labels="data.by_department.map((d) => d.department)"
                            :data="data.by_department.map((d) => d.headcount)"
                            total-label="Nhân viên"
                        />
                        <div v-else class="empty">Chưa có dữ liệu.</div>
                    </v-sheet>
                </v-col>
            </v-row>
            <v-sheet class="border rounded-lg glass-panel pa-5 mt-4">
                <div class="card-title">Số nhân viên cuối kỳ</div>
                <ReportChart
                    type="line"
                    :categories="labels"
                    :series="[{ name: 'Số nhân viên', data: data.periods.map((p) => p.headcount) }]"
                    :height="240"
                />
            </v-sheet>
            <ReportTable class="mt-4" :headers="workforceHeaders" :items="data.periods" />
        </template>

        <!-- ================= Chấm công & nghỉ phép ================= -->
        <template v-if="tab === 'attendance' && data">
            <StatCards :stats="attendanceStats" />
            <v-sheet class="border rounded-lg glass-panel pa-5 mt-4">
                <div class="card-title">Tỷ lệ đi làm, nghỉ phép, vắng không phép</div>
                <div class="card-note">
                    Tính trên số ngày lẽ ra phải đi làm (ngày có công + nghỉ phép + vắng), khớp với bảng chấm công
                    tháng.
                </div>
                <ReportChart
                    :categories="labels"
                    :series="[
                        { name: 'Đi làm', data: data.periods.map((p) => p.attendance_rate) },
                        { name: 'Nghỉ phép', data: data.periods.map((p) => p.leave_rate) },
                        { name: 'Vắng không phép', data: data.periods.map((p) => p.absence_rate) },
                    ]"
                    format="percent"
                    stacked
                    :colors="['#465FFF', '#F79009', '#F04438']"
                />
            </v-sheet>
            <v-row class="mt-2">
                <v-col cols="12" md="6">
                    <v-sheet class="border rounded-lg glass-panel pa-5 h-100">
                        <div class="card-title">Tỷ lệ đi muộn theo kỳ</div>
                        <ReportChart
                            type="line"
                            :categories="labels"
                            :series="[{ name: 'Đi muộn', data: data.periods.map((p) => p.late_rate) }]"
                            format="percent"
                            :height="240"
                            :colors="['#F79009']"
                        />
                    </v-sheet>
                </v-col>
                <v-col cols="12" md="6">
                    <v-sheet class="border rounded-lg glass-panel pa-5 h-100">
                        <div class="card-title">Tỷ lệ đi làm theo phòng ban</div>
                        <ReportChart
                            v-if="data.by_department.length"
                            horizontal
                            :categories="data.by_department.map((d) => d.department)"
                            :series="[{ name: 'Đi làm', data: data.by_department.map((d) => d.attendance_rate) }]"
                            format="percent"
                            :height="Math.max(200, data.by_department.length * 44)"
                        />
                        <div v-else class="empty">Chưa có dữ liệu.</div>
                    </v-sheet>
                </v-col>
            </v-row>
            <ReportTable class="mt-4" :headers="attendanceHeaders" :items="data.periods" />
        </template>

        <!-- ================= Chi phí lương ================= -->
        <template v-if="tab === 'payroll' && data">
            <StatCards :stats="payrollStats" />
            <v-alert
                v-if="data.periods.some((p) => p.has_draft)"
                type="info"
                variant="tonal"
                density="compact"
                class="mt-4"
            >
                Có kỳ lương chưa chốt, số liệu của kỳ đó có thể còn thay đổi.
            </v-alert>
            <v-row class="mt-2">
                <v-col cols="12" md="8">
                    <v-sheet class="border rounded-lg glass-panel pa-5 h-100">
                        <div class="card-title">Chi phí lương theo kỳ</div>
                        <div class="card-note">
                            Tổng chi phí = tổng thu nhập (gross) + bảo hiểm công ty đóng 21,5% lương đóng BH.
                        </div>
                        <ReportChart
                            :categories="labels"
                            :series="[
                                { name: 'Tổng thu nhập', data: data.periods.map((p) => p.gross) },
                                { name: 'BH công ty đóng', data: data.periods.map((p) => p.employer_insurance) },
                            ]"
                            format="money"
                            stacked
                        />
                    </v-sheet>
                </v-col>
                <v-col cols="12" md="4">
                    <v-sheet class="border rounded-lg glass-panel pa-5 h-100">
                        <div class="card-title">Tỷ trọng theo phòng ban</div>
                        <DonutChart
                            v-if="data.by_department.length"
                            :labels="data.by_department.map((d) => d.department)"
                            :data="data.by_department.map((d) => d.total_cost)"
                            total-label="Tổng chi phí"
                            format="money"
                        />
                        <div v-else class="empty">Chưa có bảng lương trong khoảng này.</div>
                    </v-sheet>
                </v-col>
            </v-row>
            <ReportTable class="mt-4" :headers="payrollHeaders" :items="data.by_department" title="Theo phòng ban" />
            <ReportTable class="mt-4" :headers="payrollPeriodHeaders" :items="data.periods" title="Theo kỳ" />
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import reportService from "../../services/reportService";
import PageHeader from "../../components/common/PageHeader.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
import DonutChart from "../../components/dashboard/DonutChart.vue";
import ReportChart from "../../components/report/ReportChart.vue";
import ReportTable from "./ReportTable.vue";
import { useRememberedRef } from "../../composables/useRememberedRef";
import { useToastStore } from "../../stores/useToastStore";

// Module Báo cáo (Kế hoạch Ngày 57-58) — dữ liệu từ ReportService. HR/Admin xem cả công ty;
// Manager chỉ phòng mình quản lý, không có tab Chi phí lương.
const toast = useToastStore();

const PRESETS = [
    { title: "6 tháng gần nhất", value: "6m" },
    { title: "12 tháng gần nhất", value: "12m" },
    { title: "Năm nay", value: "this_year" },
    { title: "Năm trước", value: "last_year" },
    { title: "Tùy chọn", value: "custom" },
];

const pad = (n) => String(n).padStart(2, "0");
const keyOf = (date) => `${date.getFullYear()}-${pad(date.getMonth() + 1)}`;
const now = new Date();
const monthOptions = Array.from({ length: 36 }, (_, i) => {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    return { title: `Tháng ${d.getMonth() + 1}/${d.getFullYear()}`, value: keyOf(d) };
});

const tab = useRememberedRef("reports.tab", "workforce");
const preset = useRememberedRef("reports.preset", "12m");
const group = useRememberedRef("reports.group", "month");
const from = ref(monthOptions[11].value);
const to = ref(monthOptions[0].value);
const departmentId = ref(null);
const filterInfo = ref({ scope: "company", departments: [], can_view_payroll: false });
const data = ref(null);
const loading = ref(false);
const exporting = ref(false);
const error = ref("");

const range = computed(() => {
    const y = now.getFullYear();
    switch (preset.value) {
        case "6m":
            return { from: monthOptions[5].value, to: monthOptions[0].value };
        case "this_year":
            return { from: `${y}-01`, to: monthOptions[0].value };
        case "last_year":
            return { from: `${y - 1}-01`, to: `${y - 1}-12` };
        case "custom":
            return { from: from.value, to: to.value };
        default:
            return { from: monthOptions[11].value, to: monthOptions[0].value };
    }
});
const rangeError = computed(() =>
    range.value.from > range.value.to ? "Tháng bắt đầu phải trước tháng kết thúc." : "",
);

const subtitle = computed(() =>
    filterInfo.value.scope === "company"
        ? "Biến động nhân sự, chấm công, nghỉ phép và chi phí lương."
        : "Số liệu của phòng ban bạn quản lý (và các phòng trực thuộc).",
);

const noScope = computed(() => filterInfo.value.scope === "departments" && !filterInfo.value.departments.length);
const departmentOptions = computed(() => filterInfo.value.departments.map((d) => ({ title: d.name, value: d.id })));
const labels = computed(() => data.value?.periods.map((p) => p.label) ?? []);

const num = (v, digits = 1) => Number(v ?? 0).toLocaleString("vi-VN", { maximumFractionDigits: digits });
const money = (v) => `${Number(v ?? 0).toLocaleString("vi-VN", { maximumFractionDigits: 0 })} ₫`;

const workforceStats = computed(() => {
    const t = data.value.totals;
    return [
        {
            label: "Nhân viên hiện tại",
            value: num(t.headcount, 0),
            icon: "mdi-account-group-outline",
            color: "primary",
        },
        { label: "Tuyển mới trong kỳ", value: num(t.hires, 0), icon: "mdi-account-plus-outline", color: "success" },
        { label: "Nghỉ việc trong kỳ", value: num(t.leavers, 0), icon: "mdi-account-minus-outline", color: "error" },
        { label: "Tỷ lệ nghỉ việc", value: `${num(t.turnover_rate)}%`, icon: "mdi-swap-horizontal", color: "warning" },
    ];
});
const attendanceStats = computed(() => {
    const t = data.value.totals;
    return [
        {
            label: "Tỷ lệ đi làm",
            value: `${num(t.attendance_rate)}%`,
            icon: "mdi-calendar-check-outline",
            color: "primary",
        },
        { label: "Tỷ lệ nghỉ phép", value: `${num(t.leave_rate)}%`, icon: "mdi-beach", color: "info" },
        {
            label: "Vắng không phép",
            value: `${num(t.absence_rate)}%`,
            icon: "mdi-account-cancel-outline",
            color: "error",
        },
        { label: "Tỷ lệ đi muộn", value: `${num(t.late_rate)}%`, icon: "mdi-clock-alert-outline", color: "warning" },
    ];
});
const payrollStats = computed(() => {
    const t = data.value.totals;
    return [
        { label: "Tổng chi phí lương", value: money(t.total_cost), icon: "mdi-cash-multiple", color: "primary" },
        { label: "Tổng thu nhập (gross)", value: money(t.gross), icon: "mdi-cash", color: "success" },
        {
            label: "BH công ty đóng",
            value: money(t.employer_insurance),
            icon: "mdi-shield-check-outline",
            color: "info",
        },
        { label: "Lượt nhận lương", value: num(t.employees, 0), icon: "mdi-account-cash-outline", color: "warning" },
    ];
});

const workforceHeaders = [
    { title: "Kỳ", key: "label" },
    { title: "Số NV cuối kỳ", key: "headcount", kind: "int" },
    { title: "Tuyển mới", key: "hires", kind: "int" },
    { title: "Nghỉ việc", key: "leavers", kind: "int" },
    { title: "Tỷ lệ nghỉ việc", key: "turnover_rate", kind: "pct" },
];
const attendanceHeaders = [
    { title: "Kỳ", key: "label" },
    { title: "Ngày công", key: "work_days", kind: "num" },
    { title: "Nghỉ có lương", key: "paid_leave_days", kind: "num" },
    { title: "Nghỉ không lương", key: "unpaid_leave_days", kind: "num" },
    { title: "Vắng", key: "absent_days", kind: "num" },
    { title: "Đi làm", key: "attendance_rate", kind: "pct" },
    { title: "Đi muộn", key: "late_rate", kind: "pct" },
];
const payrollHeaders = [
    { title: "Phòng ban", key: "department" },
    { title: "Lượt nhận lương", key: "employees", kind: "int" },
    { title: "Tổng thu nhập", key: "gross", kind: "money" },
    { title: "BH công ty", key: "employer_insurance", kind: "money" },
    { title: "Tổng chi phí", key: "total_cost", kind: "money" },
];
const payrollPeriodHeaders = [{ title: "Kỳ", key: "label" }, ...payrollHeaders.slice(1)];

function params(fresh = false) {
    return {
        ...range.value,
        group: group.value,
        department_id: departmentId.value || undefined,
        fresh: fresh ? 1 : undefined,
    };
}

async function load(fresh = false) {
    if (rangeError.value || noScope.value) return;
    loading.value = true;
    error.value = "";
    try {
        const call = {
            workforce: reportService.workforce,
            attendance: reportService.attendance,
            payroll: reportService.payrollCost,
        }[tab.value];
        data.value = (await call(params(fresh))).data;
    } catch (e) {
        data.value = null;
        error.value = e.response?.data?.message ?? "Không tải được báo cáo.";
    } finally {
        loading.value = false;
    }
}

async function exportExcel() {
    exporting.value = true;
    try {
        const response = await reportService.export({ ...params(), type: tab.value });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement("a");
        link.href = url;
        link.download = `bao-cao-${tab.value}-${range.value.from}-den-${range.value.to}.xlsx`;
        link.click();
        URL.revokeObjectURL(url);
    } catch {
        toast.error("Không xuất được file Excel.");
    } finally {
        exporting.value = false;
    }
}

watch([tab, preset, from, to, group, departmentId], () => {
    data.value = null;
    load();
});

onMounted(async () => {
    try {
        filterInfo.value = (await reportService.filters()).data;
    } catch {
        // Bộ lọc phòng ban không bắt buộc — vẫn tải báo cáo.
    }
    if (tab.value === "payroll" && !filterInfo.value.can_view_payroll) tab.value = "workforce";
    load();
});
</script>

<style scoped>
.filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}
.filters__item {
    max-width: 240px;
    min-width: 180px;
}
.filters__month {
    max-width: 180px;
    min-width: 150px;
}
.card-title {
    font-size: 0.9375rem;
    font-weight: 600;
    margin-bottom: 4px;
}
.card-note {
    font-size: 12.5px;
    color: rgb(var(--v-theme-ink-muted));
    margin-bottom: 8px;
}
.empty {
    padding: 48px 0;
    text-align: center;
    color: rgb(var(--v-theme-ink-muted));
}
@media (max-width: 600px) {
    .filters__item,
    .filters__month {
        max-width: none;
        flex: 1 1 100%;
    }
}
</style>
