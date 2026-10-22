<template>
    <div>
        <PageHeader
            title="Công việc của tôi"
            subtitle="Việc được giao ở mọi dự án — xếp theo mức gấp. Làm xong bấm “Nộp” để Lead duyệt."
        >
            <template #actions>
                <v-btn variant="flat" size="large" prepend-icon="mdi-folder-multiple-outline" :to="{ name: 'projects' }"
                    >Dự án</v-btn
                >
            </template>
        </PageHeader>

        <div class="stat-grid mb-4">
            <div v-for="stat in stats" :key="stat.label" class="stat-tile glass-panel">
                <v-avatar :color="stat.color" variant="tonal" rounded="lg" size="40"
                    ><v-icon :icon="stat.icon"
                /></v-avatar>
                <div>
                    <div class="text-caption text-medium-emphasis">{{ stat.label }}</div>
                    <div class="text-h6 font-weight-bold">{{ stat.value }}</div>
                </div>
            </div>
        </div>

        <div class="toolbar mb-4">
            <v-btn-toggle v-model="scope" mandatory color="primary" variant="outlined" density="comfortable" divided>
                <v-btn value="open" prepend-icon="mdi-format-list-checks">Đang mở</v-btn>
                <v-btn value="done" prepend-icon="mdi-check-all">Đã hoàn thành</v-btn>
                <v-btn value="all">Tất cả</v-btn>
            </v-btn-toggle>
            <v-select
                v-model="projectFilter"
                :items="projectOptions"
                density="compact"
                hide-details
                class="toolbar__select"
            />
        </div>

        <v-alert v-if="loadError" type="error" variant="tonal" density="compact" class="mb-4">{{ loadError }}</v-alert>
        <v-progress-linear v-if="loading && !tasks.length" indeterminate color="primary" class="mb-4" />

        <template v-if="tasks.length">
            <section v-for="group in groups" :key="group.key" class="mb-5">
                <div class="group-head">
                    <v-icon :icon="group.icon" :color="group.color" size="18" />
                    <span class="font-weight-semibold">{{ group.title }}</span>
                    <span class="group-count">{{ group.tasks.length }}</span>
                    <span v-if="group.hint" class="text-caption text-medium-emphasis ml-1">{{ group.hint }}</span>
                </div>
                <div class="task-list glass-panel">
                    <div
                        v-for="task in group.tasks"
                        :key="task.id"
                        class="task-row"
                        :class="`task-row--${task.priority}`"
                        role="button"
                        tabindex="0"
                        @click="openTask(task.id)"
                        @keydown.enter="openTask(task.id)"
                    >
                        <div class="task-row__main min-width-0">
                            <div class="task-row__title">{{ task.title }}</div>
                            <div class="task-row__sub">
                                <span class="code-tag">{{ task.project?.code }}</span>
                                <span class="text-truncate">{{ task.project?.name }}</span>
                            </div>
                        </div>
                        <div class="task-row__meta">
                            <span class="priority-pill" :class="`priority-pill--${task.priority}`">
                                <v-icon :icon="TASK_PRIORITY_MAP[task.priority]?.icon" size="14" />{{
                                    TASK_PRIORITY_MAP[task.priority]?.label
                                }}
                            </span>
                            <span class="points-badge" :title="`Độ khó ${task.points} điểm`">{{ task.points }}đ</span>
                            <div class="due-col">
                                <template v-if="task.status === 'done'">
                                    <ScoreLevelChip
                                        :value="task.quality_score"
                                        :levels="QUALITY_LEVELS"
                                        size="x-small"
                                    />
                                    <div class="text-caption" :class="task.on_time ? 'text-success' : 'text-error'">
                                        {{ task.on_time ? "Đúng hạn" : "Trễ hạn" }}
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="text-body-2">{{ formatDate(task.due_date) }}</div>
                                    <div
                                        v-if="dueLabel(task)"
                                        class="text-caption font-weight-medium"
                                        :class="`text-${dueLabel(task).color}`"
                                    >
                                        {{ dueLabel(task).text }}
                                    </div>
                                </template>
                            </div>
                            <StatusChip :status="task.status" :map="TASK_STATUS_MAP" class="status-col" />
                            <div class="action-col" @click.stop>
                                <v-btn
                                    v-if="task.can?.start"
                                    size="small"
                                    color="primary"
                                    variant="tonal"
                                    prepend-icon="mdi-play"
                                    :loading="startingId === task.id"
                                    @click="start(task)"
                                >
                                    Bắt đầu
                                </v-btn>
                                <v-btn
                                    v-else-if="task.can?.submit"
                                    size="small"
                                    color="primary"
                                    prepend-icon="mdi-send-check-outline"
                                    @click="openTask(task.id)"
                                >
                                    Nộp
                                </v-btn>
                                <v-icon v-else icon="mdi-chevron-right" class="text-medium-emphasis" />
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </template>

        <div v-else-if="!loading" class="empty glass-panel">
            <v-avatar color="success" variant="tonal" size="64" class="mb-3">
                <v-icon :icon="scope === 'open' ? 'mdi-party-popper' : 'mdi-clipboard-text-outline'" size="32" />
            </v-avatar>
            <div class="text-h6 mb-1">
                {{ scope === "open" ? "Bạn không còn việc nào đang mở" : "Chưa có công việc nào" }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
                {{
                    scope === "open"
                        ? "Khi Lead giao việc mới, bạn sẽ nhận được thông báo."
                        : "Công việc đã hoàn thành sẽ hiện ở đây."
                }}
            </div>
        </div>

        <TaskDetailDialog v-model="taskDialog" :task-id="activeTaskId" show-project-link @changed="loadData" />
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import projectService from "../../services/projectService";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import ScoreLevelChip from "../../components/common/ScoreLevelChip.vue";
import { QUALITY_LEVELS } from "../../composables/scoreLevels";
import TaskDetailDialog from "./TaskDetailDialog.vue";
import { useToastStore } from "../../stores/useToastStore";
import { TASK_PRIORITY_MAP, TASK_STATUS_MAP, daysLeft, dueLabel, formatDate } from "../../composables/projectStatus";
import { useRememberedRef } from "../../composables/useRememberedRef";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

const toast = useToastStore();

const tasks = ref([]);
// Số liệu thẻ luôn tính từ toàn bộ việc đang mở (không đổi theo tab/bộ lọc).
const openTasks = ref([]);
const loading = ref(false);
const loadError = ref("");
const scope = useRememberedRef("my-tasks.scope", "open");
const projectFilter = ref(null);
const taskDialog = ref(false);
const activeTaskId = ref(null);
const startingId = ref(null);

const stats = computed(() => [
    { label: "Đang mở", value: openTasks.value.length, icon: "mdi-format-list-checks", color: "primary" },
    {
        label: "Quá hạn",
        value: openTasks.value.filter((t) => t.is_overdue).length,
        icon: "mdi-clock-alert-outline",
        color: "error",
    },
    {
        label: "Chờ Lead duyệt",
        value: openTasks.value.filter((t) => t.status === "in_review").length,
        icon: "mdi-timer-sand",
        color: "warning",
    },
    {
        label: "Bị trả lại cần sửa",
        value: openTasks.value.filter((t) => t.status === "in_progress" && t.return_count).length,
        icon: "mdi-undo-variant",
        color: "deep-orange",
    },
]);

// Nhóm theo mức gấp (chỉ với tab "Đang mở"); tab khác hiện 1 nhóm.
const GROUPS = [
    {
        key: "returned",
        title: "Bị trả lại — cần sửa",
        icon: "mdi-undo-variant",
        color: "deep-orange",
        hint: "xem lý do trong chi tiết",
    },
    { key: "overdue", title: "Quá hạn", icon: "mdi-alert-circle-outline", color: "error" },
    { key: "today", title: "Hạn hôm nay", icon: "mdi-calendar-today", color: "warning" },
    { key: "week", title: "Trong 7 ngày tới", icon: "mdi-calendar-week", color: "primary" },
    { key: "later", title: "Sau đó", icon: "mdi-calendar-month-outline", color: "secondary" },
    { key: "review", title: "Đã nộp — chờ Lead duyệt", icon: "mdi-timer-sand", color: "warning" },
];

function groupOf(task) {
    if (task.status === "in_review") return "review";
    if (task.status === "in_progress" && task.return_count) return "returned";
    if (task.is_overdue) return "overdue";
    const days = daysLeft(task.due_date);
    if (days === 0) return "today";
    return days <= 7 ? "week" : "later";
}

const groups = computed(() => {
    if (scope.value !== "open") {
        return [
            {
                key: scope.value,
                title: scope.value === "done" ? "Đã hoàn thành" : "Tất cả công việc",
                icon: scope.value === "done" ? "mdi-check-all" : "mdi-format-list-bulleted",
                color: scope.value === "done" ? "success" : "primary",
                tasks: tasks.value,
            },
        ];
    }
    return GROUPS.map((g) => ({ ...g, tasks: tasks.value.filter((t) => groupOf(t) === g.key) })).filter(
        (g) => g.tasks.length,
    );
});

const projectOptions = computed(() => {
    const seen = new Map();
    for (const t of [...openTasks.value, ...tasks.value]) {
        if (t.project && !seen.has(t.project.id)) {
            seen.set(t.project.id, { title: `${t.project.code} · ${t.project.name}`, value: t.project.id });
        }
    }
    return [{ title: "Tất cả dự án", value: null }, ...seen.values()];
});

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const needOpenSeparately = scope.value !== "open" || !!projectFilter.value;
        const [current, open] = await Promise.all([
            projectService.myTasks({ scope: scope.value, project_id: projectFilter.value || undefined }),
            needOpenSeparately ? projectService.myTasks({ scope: "open" }) : null,
        ]);
        tasks.value = current.data.data;
        openTasks.value = open ? open.data.data : tasks.value;
    } catch (e) {
        tasks.value = [];
        loadError.value = e.response?.data?.message ?? "Không tải được công việc.";
    } finally {
        loading.value = false;
    }
}

