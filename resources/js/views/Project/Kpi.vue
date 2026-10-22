<template>
    <div>
        <PageHeader title="KPI công việc" :subtitle="subtitle">
            <template v-if="info.scope !== 'self' && info.self_employee_id" #actions>
                <v-btn variant="flat" prepend-icon="mdi-account-outline" @click="openDetail(info.self_employee_id)">
                    KPI của tôi
                </v-btn>
            </template>
        </PageHeader>

        <!-- Bộ lọc -->
        <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
            <div class="filters">
                <v-select
                    v-model="period"
                    :items="monthOptions"
                    label="Tháng"
                    density="compact"
                    hide-details
                    class="filters__item"
                />
                <template v-if="info.scope !== 'self'">
                    <v-select
                        v-if="info.departments.length"
                        v-model="departmentId"
                        :items="info.departments.map((d) => ({ title: d.name, value: d.id }))"
                        label="Phòng ban"
                        density="compact"
                        hide-details
                        clearable
                        class="filters__item"
                    />
                    <v-select
                        v-if="info.projects.length"
                        v-model="projectId"
                        :items="info.projects.map((p) => ({ title: `${p.code} · ${p.name}`, value: p.id }))"
                        label="Dự án"
                        density="compact"
                        hide-details
                        clearable
                        class="filters__item"
                    />
                    <SearchField v-model="search" placeholder="Tìm tên, mã nhân viên" class="filters__item" />
                </template>
            </div>
        </v-sheet>

        <p class="period-note">
            <template v-if="status.status === 'locked'">
                Số liệu {{ periodText }} đã chốt ngày {{ formatDate(status.locked_at) }}, không còn thay đổi.
            </template>
            <template v-else>
                Số tạm tính, tự chốt ngày {{ formatDate(status.lock_date) }}. Việc có hạn trong tháng mà chưa xong được
                tính là chưa hoàn thành.
            </template>
            <br />
            KPI = {{ info.weights.completion }}% Hoàn thành + {{ info.weights.on_time }}% Đúng hạn +
            {{ info.weights.quality }}% Chất lượng, tính theo điểm độ khó của việc. Xếp loại:
            {{ info.grades.map((g) => (g.min ? `${g.key} từ ${g.min}` : `${g.key} dưới ${prevMin(g)}`)).join(", ") }}.
        </p>

        <v-alert v-if="error" type="error" variant="tonal" density="compact" class="mb-4">{{ error }}</v-alert>
        <v-progress-linear v-if="loading && !loaded" indeterminate color="primary" class="mb-4" />

        <!-- Nhân viên tự xem: hiện thẳng chi tiết -->
        <v-sheet v-if="info.scope === 'self' && detailData" class="border rounded-lg glass-panel pa-5">
            <KpiDetail :data="detailData" />
        </v-sheet>

        <!-- HR / Trưởng phòng / Lead -->
        <template v-else-if="info.scope !== 'self' && loaded">
            <StatCards :stats="stats" />
            <v-sheet class="border rounded-lg glass-panel mt-4" color="transparent">
                <div class="table-wrap">
                    <table class="kpi-list">
                        <thead>
                            <tr>
                                <th>Nhân viên</th>
                                <th class="num">Việc</th>
                                <th class="num">Hoàn thành</th>
                                <th class="num">Đúng hạn</th>
                                <th class="num">Chất lượng</th>
                                <th class="num">Bị trả lại</th>
                                <th class="num">KPI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in items"
                                :key="item.employee.id"
                                tabindex="0"
                                class="kpi-row"
                                @click="openDetail(item.employee.id)"
                                @keydown.enter="openDetail(item.employee.id)"
                            >
                                <td>
                                    <div class="d-flex align-center ga-3">
                                        <PersonAvatar :person="item.employee" :size="34" />
                                        <div class="min-w-0">
                                            <div class="font-weight-medium">{{ item.employee.full_name }}</div>
                                            <div class="text-caption text-medium-emphasis">
                                                {{
                                                    [item.employee.code, item.employee.department]
                                                        .filter(Boolean)
                                                        .join(" · ")
                                                }}
                                                <template v-if="item.partial"> · chỉ dự án bạn làm Lead</template>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="num">{{ item.done_count }}/{{ item.task_count }}</td>
                                <td class="num">{{ formatPercent(item.completion_rate) }}</td>
                                <td class="num">{{ formatPercent(item.on_time_rate) }}</td>
                                <td class="num">
                                    {{
                                        item.quality_avg === null
                                            ? "—"
                                            : `${item.quality_avg.toLocaleString("vi-VN", { maximumFractionDigits: 1 })}/5`
                                    }}
                                </td>
                                <td class="num">{{ item.return_count || "—" }}</td>
                                <td class="num">
                                    <span class="score">{{ formatScore(item.score) }}</span>
                                    <v-chip
                                        :color="KPI_GRADE_COLORS[item.grade.key]"
                                        variant="tonal"
                                        size="small"
                                        label
                                        class="ml-2 font-weight-bold"
                                    >
                                        {{ item.grade.key }} · {{ item.grade.label }}
                                    </v-chip>
                                </td>
                            </tr>
                            <tr v-if="!items.length">
                                <td colspan="7" class="empty">
                                    Không có nhân viên nào có việc hạn trong {{ periodText }}.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </v-sheet>
        </template>

        <!-- Chi tiết 1 nhân viên -->
        <v-dialog v-model="detailOpen" max-width="900" scrollable :fullscreen="mobile">
            <v-card>
                <v-card-title class="d-flex align-center ga-3 pt-4 px-5">
                    <template v-if="detailData">
                        <PersonAvatar :person="detailData.employee" :size="36" />
                        <div class="min-w-0">
                            <div class="text-subtitle-1 font-weight-bold">{{ detailData.employee.full_name }}</div>
                            <div class="text-caption text-medium-emphasis">
                                KPI {{ periodText }} ·
                                {{
                                    [detailData.employee.department, detailData.employee.position]
                                        .filter(Boolean)
                                        .join(" · ")
                                }}
                            </div>
                        </div>
                    </template>
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" size="small" aria-label="Đóng" @click="detailOpen = false" />
                </v-card-title>
                <v-card-text class="px-5 pb-5">
                    <v-progress-linear v-if="detailLoading" indeterminate color="primary" />
                    <v-alert v-else-if="detailError" type="error" variant="tonal" density="compact">{{
                        detailError
                    }}</v-alert>
                    <KpiDetail v-else-if="detailData" :data="detailData" />
                </v-card-text>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useDisplay } from "vuetify";
