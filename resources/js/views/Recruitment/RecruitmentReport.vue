<template>
    <!-- Báo cáo tuyển dụng (RecruitmentReportService): phễu CV -> nhận việc, thời gian xử lý,
         tỉ lệ nhận offer, hiệu quả theo nguồn ứng viên và theo vị trí. -->
    <div>
        <PageHeader
            title="Báo cáo tuyển dụng"
            subtitle="Thống kê tuyển dụng, thời gian xử lý và kênh tuyển hiệu quả — tính theo ngày nhận CV."
        />

        <!-- Bộ lọc -->
        <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
            <div class="filters">
                <InputDate v-model="filters.from" label="Từ ngày" hide-details />
                <InputDate v-model="filters.to" label="Đến ngày" hide-details :error-messages="errors.to" />
                <v-autocomplete
                    v-model="filters.opening_id"
                    :items="options.openings"
                    label="Đợt tuyển"
                    clearable
                    hide-details
                    density="comfortable"
                />
                <v-autocomplete
                    v-model="filters.position_id"
                    :items="options.positions"
                    label="Vị trí"
                    clearable
                    hide-details
                    density="comfortable"
                />
                <v-select
                    v-model="filters.source"
                    :items="options.sources"
                    label="Nguồn ứng viên"
                    clearable
                    hide-details
                    density="comfortable"
                />
            </div>
            <div class="d-flex flex-wrap ga-2 mt-3">
                <v-chip v-for="p in PRESETS" :key="p.key" size="small" variant="outlined" @click="applyPreset(p.key)">{{
                    p.label
                }}</v-chip>
            </div>
        </v-sheet>

        <v-alert v-if="loadError" type="error" variant="tonal" density="compact" class="mb-4">{{ loadError }}</v-alert>
        <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-2" />

        <template v-if="data">
            <!-- Chỉ số chính -->
            <div class="kpis mb-4">
                <v-sheet
                    v-for="k in kpis"
                    :key="k.label"
                    class="border rounded-lg pa-4 glass-panel"
                    color="transparent"
                >
                    <div class="d-flex align-center ga-2 mb-1">
                        <v-icon :icon="k.icon" :color="k.color" size="20" />
                        <span class="text-caption" style="opacity: 0.7">{{ k.label }}</span>
                    </div>
                    <div class="text-h5 font-weight-bold">{{ k.value }}</div>
                    <div class="text-caption" style="opacity: 0.65">{{ k.hint }}</div>
                </v-sheet>
            </div>

            <!-- Phễu -->
            <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
                <div class="text-subtitle-1 font-weight-bold mb-2">Thống kê tuyển dụng</div>
                <div v-if="!data.metrics.cv_total" class="text-body-2 py-6 text-center" style="opacity: 0.6">
                    Không có CV nào trong khoảng thời gian / bộ lọc này.
                </div>
                <div v-else class="funnel-layout">
                    <VueApexCharts type="bar" height="320" :options="funnelOptions" :series="funnelSeries" />
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th>Bước</th>
                                <th class="text-right">Số CV</th>
                                <th class="text-right">So với bước trước</th>
                                <th class="text-right">So với tổng</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in data.funnel" :key="s.key">
                                <td>{{ s.label }}</td>
                                <td class="text-right font-weight-bold">{{ s.count }}</td>
                                <td class="text-right" :class="dropClass(s.percent_of_previous)">
                                    {{ s.percent_of_previous === null ? "—" : `${s.percent_of_previous}%` }}
                                </td>
                                <td class="text-right">{{ s.percent_of_total }}%</td>
                            </tr>
                        </tbody>
                    </v-table>
                </div>
            </v-sheet>

            <!-- Theo nguồn / theo vị trí -->
            <div class="breakdowns">
                <v-sheet
                    v-for="b in breakdowns"
                    :key="b.title"
                    class="border rounded-lg pa-4 glass-panel"
                    color="transparent"
                >
                    <div class="text-subtitle-1 font-weight-bold mb-2">{{ b.title }}</div>
                    <div v-if="!b.rows.length" class="text-body-2 py-4 text-center" style="opacity: 0.6">
                        Chưa có dữ liệu.
                    </div>
                    <div v-else class="table-scroll">
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th>{{ b.column }}</th>
                                    <th class="text-right">CV</th>
                                    <th class="text-right">Duyệt</th>
                                    <th class="text-right">Đã PV</th>
                                    <th class="text-right">Đạt</th>
                                    <th class="text-right">Nhận việc</th>
                                    <th style="min-width: 120px">Tỉ lệ nhận việc</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in b.rows" :key="r.key">
                                    <td>{{ r.label }}</td>
                                    <td class="text-right">{{ r.received }}</td>
                                    <td class="text-right">{{ r.approved }}</td>
                                    <td class="text-right">{{ r.interviewed }}</td>
                                    <td class="text-right">{{ r.passed }}</td>
                                    <td class="text-right font-weight-bold">{{ r.hired }}</td>
                                    <td>
                                        <div class="d-flex align-center ga-2">
                                            <v-progress-linear
                                                :model-value="r.hire_rate"
                                                color="success"
                                                height="6"
                                                rounded
                                            />
                                            <span class="text-caption" style="min-width: 40px">{{ r.hire_rate }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>
                    </div>
                </v-sheet>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from "vue";
import VueApexCharts from "vue3-apexcharts";
import { useTheme } from "vuetify";
import recruitmentService from "../../services/recruitmentService";
import PageHeader from "../../components/common/PageHeader.vue";
import InputDate from "../../components/common/InputDate.vue";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

const theme = useTheme();
const iso = (d) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

const PRESETS = [
    { key: "month", label: "Tháng này" },
    { key: "quarter", label: "Quý này" },
    { key: "year", label: "Năm nay" },
    { key: "last90", label: "90 ngày gần đây" },
];

const filters = reactive({
    from: iso(new Date(new Date().getFullYear(), 0, 1)),
    to: iso(new Date()),
    opening_id: null,
    position_id: null,
    source: null,
});
const data = ref(null);
const options = ref({ openings: [], positions: [], sources: [] });
const loading = ref(false);
const loadError = ref("");
const errors = ref({});

function applyPreset(key) {
    const now = new Date();
    const start = {
        month: new Date(now.getFullYear(), now.getMonth(), 1),
        quarter: new Date(now.getFullYear(), Math.floor(now.getMonth() / 3) * 3, 1),
        year: new Date(now.getFullYear(), 0, 1),
        last90: new Date(now.getTime() - 89 * 86400000),
    }[key];
    filters.from = iso(start);
    filters.to = iso(now);
}

let seq = 0;
async function load() {
    const mine = ++seq;
    loading.value = true;
    loadError.value = "";
    errors.value = {};
    try {
        const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== null && v !== ""));
        const response = (await recruitmentService.report(params)).data.data;
        if (mine !== seq) return;
        data.value = response;
        options.value = response.options;
    } catch (e) {
        if (mine !== seq) return;
        if (e.response?.status === 422)
            errors.value = Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]]));
        else loadError.value = e.response?.data?.message ?? "Không tải được báo cáo.";
    } finally {
        if (mine === seq) loading.value = false;
    }
}