function openTask(id) {
    activeTaskId.value = id;
    taskDialog.value = true;
}

async function start(task) {
    startingId.value = task.id;
    try {
        await projectService.startTask(task.id);
        toast.success("Đã bắt đầu công việc.");
        await loadData();
    } catch (e) {
        toast.error(e.response?.data?.message ?? "Không thực hiện được.");
    } finally {
        startingId.value = null;
    }
}

useRealtimeRefresh(loadData, { mine: ["tasks"], shared: ["projects"] });

watch([scope, projectFilter], loadData);

onMounted(loadData);
</script>

<style scoped>
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.stat-tile {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border: 1px solid rgb(var(--v-theme-hairline));
}
.toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}
.toolbar__select {
    max-width: 300px;
    min-width: 200px;
}
.group-head {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-size: 0.9375rem;
}
.font-weight-semibold {
    font-weight: 600;
}
.group-count {
    min-width: 22px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    background: rgba(var(--v-theme-on-surface), 0.06);
}
.task-list {
    border: 1px solid rgb(var(--v-theme-hairline));
    overflow: hidden;
}
.task-row {
    position: relative;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 16px 14px 20px;
    cursor: pointer;
    transition: background 0.12s ease;
}
.task-row + .task-row {
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.task-row:hover,
.task-row:focus-visible {
    background: rgba(var(--v-theme-primary), 0.035);
    outline: none;
}
.task-row::before {
    content: "";
    position: absolute;
    left: 0;
    top: 12px;
    bottom: 12px;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: rgb(var(--v-theme-hairline));
}
.task-row--medium::before {
    background: rgb(var(--v-theme-info));
}
.task-row--high::before {
    background: rgb(var(--v-theme-warning));
}
.task-row--urgent::before {
    background: rgb(var(--v-theme-error));
}
.task-row__main {
    flex: 1;
}
.task-row__title {
    font-weight: 600;
    color: rgb(var(--v-theme-ink));
    word-break: break-word;
}
.task-row__sub {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 2px;
    font-size: 12.5px;
    color: rgb(var(--v-theme-ink-muted));
    min-width: 0;
}
.task-row__meta {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}
.due-col {
    width: 110px;
}
.status-col {
    width: 112px;
    justify-content: center;
}
.action-col {
    width: 96px;
    display: flex;
    justify-content: flex-end;
}
.code-tag {
    font-size: 11px;
    font-weight: 600;
    padding: 1px 6px;
    border-radius: 5px;
    background: rgba(var(--v-theme-on-surface), 0.06);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    flex-shrink: 0;
}
.priority-pill {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    font-size: 11px;
    font-weight: 600;
    padding: 1px 8px 1px 4px;
    border-radius: 999px;
    color: rgb(var(--v-theme-ink-muted));
    background: rgba(var(--v-theme-on-surface), 0.05);
    width: 98px;
}
.priority-pill--medium {
    color: rgb(var(--v-theme-info));
    background: rgba(var(--v-theme-info), 0.1);
}
.priority-pill--high {
    color: rgb(var(--v-theme-warning));
    background: rgba(var(--v-theme-warning), 0.12);
}
.priority-pill--urgent {
    color: rgb(var(--v-theme-error));
    background: rgba(var(--v-theme-error), 0.1);
}
.points-badge {
    min-width: 30px;
    height: 22px;
    padding: 0 6px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.08);
}
.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 48px 16px;
    border: 1px dashed rgb(var(--v-theme-hairline));
}

@media (max-width: 1100px) {
    .task-row {
        flex-wrap: wrap;
    }
    .task-row__main {
        flex-basis: 100%;
    }
    .task-row__meta {
        width: 100%;
        flex-wrap: wrap;
        gap: 8px 12px;
    }
    .action-col {
        margin-left: auto;
        width: auto;
    }
}
@media (max-width: 960px) {
    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