import kpiService from "../../services/kpiService";
import PageHeader from "../../components/common/PageHeader.vue";
import SearchField from "../../components/common/SearchField.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
import PersonAvatar from "./PersonAvatar.vue";
import KpiDetail from "./KpiDetail.vue";
import { formatDate } from "../../composables/projectStatus";
import { KPI_GRADE_COLORS, formatPercent, formatScore, monthLabel } from "../../composables/kpi";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

// KPI tháng từ công việc dự án (giai đoạn B). Phạm vi (công ty / phòng mình + dự án mình làm Lead /
// chỉ bản thân) do API quyết định — trang chỉ đổi cách hiển thị theo `info.scope`.
const { mobile } = useDisplay();

const now = new Date();
const monthOptions = Array.from({ length: 12 }, (_, i) => {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    return { title: monthLabel(d.getFullYear(), d.getMonth() + 1), value: `${d.getFullYear()}-${d.getMonth() + 1}` };
});

const info = ref({ scope: "self", self_employee_id: null, departments: [], projects: [], weights: {}, grades: [] });
const period = ref(monthOptions[0].value);
const departmentId = ref(null);
const projectId = ref(null);
const search = ref("");
const items = ref([]);
const summary = ref(null);
const status = ref({});
const loading = ref(false);
const loaded = ref(false);
const error = ref("");

const detailOpen = ref(false);
const detailLoading = ref(false);
const detailError = ref("");
const detailData = ref(null);

