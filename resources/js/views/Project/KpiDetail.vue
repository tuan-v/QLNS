<template>
    <div>
        <p v-if="data.partial" class="note mb-4">
            Bạn đang xem với vai trò Lead nên chỉ tính các việc trong dự án bạn làm Lead.
        </p>

        <div v-if="!data.metrics" class="empty">Không có việc nào có hạn trong {{ period }}.</div>

        <template v-else>
            <!-- Điểm tổng + 3 chỉ số -->
            <div class="score-row">
                <div class="score-box">
                    <div class="score-box__label">Điểm KPI</div>
                    <div class="score-box__value">{{ formatScore(data.metrics.score) }}</div>
                    <v-chip :color="KPI_GRADE_COLORS[data.metrics.grade.key]" variant="flat" size="small" label>
                        Loại {{ data.metrics.grade.key }} · {{ data.metrics.grade.label }}
                    </v-chip>
                </div>
                <div class="metrics">
                    <div v-for="metric in KPI_METRICS" :key="metric.key" class="metric">
                        <div class="metric__head">
                            <span class="font-weight-medium">{{ metric.label }}</span>
                            <span class="text-medium-emphasis text-caption">× {{ data.weights[metric.key] }}%</span>
                            <v-spacer />
                            <span class="font-weight-medium">{{ formatPercent(data.metrics[metric.rate]) }}</span>
                        </div>
                        <v-progress-linear
                            :model-value="data.metrics[metric.rate]"
                            color="primary"
                            bg-color="hairline"
                            bg-opacity="1"
                            height="6"
                            rounded
                        />
                        <div class="text-caption text-medium-emphasis mt-1">{{ metricNote(metric.key) }}</div>
                    </div>
                </div>
            </div>

            <!-- Theo dự án -->
            <template v-if="data.projects.length > 1">
                <div class="section-title">Theo dự án</div>
                <div class="table-wrap">
                    <table class="kpi-table">
                        <thead>
                            <tr>
                                <th>Dự án</th>
                                <th class="num">Việc</th>
                                <th class="num">Hoàn thành</th>
                                <th class="num">Đúng hạn</th>
                                <th class="num">Chất lượng</th>
                                <th class="num">KPI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in data.projects" :key="row.project.id">
                                <td>
                                    <span class="text-medium-emphasis">{{ row.project.code }}</span>
                                    {{ row.project.name }}
                                </td>
                                <td class="num">{{ row.done_count }}/{{ row.task_count }}</td>
                                <td class="num">{{ formatPercent(row.completion_rate) }}</td>
                                <td class="num">{{ formatPercent(row.on_time_rate) }}</td>
                                <td class="num">{{ qualityText(row.quality_avg) }}</td>
                                <td class="num font-weight-medium">{{ formatScore(row.score) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- Việc được tính -->
            <div class="section-title">Các việc được tính ({{ data.tasks.length }})</div>
            <div class="table-wrap">
                <table class="kpi-table">
                    <thead>
                        <tr>
                            <th>Việc</th>
                            <th class="num">Độ khó</th>
                            <th>Hạn</th>
                            <th>Nộp</th>
                            <th>Trạng thái</th>
                            <th>Kết quả</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="task in data.tasks" :key="task.id">
                            <td class="task-title">
                                <div>{{ task.title }}</div>
                                <div class="text-caption text-medium-emphasis">{{ task.project.code }}</div>
                            </td>
                            <td class="num">{{ task.points }}</td>
                            <td>{{ formatDate(task.due_date) }}</td>
                            <td>{{ formatDate(task.submitted_at) || "—" }}</td>
                            <td>
                                <v-chip
                                    :color="TASK_STATUS_MAP[task.status]?.color"
                                    size="x-small"
                                    variant="tonal"
                                    label
                                >
                                    {{ TASK_STATUS_MAP[task.status]?.label ?? task.status }}
                                </v-chip>
                            </td>
                            <td>
                                <div v-if="task.status === 'done'" class="d-flex flex-wrap ga-1">
                                    <ScoreLevelChip
                                        :value="task.quality_score"
                                        :levels="QUALITY_LEVELS"
                                        size="x-small"
                                    />
                                    <v-chip
                                        size="x-small"
                                        :color="task.on_time ? 'success' : 'error'"
                                        variant="tonal"
                                        label
                                    >
                                        {{ task.on_time ? "Đúng hạn" : "Trễ hạn" }}
                                    </v-chip>
                                </div>
                                <span v-else class="text-caption text-error">Chưa hoàn thành</span>
                                <div v-if="task.return_count" class="text-caption text-medium-emphasis">
                                    Bị trả lại {{ task.return_count }} lần
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed } from "vue";
import ScoreLevelChip from "../../components/common/ScoreLevelChip.vue";
import { QUALITY_LEVELS } from "../../composables/scoreLevels";
import { TASK_STATUS_MAP, formatDate } from "../../composables/projectStatus";
import { KPI_GRADE_COLORS, KPI_METRICS, formatPercent, formatScore, monthLabel } from "../../composables/kpi";

// Chi tiết KPI 1 nhân viên trong 1 tháng (response GET /kpi/employees/{id}) — dùng ở hộp thoại của
// trang KPI và hiện thẳng khi nhân viên tự xem.
const props = defineProps({
    data: { type: Object, required: true },
});

const period = computed(() => monthLabel(props.data.year, props.data.month).toLowerCase());
const qualityText = (value) =>
    value === null ? "—" : `${Number(value).toLocaleString("vi-VN", { maximumFractionDigits: 1 })}/5`;

function metricNote(key) {
    const m = props.data.metrics;
    if (key === "completion")
        return `${m.points_done}/${m.points_due} điểm độ khó (${m.done_count}/${m.task_count} việc)`;
    if (key === "on_time")
        return m.points_done ? `${m.points_on_time}/${m.points_done} điểm nộp đúng hạn` : "Chưa có việc hoàn thành";
    return m.quality_avg === null ? "Chưa có việc hoàn thành" : `Trung bình ${qualityText(m.quality_avg)}`;
}
</script>

<style scoped>
.note {
    font-size: 0.875rem;
    color: rgb(var(--v-theme-ink-muted));
}
.empty {
    padding: 40px 0;
    text-align: center;
    color: rgb(var(--v-theme-ink-muted));
}
.score-row {
    display: grid;
    grid-template-columns: 180px 1fr;
    gap: 24px;
    align-items: start;
}
.score-box {
    border: 1px solid rgb(var(--v-theme-hairline));
    border-radius: 12px;
    padding: 16px;
    text-align: center;
}
.score-box__label {
    font-size: 0.8125rem;
    color: rgb(var(--v-theme-ink-muted));
}
.score-box__value {
    font-size: 2.5rem;
    font-weight: 700;
    line-height: 1.2;
    margin: 4px 0 8px;
    font-variant-numeric: tabular-nums;
}
.metrics {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.metric__head {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin-bottom: 6px;
    font-size: 0.875rem;
}
.section-title {
    font-weight: 600;
    margin: 24px 0 8px;
}
.table-wrap {
    overflow-x: auto;
    border: 1px solid rgb(var(--v-theme-hairline));
    border-radius: 12px;
}
.kpi-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}
.kpi-table th,
.kpi-table td {
    padding: 10px 14px;
    text-align: left;
    border-bottom: 1px solid rgb(var(--v-theme-hairline));
    white-space: nowrap;
    vertical-align: top;
}
.kpi-table th {
    font-size: 12px;
    font-weight: 600;
    color: rgb(var(--v-theme-ink-muted));
}
.kpi-table tbody tr:last-child td {
    border-bottom: 0;
}
.kpi-table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.task-title {
    white-space: normal !important;
    min-width: 200px;
}
@media (max-width: 600px) {
    .score-row {
        grid-template-columns: 1fr;
    }
}
</style>