const days = (v) => (v === null || v === undefined ? "—" : `${v} ngày`);
const kpis = computed(() => {
    const m = data.value?.metrics ?? {};
    return [
        {
            label: "CV nhận được",
            value: m.cv_total ?? 0,
            hint: "trong khoảng đã chọn",
            icon: "mdi-file-account-outline",
            color: "primary",
        },
        {
            label: "Nhận việc",
            value: m.hired ?? 0,
            hint: `${m.hire_rate ?? 0}% số CV`,
            icon: "mdi-account-check-outline",
            color: "success",
        },
        {
            label: "Thời gian duyệt CV TB",
            value: days(m.avg_review_days),
            hint: "từ lúc nhận CV tới lúc duyệt",
            icon: "mdi-timer-sand",
            color: "info",
        },
        {
            label: "Thời gian tuyển TB",
            value: days(m.avg_days_to_accept),
            hint: "từ lúc nhận CV tới chấp nhận offer",
            icon: "mdi-calendar-clock",
            color: "warning",
        },
        {
            label: "Tỉ lệ nhận offer",
            value:
                m.offer_acceptance_rate === null || m.offer_acceptance_rate === undefined
                    ? "—"
                    : `${m.offer_acceptance_rate}%`,
            hint: `${m.offers_accepted ?? 0} nhận · ${m.offers_declined ?? 0} từ chối · ${m.offers_expired ?? 0} hết hạn · ${m.offers_waiting ?? 0} chờ`,
            icon: "mdi-email-check-outline",
            color: "secondary",
        },
    ];
});

const breakdowns = computed(() => [
    { title: "Theo nguồn ứng viên", column: "Nguồn", rows: data.value?.by_source ?? [] },
    { title: "Theo vị trí", column: "Vị trí", rows: data.value?.by_position ?? [] },
]);

const funnelSeries = computed(() => [{ name: "Số CV", data: (data.value?.funnel ?? []).map((s) => s.count) }]);
const funnelOptions = computed(() => {
    const dark = theme.global.current.value.dark;
    return {
        chart: { toolbar: { show: false }, fontFamily: "inherit", background: "transparent" },
        theme: { mode: dark ? "dark" : "light" },
        plotOptions: { bar: { horizontal: true, borderRadius: 4, distributed: true, barHeight: "70%" } },
        colors: ["#6366f1", "#7c6cf2", "#8b75f3", "#9a7ef4", "#22c55e", "#16a34a", "#15803d", "#166534"],
        legend: { show: false },
        dataLabels: { enabled: true, style: { fontSize: "12px" } },
        xaxis: {
            categories: (data.value?.funnel ?? []).map((s) => s.label),
            labels: { formatter: (v) => Math.round(v) },
        },
        tooltip: { y: { formatter: (v) => `${v} CV` } },
        grid: { borderColor: dark ? "rgba(255,255,255,0.08)" : "rgba(0,0,0,0.06)" },
    };
});

// Rơi mạnh ở 1 bước (< 50% bước trước) -> tô cảnh báo để thấy "điểm nghẽn".
function dropClass(percent) {
    if (percent === null) return "";
    return percent < 50 ? "text-error" : "";
}

let timer = null;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});

useRealtimeRefresh(load, {
    shared: [{ resource: "recruitment", permission: ["recruitment.manage", "recruitment.approve"] }],
});

onMounted(load);
</script>

<style scoped>
.filters {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
}
.kpis {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
}
.funnel-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
    gap: 16px;
    align-items: center;
}
.breakdowns {
    display: grid;
    grid-template-columns: 1fr;
    gap: 16px;
}
.table-scroll :deep(td:first-child),
.table-scroll :deep(th:first-child) {
    min-width: 180px;
    white-space: nowrap;
}
.table-scroll {
    overflow-x: auto;
}
@media (max-width: 1280px) {
    .filters,
    .kpis {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .funnel-layout {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 600px) {
    .filters,
    .kpis {
        grid-template-columns: 1fr;
    }
}
</style>