const [year, month] = [() => Number(period.value.split("-")[0]), () => Number(period.value.split("-")[1])];
const periodText = computed(() => monthLabel(year(), month()).toLowerCase());
const subtitle = computed(
    () =>
        ({
            company: "KPI tháng của nhân viên, tính từ công việc dự án.",
            limited: "Nhân viên phòng bạn quản lý và thành viên các dự án bạn làm Lead.",
            self: "KPI tháng của bạn, tính từ công việc dự án được giao.",
        })[info.value.scope],
);

const stats = computed(() => {
    const s = summary.value;
    if (!s) return [];
    return [
        { label: "Nhân viên có việc", value: s.employees, icon: "mdi-account-group-outline", color: "primary" },
        { label: "KPI trung bình", value: formatScore(s.average_score), icon: "mdi-chart-line", color: "info" },
        { label: "Loại A (Xuất sắc)", value: s.grades.A ?? 0, icon: "mdi-star-circle-outline", color: "success" },
        { label: "Loại D (Cần cải thiện)", value: s.grades.D ?? 0, icon: "mdi-alert-circle-outline", color: "error" },
    ];
});

const prevMin = (grade) => {
    const index = info.value.grades.indexOf(grade);
    return info.value.grades[index - 1]?.min ?? 0;
};

async function load({ silent = false } = {}) {
    if (!silent) loading.value = true;
    error.value = "";
    try {
        const params = { year: year(), month: month() };
        if (info.value.scope === "self") {
            if (!info.value.self_employee_id) return;
            const { data } = await kpiService.employee(info.value.self_employee_id, params);
            detailData.value = data;
            status.value = data;
        } else {
            const { data } = await kpiService.monthly({
                ...params,
                department_id: departmentId.value || undefined,
                project_id: projectId.value || undefined,
                search: search.value?.trim() || undefined,
            });
            items.value = data.items;
            summary.value = data.summary;
            status.value = data;
        }
        loaded.value = true;
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không tải được KPI.";
    } finally {
        loading.value = false;
    }
}

async function openDetail(employeeId) {
    detailOpen.value = true;
    detailLoading.value = true;
    detailError.value = "";
    detailData.value = null;
    try {
        detailData.value = (await kpiService.employee(employeeId, { year: year(), month: month() })).data;
    } catch (e) {
        detailError.value = e.response?.data?.message ?? "Không tải được KPI của nhân viên.";
    } finally {
        detailLoading.value = false;
    }
}

// SearchField đã tự trễ khi gõ nên đổi ô tìm là tải lại luôn.
watch([period, departmentId, projectId, search], () => load());

// Việc được giao / nộp / duyệt ở bất kỳ dự án nào → số tạm tính đổi theo.
useRealtimeRefresh(() => load({ silent: true }), { shared: ["projects"] });

onMounted(async () => {
    try {
        info.value = (await kpiService.filters()).data;
    } catch {
        error.value = "Không tải được bộ lọc KPI.";
    }
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
    max-width: 260px;
    min-width: 180px;
}
.period-note {
    font-size: 0.8125rem;
    color: rgb(var(--v-theme-ink-muted));
    margin-bottom: 16px;
}
.table-wrap {
    overflow-x: auto;
}
.kpi-list {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.kpi-list th,
.kpi-list td {
    padding: 12px 16px;
    text-align: left;
    border-bottom: 1px solid rgb(var(--v-theme-hairline));
    white-space: nowrap;
}
.kpi-list th {
    font-size: 12px;
    font-weight: 600;
    color: rgb(var(--v-theme-ink-muted));
}
.kpi-list tbody tr:last-child td {
    border-bottom: 0;
}
.kpi-list .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.kpi-row {
    cursor: pointer;
}
.kpi-row:hover,
.kpi-row:focus-visible {
    background: rgba(var(--v-theme-primary), 0.04);
    outline: none;
}
.score {
    font-weight: 600;
}
.empty {
    text-align: center !important;
    padding: 40px 16px !important;
    color: rgb(var(--v-theme-ink-muted));
}
@media (max-width: 600px) {
    .filters__item {
        max-width: none;
        flex: 1 1 100%;
    }
}
</style>
