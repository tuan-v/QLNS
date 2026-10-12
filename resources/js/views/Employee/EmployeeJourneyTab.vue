<template>
    <div class="d-flex flex-column ga-4">
        <div v-if="loading" class="d-flex justify-center py-8"><v-progress-circular indeterminate /></div>
        <v-alert v-else-if="loadError" type="error" variant="tonal" density="compact">{{ loadError }}</v-alert>

        <template v-else-if="journey">
            <!-- Tóm tắt -->
            <div class="summary-grid">
                <v-sheet class="border rounded-lg glass-panel pa-4" color="transparent">
                    <div class="text-caption" style="opacity: 0.7">Thâm niên</div>
                    <div class="text-h6 font-weight-bold">{{ tenureLabel }}</div>
                    <div class="text-caption" style="opacity: 0.7">
                        Vào làm {{ formatDate(summary.hire_date) }}
                        <template v-if="summary.termination_date"> · nghỉ {{ formatDate(summary.termination_date) }}</template>
                    </div>
                </v-sheet>
                <v-sheet class="border rounded-lg glass-panel pa-4" color="transparent">
                    <div class="text-caption" style="opacity: 0.7">Lương khởi điểm → hiện tại</div>
                    <div class="text-h6 font-weight-bold">
                        <template v-if="summary.current_salary != null">{{ money(summary.current_salary) }}</template>
                        <template v-else>—</template>
                    </div>
                    <div v-if="summary.starting_salary != null" class="text-caption d-flex align-center ga-1">
                        <span style="opacity: 0.7">từ {{ money(summary.starting_salary) }}</span>
                        <ChangeChip :amount="summary.total_change_amount" :percent="summary.total_change_percent" />
                    </div>
                </v-sheet>
                <v-sheet class="border rounded-lg glass-panel pa-4" color="transparent">
                    <div class="text-caption" style="opacity: 0.7">Vị trí hiện tại</div>
                    <div class="text-h6 font-weight-bold text-truncate">{{ summary.position || "—" }}</div>
                    <div class="text-caption" style="opacity: 0.7">{{ summary.department || "—" }} · {{ summary.contracts_count }} hợp đồng</div>
                </v-sheet>
                <v-sheet class="border rounded-lg glass-panel pa-4" color="transparent">
                    <div class="text-caption" style="opacity: 0.7">Nguồn tuyển dụng</div>
                    <template v-if="summary.recruitment">
                        <div class="text-h6 font-weight-bold text-truncate">{{ summary.recruitment.opening }}</div>
                        <div class="text-caption" style="opacity: 0.7">
                            Nộp CV {{ formatDate(summary.recruitment.applied_at) }}
                            <template v-if="summary.recruitment.score_percent != null"> · điểm PV {{ summary.recruitment.score_percent }}%</template>
                            <template v-if="summary.recruitment.offer_salary != null"> · offer {{ money(summary.recruitment.offer_salary) }}</template>
                        </div>
                    </template>
                    <template v-else>
                        <div class="text-h6 font-weight-bold">Nhập trực tiếp</div>
                        <div class="text-caption" style="opacity: 0.7">Không qua module Tuyển dụng</div>
                    </template>
                </v-sheet>
            </div>

            <!-- Biểu đồ lương -->
            <v-sheet class="border rounded-lg glass-panel pa-4" color="transparent">
                <div class="text-subtitle-1 font-weight-bold mb-1">Diễn biến lương</div>
                <SalaryJourneyChart :salary-history="journey.salary_history" :payslips="journey.payslips" />
            </v-sheet>

            <v-row>
                <!-- Các giai đoạn lương theo hợp đồng -->
                <v-col cols="12" md="6">
                    <v-sheet class="border rounded-lg glass-panel h-100" color="transparent">
                        <div class="text-subtitle-1 font-weight-bold pa-4 pb-0">Các giai đoạn lương (theo hợp đồng)</div>
                        <v-table density="comfortable">
                            <thead>
                                <tr><th>Giai đoạn</th><th>Loại HĐ</th><th class="text-right">Lương</th><th class="text-right">Thay đổi</th></tr>
                            </thead>
                            <tbody>
                                <tr v-if="!journey.salary_history.length"><td colspan="4" class="text-center py-4" style="opacity: 0.6">Chưa có hợp đồng.</td></tr>
                                <tr v-for="row in [...journey.salary_history].reverse()" :key="row.contract_id">
                                    <td>
                                        <div class="text-no-wrap">{{ formatDate(row.start_date) }} → {{ row.end_date ? formatDate(row.end_date) : "nay" }}</div>
                                        <div class="text-caption" style="opacity: 0.6">{{ row.contract_number }} · {{ CONTRACT_STATUS[row.status] ?? row.status }}</div>
                                    </td>
                                    <td>{{ CONTRACT_TYPE_LABELS[row.contract_type] ?? row.contract_type }}</td>
                                    <td class="text-right font-weight-medium text-no-wrap">{{ money(row.salary) }}</td>
                                    <td class="text-right text-no-wrap">
                                        <ChangeChip v-if="row.change_amount !== null" :amount="row.change_amount" :percent="row.change_percent" />
                                        <span v-else class="text-caption" style="opacity: 0.6">Khởi điểm</span>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-sheet>
                </v-col>
                <!-- Lương thực nhận từng kỳ -->
                <v-col cols="12" md="6">
                    <v-sheet class="border rounded-lg glass-panel h-100" color="transparent">
                        <div class="text-subtitle-1 font-weight-bold pa-4 pb-0">Lương thực nhận hằng tháng</div>
                        <v-table density="comfortable">
                            <thead>
                                <tr><th>Kỳ</th><th class="text-center">Công</th><th class="text-right">Tổng thu nhập</th><th class="text-right">Thực nhận</th></tr>
                            </thead>
                            <tbody>
                                <tr v-if="!journey.payslips.length"><td colspan="4" class="text-center py-4" style="opacity: 0.6">Chưa có kỳ lương nào.</td></tr>
                                <tr v-for="p in [...journey.payslips].reverse()" :key="p.period">
                                    <td class="text-no-wrap">
                                        {{ p.period }}
                                        <v-chip v-if="p.payroll_status !== 'closed'" size="x-small" variant="outlined" class="ml-1">Chưa chốt</v-chip>
                                    </td>
                                    <td class="text-center">{{ p.actual_work_days }}/{{ p.standard_work_days }}</td>
                                    <td class="text-right text-no-wrap">{{ money(p.gross_salary) }}</td>
                                    <td class="text-right font-weight-bold text-no-wrap">{{ money(p.net_salary) }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </v-sheet>
                </v-col>
            </v-row>

            <!-- Dòng thời gian -->
            <v-sheet class="border rounded-lg glass-panel pa-4" color="transparent">
                <div class="d-flex flex-wrap align-center ga-2 mb-3">
                    <div class="text-subtitle-1 font-weight-bold flex-grow-1">Dòng thời gian</div>
                    <v-chip-group v-model="groupFilter" selected-class="text-primary" mandatory>
                        <v-chip value="all" size="small" variant="outlined">Tất cả</v-chip>
                        <v-chip v-for="(meta, key) in GROUPS" :key="key" :value="key" size="small" variant="outlined" :prepend-icon="meta.icon">
                            {{ meta.label }}
                        </v-chip>
                    </v-chip-group>
                </div>
                <div v-if="!filteredEvents.length" class="text-center py-4" style="opacity: 0.6">Chưa có mốc nào.</div>
                <v-timeline v-else side="end" align="start" density="compact" truncate-line="both">
                    <v-timeline-item
                        v-for="(event, index) in filteredEvents"
                        :key="index"
                        :dot-color="GROUPS[event.group]?.color"
                        :icon="ICONS[event.type] ?? GROUPS[event.group]?.icon"
                        size="small"
                    >
                        <template #opposite>
                            <span class="text-caption" style="opacity: 0.7">{{ formatDate(event.date) }}</span>
                        </template>
                        <div class="text-body-2 font-weight-bold">{{ event.title }}</div>
                        <div v-if="event.description" class="text-caption" style="opacity: 0.8">{{ event.description }}</div>
                    </v-timeline-item>
                </v-timeline>
            </v-sheet>
        </template>
    </div>
</template>

<script setup>
import { computed, defineAsyncComponent, defineComponent, h, onMounted, ref } from "vue";
import { VChip } from "vuetify/components";
import employeeService from "../../services/employeeService";
import { CONTRACT_TYPE_LABELS } from "../../composables/recruitmentStatus";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

// ApexCharts nặng — tải riêng, không chặn phần còn lại của tab.
const SalaryJourneyChart = defineAsyncComponent(() => import("../../components/employee/SalaryJourneyChart.vue"));

const props = defineProps({
    employeeId: { type: [Number, String], required: true },
});

const GROUPS = {
    recruitment: { label: "Tuyển dụng", icon: "mdi-briefcase-search-outline", color: "indigo" },
    contract: { label: "Hợp đồng & lương", icon: "mdi-file-document-outline", color: "primary" },
    position: { label: "Phòng ban & chức vụ", icon: "mdi-swap-horizontal", color: "teal" },
    resignation: { label: "Nghỉ việc", icon: "mdi-exit-run", color: "error" },
};

const ICONS = {
    cv_submitted: "mdi-file-upload-outline",
    cv_approved: "mdi-check",
    interview: "mdi-account-voice",
    offer_sent: "mdi-email-outline",
    offer_accepted: "mdi-handshake-outline",
    offer_declined: "mdi-close",
    hired: "mdi-account-plus-outline",
    contract_started: "mdi-cash",
    contract_terminated: "mdi-file-cancel-outline",
    onboard: "mdi-door-open",
};

const CONTRACT_STATUS = { active: "Đang hiệu lực", pending: "Chưa hiệu lực", expired: "Hết hạn", terminated: "Đã chấm dứt" };

// Chip "+2.000.000 ₫ · +20%" (xanh tăng / đỏ giảm / xám giữ nguyên).
const ChangeChip = defineComponent({
    props: { amount: { type: Number, default: 0 }, percent: { type: Number, default: null } },
    setup(p) {
        return () => {
            const up = p.amount > 0;
            const down = p.amount < 0;
            const sign = up ? "+" : down ? "−" : "";
            const text = p.amount === 0
                ? "Giữ nguyên"
                : `${sign}${Math.abs(Math.round(p.amount)).toLocaleString("vi-VN")} ₫${p.percent != null ? ` · ${sign}${Math.abs(p.percent)}%` : ""}`;
            return h(VChip, {
                size: "x-small",
                variant: "tonal",
                color: up ? "success" : down ? "error" : undefined,
                prependIcon: up ? "mdi-trending-up" : down ? "mdi-trending-down" : "mdi-minus",
            }, () => text);
        };
    },
});

const journey = ref(null);
const loading = ref(true);
const loadError = ref("");
const groupFilter = ref("all");

const summary = computed(() => journey.value?.summary ?? {});
const filteredEvents = computed(() =>
    (journey.value?.events ?? []).filter((event) => groupFilter.value === "all" || event.group === groupFilter.value),
);

const tenureLabel = computed(() => {
    const t = summary.value.tenure;
    if (!t) return "—";
    const parts = [];
    if (t.years) parts.push(`${t.years} năm`);
    if (t.months) parts.push(`${t.months} tháng`);
    if (!t.years && t.days) parts.push(`${t.days} ngày`);
    return parts.join(" ") || "Hôm nay";
});

function money(value) {
    return value == null ? "—" : `${Math.round(value).toLocaleString("vi-VN")} ₫`;
}

function formatDate(value) {
    if (!value) return "";
    const d = new Date(String(value).length === 10 ? `${value}T00:00:00` : value);
    const pad = (n) => String(n).padStart(2, "0");
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
}

async function load() {
    loadError.value = "";
    try {
        journey.value = (await employeeService.journey(props.employeeId)).data.data;
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không tải được hành trình nhân viên.";
    } finally {
        loading.value = false;
    }
}

useRealtimeRefresh(load, {
    shared: [
        { resource: "employees", permission: "employee.view" },
        { resource: "employee_contracts", permission: "employee.view" },
        { resource: "employee_transfers", permission: "employee.view" },
        { resource: "payrolls", permission: "payroll.view_all" },
    ],
});

onMounted(load);
</script>

<style scoped>
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
@media (max-width: 960px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 520px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>
