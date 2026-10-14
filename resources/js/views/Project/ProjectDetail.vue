<template>
    <div>
        <div class="mb-3">
            <v-btn variant="text" size="small" prepend-icon="mdi-arrow-left" :to="{ name: 'projects' }" class="px-1"
                >Tất cả dự án</v-btn
            >
        </div>

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

        <v-progress-linear v-if="!project && loading" indeterminate color="primary" />

        <template v-if="project">
            <!-- ================= Khung đầu trang ================= -->
            <v-sheet class="hero glass-panel border pa-5 pa-md-6 mb-4" color="transparent">
                <div class="hero__grid">
                    <div class="min-width-0">
                        <div class="d-flex flex-wrap align-center ga-2 mb-2">
                            <span class="code-tag">{{ project.code }}</span>
                            <v-menu v-if="project.can_manage_team">
                                <template #activator="{ props: menuProps }">
                                    <button v-bind="menuProps" class="status-trigger" type="button">
                                        <StatusChip :status="project.status" :map="PROJECT_STATUS_MAP" />
                                        <v-icon icon="mdi-chevron-down" size="16" />
                                    </button>
                                </template>
                                <v-list density="compact" min-width="220">
                                    <v-list-subheader>Đổi trạng thái dự án</v-list-subheader>
                                    <v-list-item
                                        v-for="option in statusOptions"
                                        :key="option.value"
                                        :active="option.value === project.status"
                                        @click="changeStatus(option.value)"
                                    >
                                        <StatusChip :status="option.value" :map="PROJECT_STATUS_MAP" />
                                    </v-list-item>
                                </v-list>
                            </v-menu>
                            <StatusChip v-else :status="project.status" :map="PROJECT_STATUS_MAP" />
                            <v-chip
                                v-if="project.my_role === 'lead'"
                                size="small"
                                color="primary"
                                variant="tonal"
                                prepend-icon="mdi-star-outline"
                            >
                                Bạn là Lead
                            </v-chip>
                        </div>
                        <h1 class="hero__title">{{ project.name }}</h1>
                        <p v-if="project.description" class="hero__desc" :class="{ 'hero__desc--open': descOpen }">
                            {{ project.description }}
                        </p>
                        <button
                            v-if="project.description && project.description.length > 160"
                            type="button"
                            class="text-caption text-primary"
                            @click="descOpen = !descOpen"
                        >
                            {{ descOpen ? "Thu gọn" : "Xem thêm" }}
                        </button>

                        <div class="hero__meta">
                            <div class="meta-item">
                                <div class="meta-item__label">Lead dự án</div>
                                <div class="d-flex align-center ga-2">
                                    <PersonAvatar :person="project.lead" :size="28" />
                                    <span class="font-weight-medium text-truncate">{{ project.lead?.full_name }}</span>
                                </div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-item__label">Thành viên</div>
                                <div class="d-flex align-center ga-2">
                                    <AvatarStack :people="memberPeople" :max="5" :size="28" />
                                    <span class="text-body-2 text-medium-emphasis"
                                        >{{ project.members.length }} người</span
                                    >
                                </div>
                            </div>
                            <div class="meta-item">
                                <div class="meta-item__label">Thời gian</div>
                                <div class="font-weight-medium">
                                    {{ formatDate(project.start_date) }} –
                                    {{ project.end_date ? formatDate(project.end_date) : "chưa xác định" }}
                                </div>
                                <div
                                    v-if="deadlineText"
                                    class="text-caption"
                                    :class="deadlineText.color ? `text-${deadlineText.color}` : 'text-medium-emphasis'"
                                >
                                    {{ deadlineText.text }}
                                </div>
                            </div>
                            <div v-if="project.department" class="meta-item">
                                <div class="meta-item__label">Phòng ban phụ trách</div>
                                <div class="font-weight-medium">{{ project.department }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="hero__side">
                        <v-progress-circular
                            :model-value="project.stats.progress"
                            :size="132"
                            :width="11"
                            :color="project.stats.progress >= 100 ? 'success' : 'primary'"
                            bg-color="hairline"
                        >
                            <div class="text-center">
                                <div class="ring-value">{{ project.stats.progress }}%</div>
                                <div class="text-caption text-medium-emphasis">tiến độ</div>
                            </div>
                        </v-progress-circular>
                        <div class="text-caption text-medium-emphasis text-center mt-2">
                            {{ project.stats.done }}/{{ project.stats.total }} việc hoàn thành<br />(tính theo điểm độ
                            khó)
                        </div>
                        <div class="d-flex flex-wrap justify-center ga-2 mt-4">
                            <v-btn
                                v-if="project.can_edit"
                                variant="flat"
                                prepend-icon="mdi-pencil-outline"
                                @click="formOpen = true"
                                >Sửa</v-btn
                            >
                            <v-btn
                                v-if="project.can_manage_team && !isClosed"
                                color="primary"
                                prepend-icon="mdi-plus"
                                @click="openCreateTask()"
                            >
                                Giao việc
                            </v-btn>
                        </div>
                    </div>
                </div>
            </v-sheet>

            <v-alert v-if="isClosed" type="info" variant="tonal" density="compact" class="mb-4" icon="mdi-lock-outline">
                Dự án đã {{ project.status === "completed" ? "hoàn thành" : "hủy" }} — chỉ xem, không giao thêm việc hay
                thêm thành viên.
            </v-alert>

            <!-- ================= Số liệu nhanh ================= -->
            <div class="stat-grid mb-4">
                <button
                    v-for="stat in stats"
                    :key="stat.key"
                    type="button"
                    class="stat-tile glass-panel"
                    :class="{ 'stat-tile--active': quickFilter === stat.key }"
                    @click="toggleQuickFilter(stat.key)"
                >
                    <v-avatar :color="stat.color" variant="tonal" rounded="lg" size="40">
                        <v-icon :icon="stat.icon" />
                    </v-avatar>
                    <div class="text-left">
                        <div class="text-caption text-medium-emphasis">{{ stat.label }}</div>
                        <div class="text-h6 font-weight-bold">{{ stat.value }}</div>
                    </div>
                </button>
            </div>

            <!-- ================= Thanh công cụ ================= -->
            <div class="toolbar mb-4">
                <v-btn-toggle
                    v-model="view"
                    mandatory
                    color="primary"
                    variant="outlined"
                    density="comfortable"
                    divided
                    class="view-toggle"
                >
                    <v-btn value="board" prepend-icon="mdi-view-column-outline">Bảng</v-btn>
                    <v-btn value="list" prepend-icon="mdi-format-list-bulleted">Danh sách</v-btn>
                    <v-btn value="members" prepend-icon="mdi-account-group-outline">Thành viên</v-btn>
                </v-btn-toggle>
                <template v-if="view !== 'members'">
                    <v-text-field
                        v-model="search"
                        density="compact"
                        hide-details
                        clearable
                        prepend-inner-icon="mdi-magnify"
                        placeholder="Tìm công việc..."
                        class="toolbar__search"
                    />
                    <v-select
                        v-model="assigneeFilter"
                        :items="assigneeOptions"
                        density="compact"
                        hide-details
                        class="toolbar__select"
                    />
                    <v-checkbox
                        v-model="showCancelled"
                        density="compact"
                        hide-details
                        label="Việc đã hủy"
                        color="primary"
                        class="flex-grow-0"
                    />
                </template>
                <v-chip v-if="quickFilter" closable color="primary" variant="tonal" @click:close="quickFilter = null">
                    Đang lọc: {{ stats.find((s) => s.key === quickFilter)?.label }}
                </v-chip>
            </div>

            <!-- ================= Bảng Kanban ================= -->
            <div v-if="view === 'board'" class="board">
                <div v-for="column in boardColumns" :key="column.status" class="board-col">
                    <div class="board-col__head">
                        <span class="col-dot" :class="`bg-${columnColor(column.status)}`" />
                        <span class="font-weight-semibold">{{ TASK_STATUS_MAP[column.status].label }}</span>
                        <span class="col-count">{{ column.tasks.length }}</span>
                        <v-spacer />
                        <span class="text-caption text-medium-emphasis">{{ column.points }} điểm</span>
                    </div>
                    <div class="board-col__body">
                        <TaskCard v-for="task in column.tasks" :key="task.id" :task="task" @open="openTask(task.id)" />
                        <div v-if="!column.tasks.length" class="board-empty">
                            <v-icon :icon="EMPTY_ICONS[column.status]" size="28" class="mb-1" />
                            <div>{{ EMPTY_TEXT[column.status] }}</div>
                        </div>
                        <button
                            v-if="column.status === 'todo' && project.can_manage_team && !isClosed"
                            type="button"
                            class="add-task"
                            @click="openCreateTask()"
                        >
                            <v-icon icon="mdi-plus" size="18" />Giao việc mới
                        </button>
                    </div>
                </div>
            </div>

            <!-- ================= Danh sách ================= -->
            <DataTable
                v-else-if="view === 'list'"
                :headers="listHeaders"
                :items="filteredTasks"
                :loading="loading"
                no-data-text="Không có công việc nào."
            >
                <template #item.title="{ item }">
                    <a
                        href="#"
                        class="font-weight-medium text-primary text-decoration-none"
                        @click.prevent="openTask(item.id)"
                        >{{ item.title }}</a
                    >
                    <div v-if="item.return_count && item.status !== 'done'" class="text-caption text-warning">
                        Bị trả lại {{ item.return_count }} lần
                    </div>
                </template>
                <template #item.assignee="{ item }">
                    <div class="d-flex align-center ga-2">
                        <PersonAvatar :person="item.assignee" :size="26" />
                        <span class="text-truncate">{{ item.assignee?.full_name ?? "Chưa giao" }}</span>
                    </div>
                </template>
                <template #item.priority="{ item }">
                    <v-chip
                        size="small"
                        :color="TASK_PRIORITY_MAP[item.priority]?.color"
                        variant="tonal"
                        :prepend-icon="TASK_PRIORITY_MAP[item.priority]?.icon"
                    >
                        {{ TASK_PRIORITY_MAP[item.priority]?.label }}
                    </v-chip>
                </template>
                <template #item.points="{ item }">{{ item.points }}</template>
                <template #item.due_date="{ item }">
                    <div>{{ formatDate(item.due_date) }}</div>
                    <span v-if="dueLabel(item)" class="text-caption" :class="`text-${dueLabel(item).color}`">{{
                        dueLabel(item).text
                    }}</span>
                </template>
                <template #item.status="{ item }">
                    <StatusChip :status="item.status" :map="TASK_STATUS_MAP" />
                </template>
            </DataTable>

            <!-- ================= Thành viên ================= -->
            <div v-else>
                <div class="d-flex flex-wrap align-center ga-3 mb-3">
                    <div class="text-body-2 text-medium-emphasis">
                        Khối lượng việc đang mở của từng người — giúp chia việc đều hơn.
                    </div>
                    <v-spacer />
                    <v-btn
                        v-if="project.can_manage_team && !isClosed"
                        color="primary"
                        prepend-icon="mdi-account-multiple-plus-outline"
                        @click="addMembersOpen = true"
                    >
                        Thêm thành viên
                    </v-btn>
                </div>
                <div class="member-grid">
                    <div v-for="member in project.members" :key="member.id" class="member-card glass-panel">
                        <div class="d-flex align-center ga-3">
                            <PersonAvatar :person="member.employee" :size="44" />
                            <div class="min-width-0 flex-grow-1">
                                <div class="d-flex align-center ga-2">
                                    <span class="font-weight-semibold text-truncate">{{
                                        member.employee.full_name
                                    }}</span>
                                    <v-chip v-if="member.role === 'lead'" size="x-small" color="primary" variant="flat"
                                        >Lead</v-chip
                                    >
                                </div>
                                <div class="text-caption text-medium-emphasis text-truncate">
                                    {{ member.employee.code
                                    }}<template v-if="member.employee.position">
                                        · {{ member.employee.position }}</template
                                    >
                                </div>
                            </div>
                            <v-tooltip
                                v-if="project.can_manage_team && member.role !== 'lead'"
                                :text="
                                    member.open_tasks ? 'Còn việc đang mở — giao lại trước khi xóa' : 'Xóa khỏi dự án'
                                "
                                location="top"
                            >
                                <template #activator="{ props: tipProps }">
                                    <span v-bind="tipProps" class="d-inline-flex">
                                        <v-btn
                                            icon="mdi-account-remove-outline"
                                            variant="text"
                                            size="small"
                                            color="error"
                                            :disabled="member.open_tasks > 0"
                                            @click="confirmRemove = member"
                                        />
                                    </span>
                                </template>
                            </v-tooltip>
                        </div>
                        <div class="mt-4">
                            <div class="d-flex justify-space-between text-caption mb-1">
                                <span>{{ member.open_tasks }} việc đang mở</span>
                                <span v-if="member.overdue_tasks" class="text-error font-weight-medium"
                                    >{{ member.overdue_tasks }} quá hạn</span
                                >
                                <span v-else class="text-medium-emphasis"
                                    >{{ doneBy(member.employee.id) }} đã xong</span
                                >
                            </div>
                            <v-progress-linear
                                :model-value="loadPercent(member.open_tasks)"
                                :color="member.overdue_tasks ? 'error' : member.open_tasks >= 5 ? 'warning' : 'primary'"
                                bg-color="hairline"
                                bg-opacity="1"
                                height="6"
                                rounded
                            />
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <TaskDetailDialog v-model="taskDialog" :task-id="activeTaskId" @changed="reload" @edit="editTask" />
        <TaskFormDialog
            v-if="project"
            v-model="taskFormOpen"
            :project-id="project.id"
            :members="project.members"
            :task="editingTask"
            @saved="reload"
        />
        <AddMembersDialog v-if="project" v-model="addMembersOpen" :project-id="project.id" @saved="reload" />
        <ProjectForm
            v-if="project"
            v-model="formOpen"
            :project="project"
            :department-options="departmentOptions"
            @saved="reload"
        />

        <v-dialog :model-value="!!confirmRemove" max-width="420" @update:model-value="confirmRemove = null">
            <v-card rounded="lg" :title="`Xóa ${confirmRemove?.employee.full_name} khỏi dự án?`">
                <v-card-text>Lịch sử công việc đã làm vẫn được giữ lại.</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="confirmRemove = null">Thôi</v-btn>
                    <v-btn color="error" :loading="removing" @click="removeMember">Xóa</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import projectService from "../../services/projectService";
import DataTable from "../../components/common/DataTable.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import TaskCard from "./TaskCard.vue";
import PersonAvatar from "./PersonAvatar.vue";
import AvatarStack from "./AvatarStack.vue";
import TaskDetailDialog from "./TaskDetailDialog.vue";
import TaskFormDialog from "./TaskFormDialog.vue";
import AddMembersDialog from "./AddMembersDialog.vue";
import ProjectForm from "./ProjectForm.vue";
import { useAuthStore } from "../../stores/authStore";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import { useToastStore } from "../../stores/useToastStore";
import { normalizeVietnamese } from "../../composables/normalizeVietnamese";
import {
    PROJECT_STATUS_MAP,
    TASK_BOARD_COLUMNS,
    TASK_PRIORITY_MAP,
    TASK_STATUS_MAP,
    daysLeft,
    dueLabel,
    formatDate,
    toOptions,
} from "../../composables/projectStatus";
import { useRememberedRef } from "../../composables/useRememberedRef";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const departmentStore = useDepartmentStore();
const toast = useToastStore();

const project = ref(null);
const tasks = ref([]);
const loading = ref(false);
const loadError = ref("");
const descOpen = ref(false);
const view = useRememberedRef("project-detail.view", "board");
const search = ref("");
const assigneeFilter = ref(null);
const quickFilter = ref(null);
const showCancelled = useRememberedRef("project-detail.show-cancelled", false);

const taskDialog = ref(false);
const activeTaskId = ref(null);
const taskFormOpen = ref(false);
const editingTask = ref(null);
const addMembersOpen = ref(false);
const formOpen = ref(false);
const confirmRemove = ref(null);
const removing = ref(false);

const EMPTY_ICONS = {
    todo: "mdi-clipboard-outline",
    in_progress: "mdi-progress-clock",
    in_review: "mdi-timer-sand",
    done: "mdi-check-all",
    cancelled: "mdi-cancel",
};
const EMPTY_TEXT = {
    todo: "Chưa có việc cần làm",
    in_progress: "Chưa ai bắt đầu việc nào",
    in_review: "Không có việc chờ duyệt",
    done: "Chưa có việc hoàn thành",
    cancelled: "Không có việc bị hủy",
};

const projectId = computed(() => Number(route.params.id));
const isClosed = computed(() => ["completed", "cancelled"].includes(project.value?.status));
const statusOptions = toOptions(PROJECT_STATUS_MAP);
const memberPeople = computed(() => project.value.members.map((m) => m.employee));

const deadlineText = computed(() => {
    const p = project.value;
    if (!p.end_date || isClosed.value) {
        return null;
    }
    const days = daysLeft(p.end_date);
    if (days < 0) return { text: `Đã quá hạn kết thúc ${-days} ngày`, color: "error" };
    if (days === 0) return { text: "Kết thúc hôm nay", color: "warning" };
    return { text: `Còn ${days} ngày`, color: days <= 7 ? "warning" : null };
});

const stats = computed(() => {
    const open = tasks.value.filter((t) => ["todo", "in_progress", "in_review"].includes(t.status));
    return [
        {
            key: "in_progress",
            label: "Đang làm",
            value: open.filter((t) => t.status === "in_progress").length,
            icon: "mdi-progress-clock",
            color: "primary",
        },
        {
            key: "in_review",
            label: "Chờ duyệt",
            value: project.value.stats.in_review,
            icon: "mdi-timer-sand",
            color: "warning",
        },
        {
            key: "overdue",
            label: "Quá hạn",
            value: project.value.stats.overdue,
            icon: "mdi-clock-alert-outline",
            color: "error",
        },
        {
            key: "unassigned",
            label: "Chưa giao người",
            value: open.filter((t) => !t.assignee_employee_id).length,
            icon: "mdi-account-question-outline",
            color: "secondary",
        },
    ];
});

const assigneeOptions = computed(() => [
    { title: "Tất cả thành viên", value: null },
    { title: "Chưa giao", value: "none" },
    ...(project.value?.members ?? []).map((m) => ({ title: m.employee.full_name, value: m.employee.id })),
]);

const filteredTasks = computed(() => {
    const keyword = normalizeVietnamese((search.value ?? "").trim());
    return tasks.value.filter((t) => {
        if (!showCancelled.value && t.status === "cancelled") return false;
        if (assigneeFilter.value === "none" && t.assignee_employee_id) return false;
        if (assigneeFilter.value && assigneeFilter.value !== "none" && t.assignee_employee_id !== assigneeFilter.value)
            return false;
        if (quickFilter.value === "overdue" && !t.is_overdue) return false;
        if (
            quickFilter.value === "unassigned" &&
            (t.assignee_employee_id || !["todo", "in_progress", "in_review"].includes(t.status))
        )
            return false;
        if (["in_progress", "in_review"].includes(quickFilter.value) && t.status !== quickFilter.value) return false;
        return !keyword || normalizeVietnamese(`${t.title} ${t.description ?? ""}`).includes(keyword);
    });
});

const boardColumns = computed(() => {
    const columns = showCancelled.value ? [...TASK_BOARD_COLUMNS, "cancelled"] : TASK_BOARD_COLUMNS;
    return columns.map((status) => {
        const list = filteredTasks.value.filter((t) => t.status === status);
        return { status, tasks: list, points: list.reduce((sum, t) => sum + t.points, 0) };
    });
});

const listHeaders = [
    { title: "Công việc", key: "title" },
    { title: "Người làm", key: "assignee", sortable: false, width: 200 },
    { title: "Ưu tiên", key: "priority", width: 140 },
    { title: "Điểm", key: "points", width: 80 },
    { title: "Hạn", key: "due_date", width: 140 },
    { title: "Trạng thái", key: "status", width: 140 },
];

function columnColor(status) {
    return { todo: "secondary", in_progress: "primary", in_review: "warning", done: "success", cancelled: "grey" }[
        status
    ];
}

function toggleQuickFilter(key) {
    quickFilter.value = quickFilter.value === key ? null : key;
    if (view.value === "members") {
        view.value = "board";
    }
}

function doneBy(employeeId) {
    return tasks.value.filter((t) => t.assignee_employee_id === employeeId && t.status === "done").length;
}

// Thanh khối lượng: 5 việc mở trở lên coi như đầy.
function loadPercent(open) {
    return Math.min(100, open * 20);
}

function flatten(nodes) {
    return nodes.flatMap((node) => [node, ...flatten(node.children ?? [])]);
}
const departmentOptions = computed(() => flatten(departmentStore.tree).map((d) => ({ title: d.name, value: d.id })));

async function reload() {
    loading.value = true;
    try {
        const [projectResponse, tasksResponse] = await Promise.all([
            projectService.show(projectId.value),
            projectService.tasks(projectId.value),
        ]);
        project.value = projectResponse.data.data;
        tasks.value = tasksResponse.data.data;
        loadError.value = "";
    } catch (e) {
        if (!project.value) {
            loadError.value = e.response?.data?.message ?? "Không tải được dự án.";
        }
    } finally {
        loading.value = false;
    }
}

function openTask(id) {
    activeTaskId.value = id;
    taskDialog.value = true;
}

function openCreateTask() {
    editingTask.value = null;
    taskFormOpen.value = true;
}

function editTask(task) {
    taskDialog.value = false;
    editingTask.value = task;
    taskFormOpen.value = true;
}

async function changeStatus(status) {
    if (status === project.value.status) {
        return;
    }
    try {
        await projectService.update(project.value.id, { status });
        toast.success("Đã đổi trạng thái dự án.");
        reload();
    } catch (e) {
        toast.error(e.response?.data?.message ?? "Không đổi được trạng thái.");
    }
}

async function removeMember() {
    removing.value = true;
    try {
        await projectService.removeMember(project.value.id, confirmRemove.value.employee.id);
        toast.success("Đã xóa thành viên khỏi dự án.");
        confirmRemove.value = null;
        reload();
    } catch (e) {
        const errors = e.response?.data?.errors;
        toast.error(
            (errors ? Object.values(errors)[0]?.[0] : e.response?.data?.message) ?? "Không xóa được thành viên.",
        );
    } finally {
        removing.value = false;
    }
}

// Mở thẳng 1 công việc từ thông báo (?task=ID), rồi bỏ tham số khỏi URL.
watch(
    () => route.query.task,
    (taskId) => {
        if (taskId) {
            openTask(Number(taskId));
            const { task: _task, ...rest } = route.query;
            router.replace({ query: rest });
        }
    },
    { immediate: true },
);

watch(projectId, () => {
    project.value = null;
    reload();
});

useRealtimeRefresh(reload, { shared: ["projects"] });

onMounted(() => {
    reload();
    if (auth.permissions.includes("department.view")) {
        departmentStore.fetchTree();
    }
});
</script>

<style scoped>
.hero__grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 220px;
    gap: 24px;
    align-items: center;
}
.hero__title {
    font-size: 1.625rem;
    font-weight: 700;
    line-height: 1.3;
    color: rgb(var(--v-theme-ink));
    word-break: break-word;
}
.hero__desc {
    margin-top: 6px;
    color: rgb(var(--v-theme-ink-muted));
    white-space: pre-line;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    max-width: 760px;
}
.hero__desc--open {
    -webkit-line-clamp: unset;
    display: block;
}
.hero__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 16px 32px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.meta-item {
    min-width: 0;
}
.meta-item__label {
    font-size: 12px;
    color: rgb(var(--v-theme-ink-muted));
    margin-bottom: 4px;
}
.hero__side {
    display: flex;
    flex-direction: column;
    align-items: center;
}
.ring-value {
    font-size: 1.75rem;
    font-weight: 700;
    line-height: 1;
    color: rgb(var(--v-theme-ink));
}
.code-tag {
    font-size: 12px;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 6px;
    color: rgb(var(--v-theme-ink-muted));
    background: rgba(var(--v-theme-on-surface), 0.06);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.status-trigger {
    display: inline-flex;
    align-items: center;
    gap: 2px;
    border-radius: 999px;
    padding-right: 4px;
}
.status-trigger:hover {
    background: rgba(var(--v-theme-on-surface), 0.04);
}

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
    border-radius: 16px;
    background: rgb(var(--v-theme-surface));
    transition: border-color 0.12s ease;
}
.stat-tile:hover {
    border-color: rgba(var(--v-theme-primary), 0.45);
}
.stat-tile--active {
    border-color: rgb(var(--v-theme-primary));
    box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), 0.12) !important;
}

.toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
}
.toolbar__search {
    max-width: 260px;
    min-width: 180px;
}
.toolbar__select {
    max-width: 220px;
    min-width: 170px;
}

.board {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: minmax(240px, 1fr);
    gap: 14px;
    overflow-x: auto;
    padding-bottom: 8px;
}
.board-col {
    display: flex;
    flex-direction: column;
    border-radius: 16px;
    background: rgba(var(--v-theme-on-surface), 0.03);
    border: 1px solid rgb(var(--v-theme-hairline));
    min-height: 320px;
}
.board-col__head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 14px;
    font-size: 0.875rem;
}
.font-weight-semibold {
    font-weight: 600;
}
.col-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
}
.col-count {
    min-width: 22px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    background: rgb(var(--v-theme-surface));
    border: 1px solid rgb(var(--v-theme-hairline));
}
.board-col__body {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 0 10px 12px;
}
.board-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 28px 8px;
    font-size: 0.8125rem;
    color: rgb(var(--v-theme-ink-muted));
    border: 1px dashed rgb(var(--v-theme-hairline));
    border-radius: 12px;
    opacity: 0.85;
}
.add-task {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 10px;
    border-radius: 12px;
    border: 1px dashed rgba(var(--v-theme-primary), 0.45);
    color: rgb(var(--v-theme-primary));
    font-size: 0.875rem;
    font-weight: 500;
    transition: background 0.12s ease;
}
.add-task:hover {
    background: rgba(var(--v-theme-primary), 0.06);
}

.member-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
}
.member-card {
    padding: 16px;
    border: 1px solid rgb(var(--v-theme-hairline));
    border-radius: 16px;
    background: rgb(var(--v-theme-surface));
}

@media (max-width: 960px) {
    .hero__grid {
        grid-template-columns: minmax(0, 1fr);
    }
    .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 600px) {
    .view-toggle {
        width: 100%;
    }
    .view-toggle .v-btn {
        flex: 1;
    }
    .toolbar__search,
    .toolbar__select {
        max-width: none;
        flex: 1 1 100%;
    }
    .hero__title {
        font-size: 1.375rem;
    }
}
</style>
