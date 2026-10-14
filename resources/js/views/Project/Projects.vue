<template>
    <div>
        <PageHeader
            title="Dự án"
            :subtitle="
                canManage
                    ? 'Tạo dự án, chọn Lead — Lead thêm thành viên, giao việc và duyệt kết quả.'
                    : 'Các dự án bạn đang tham gia.'
            "
        >
            <template #actions>
                <v-btn
                    variant="flat"
                    size="large"
                    prepend-icon="mdi-checkbox-marked-circle-outline"
                    :to="{ name: 'my-tasks' }"
                >
                    Công việc của tôi
                </v-btn>
                <v-btn v-if="canManage" color="primary" size="large" prepend-icon="mdi-plus" @click="openCreate"
                    >Tạo dự án</v-btn
                >
            </template>
        </PageHeader>

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

        <!-- Tổng quan nhanh -->
        <div class="summary-grid mb-4">
            <div v-for="item in summary" :key="item.label" class="summary-tile glass-panel">
                <v-avatar :color="item.color" variant="tonal" rounded="lg" size="40"
                    ><v-icon :icon="item.icon"
                /></v-avatar>
                <div>
                    <div class="text-caption text-medium-emphasis">{{ item.label }}</div>
                    <div class="text-h6 font-weight-bold">{{ item.value }}</div>
                </div>
            </div>
        </div>

        <div class="filters mb-4">
            <SearchField v-model="search" placeholder="Tìm tên hoặc mã dự án..." />
            <!-- Bấm lại chip đang chọn = bỏ lọc (xem tất cả). -->
            <v-chip-group v-model="statusFilter" selected-class="text-primary" class="status-chips">
                <v-chip
                    v-for="option in statusOptions"
                    :key="option.value"
                    :value="option.value"
                    variant="outlined"
                    filter
                >
                    {{ option.title }}
                </v-chip>
            </v-chip-group>
            <v-spacer />
            <v-switch
                v-if="canManage"
                v-model="onlyMine"
                color="primary"
                density="compact"
                hide-details
                inset
                label="Chỉ dự án tôi tham gia"
                class="flex-grow-0"
            />
        </div>

        <v-progress-linear v-if="loading && !projects.length" indeterminate color="primary" class="mb-4" />

        <!-- Lưới thẻ dự án -->
        <div v-if="projects.length" class="project-grid">
            <article
                v-for="project in projects"
                :key="project.id"
                class="project-card glass-panel"
                role="link"
                tabindex="0"
                @click="open(project)"
                @keydown.enter="open(project)"
            >
                <div class="d-flex align-center ga-2">
                    <span class="code-tag">{{ project.code }}</span>
                    <StatusChip :status="project.status" :map="PROJECT_STATUS_MAP" />
                    <v-spacer />
                    <v-chip
                        v-if="project.my_role === 'lead'"
                        size="x-small"
                        color="primary"
                        variant="tonal"
                        prepend-icon="mdi-star-outline"
                        >Lead</v-chip
                    >
                    <v-menu v-if="canManage" location="bottom end">
                        <template #activator="{ props: menuProps }">
                            <v-btn
                                v-bind="menuProps"
                                icon="mdi-dots-horizontal"
                                variant="text"
                                size="small"
                                density="comfortable"
                                @click.stop
                            />
                        </template>
                        <v-list density="compact" min-width="180">
                            <v-list-item prepend-icon="mdi-pencil-outline" title="Sửa dự án" @click="edit(project)" />
                            <v-list-item
                                prepend-icon="mdi-delete-outline"
                                title="Xóa dự án"
                                base-color="error"
                                :disabled="project.stats.done > 0"
                                :subtitle="project.stats.done > 0 ? 'Có việc đã xong — đổi sang “Đã hủy”' : ''"
                                @click="confirmDelete = project"
                            />
                        </v-list>
                    </v-menu>
                </div>

                <h3 class="project-card__name">{{ project.name }}</h3>
                <p class="project-card__desc">{{ project.description || "Chưa có mô tả." }}</p>

                <div class="mt-auto">
                    <div class="d-flex justify-space-between align-end mb-1">
                        <span class="text-caption text-medium-emphasis">Tiến độ</span>
                        <span class="font-weight-bold">{{ project.stats.progress }}%</span>
                    </div>
                    <v-progress-linear
                        :model-value="project.stats.progress"
                        :color="project.stats.progress >= 100 ? 'success' : 'primary'"
                        bg-color="hairline"
                        bg-opacity="1"
                        height="8"
                        rounded
                    />
                    <div class="mini-stats mt-3">
                        <span
                            ><v-icon icon="mdi-check-all" size="15" color="success" />{{ project.stats.done }}/{{
                                project.stats.total
                            }}
                            việc</span
                        >
                        <span v-if="project.stats.in_review" class="text-warning">
                            <v-icon icon="mdi-timer-sand" size="15" />{{ project.stats.in_review }} chờ duyệt
                        </span>
                        <span v-if="project.stats.overdue" class="text-error">
                            <v-icon icon="mdi-clock-alert-outline" size="15" />{{ project.stats.overdue }} quá hạn
                        </span>
                    </div>
                </div>

                <div class="project-card__foot">
                    <div class="d-flex align-center ga-2 min-width-0">
                        <PersonAvatar :person="project.lead" :size="30" />
                        <div class="min-width-0">
                            <div class="text-caption text-medium-emphasis">Lead</div>
                            <div class="text-body-2 font-weight-medium text-truncate">
                                {{ project.lead?.full_name }}
                            </div>
                        </div>
                    </div>
                    <AvatarStack :people="(project.members ?? []).map((m) => m.employee)" :max="4" :size="26" />
                </div>
                <div class="project-card__dates">
                    <v-icon icon="mdi-calendar-range" size="15" />
                    {{ formatDate(project.start_date) }} –
                    {{ project.end_date ? formatDate(project.end_date) : "chưa xác định" }}
                    <span
                        v-if="deadline(project)"
                        :class="deadline(project).color ? `text-${deadline(project).color}` : ''"
                    >
                        · {{ deadline(project).text }}
                    </span>
                </div>
            </article>
        </div>

        <!-- Trống -->
        <div v-else-if="!loading" class="empty glass-panel">
            <v-avatar color="primary" variant="tonal" size="64" class="mb-3"
                ><v-icon icon="mdi-folder-search-outline" size="32"
            /></v-avatar>
            <div class="text-h6 mb-1">{{ hasFilter ? "Không có dự án phù hợp" : "Chưa có dự án nào" }}</div>
            <div class="text-body-2 text-medium-emphasis mb-4">
                {{
                    hasFilter
                        ? "Thử đổi từ khóa hoặc bộ lọc trạng thái."
                        : canManage
                          ? "Tạo dự án đầu tiên và chọn Lead để bắt đầu giao việc."
                          : "Khi được thêm vào dự án, bạn sẽ thấy dự án ở đây."
                }}
            </div>
            <v-btn v-if="canManage && !hasFilter" color="primary" prepend-icon="mdi-plus" @click="openCreate"
                >Tạo dự án</v-btn
            >
        </div>

        <ProjectForm v-model="formDialog" :project="editing" :department-options="departmentOptions" @saved="onSaved" />

        <v-dialog :model-value="!!confirmDelete" max-width="440" @update:model-value="confirmDelete = null">
            <v-card rounded="lg" :title="`Xóa dự án “${confirmDelete?.name}”?`">
                <v-card-text>Toàn bộ thành viên và công việc chưa hoàn thành của dự án sẽ bị xóa.</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="confirmDelete = null">Thôi</v-btn>
                    <v-btn color="error" :loading="deleting" @click="remove">Xóa dự án</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import projectService from "../../services/projectService";
import { useAuthStore } from "../../stores/authStore";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import { useToastStore } from "../../stores/useToastStore";
import PageHeader from "../../components/common/PageHeader.vue";
import SearchField from "../../components/common/SearchField.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import ProjectForm from "./ProjectForm.vue";
import PersonAvatar from "./PersonAvatar.vue";
import AvatarStack from "./AvatarStack.vue";
import { PROJECT_STATUS_MAP, daysLeft, formatDate, toOptions } from "../../composables/projectStatus";
import { useRememberedRef } from "../../composables/useRememberedRef";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";
import { useRouteAction } from "../../composables/useRouteAction";

const router = useRouter();
const auth = useAuthStore();
const departmentStore = useDepartmentStore();
const toast = useToastStore();

const canManage = computed(() => auth.permissions.includes("project.manage"));

const projects = ref([]);
const loading = ref(false);
const loadError = ref("");
const search = ref("");
const statusFilter = useRememberedRef("projects.status", null);
const onlyMine = useRememberedRef("projects.only-mine", false);
const formDialog = ref(false);
const editing = ref(null);
const confirmDelete = ref(null);
const deleting = ref(false);

const statusOptions = toOptions(PROJECT_STATUS_MAP);
const hasFilter = computed(() => !!search.value || !!statusFilter.value);

const summary = computed(() => {
    const list = projects.value;
    return [
        {
            label: "Dự án đang thực hiện",
            value: list.filter((p) => p.status === "active").length,
            icon: "mdi-rocket-launch-outline",
            color: "primary",
        },
        {
            label: "Việc chờ duyệt",
            value: list.reduce((s, p) => s + p.stats.in_review, 0),
            icon: "mdi-timer-sand",
            color: "warning",
        },
        {
            label: "Việc quá hạn",
            value: list.reduce((s, p) => s + p.stats.overdue, 0),
            icon: "mdi-clock-alert-outline",
            color: "error",
        },
        {
            label: "Dự án hoàn thành",
            value: list.filter((p) => p.status === "completed").length,
            icon: "mdi-flag-checkered",
            color: "success",
        },
    ];
});

function flatten(nodes) {
    return nodes.flatMap((node) => [node, ...flatten(node.children ?? [])]);
}
const departmentOptions = computed(() => flatten(departmentStore.tree).map((d) => ({ title: d.name, value: d.id })));

function deadline(project) {
    if (!project.end_date || ["completed", "cancelled"].includes(project.status)) {
        return null;
    }
    const days = daysLeft(project.end_date);
    if (days < 0) return { text: `trễ ${-days} ngày`, color: "error" };
    if (days === 0) return { text: "kết thúc hôm nay", color: "warning" };
    return { text: `còn ${days} ngày`, color: days <= 7 ? "warning" : null };
}

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await projectService.list({
            status: statusFilter.value || undefined,
            search: search.value || undefined,
            mine: canManage.value && onlyMine.value ? 1 : undefined,
        });
        projects.value = response.data.data;
    } catch (e) {
        projects.value = [];
        loadError.value = e.response?.data?.message ?? "Không thể tải danh sách dự án.";
    } finally {
        loading.value = false;
    }
}

function open(project) {
    router.push({ name: "project-detail", params: { id: project.id } });
}

function openCreate() {
    editing.value = null;
    formDialog.value = true;
}

function edit(project) {
    editing.value = project;
    formDialog.value = true;
}

function onSaved(project) {
    if (!editing.value && project?.id) {
        open(project);
        return;
    }
    loadData();
}

async function remove() {
    deleting.value = true;
    try {
        await projectService.remove(confirmDelete.value.id);
        toast.success("Đã xóa dự án.");
        confirmDelete.value = null;
    } catch (e) {
        const errors = e.response?.data?.errors;
        toast.error((errors ? Object.values(errors)[0]?.[0] : e.response?.data?.message) ?? "Không xóa được dự án.");
    } finally {
        deleting.value = false;
    }
    await loadData();
}

useRealtimeRefresh(loadData, { shared: ["projects"] });

watch([search, statusFilter, onlyMine], loadData);

useRouteAction({ create: () => canManage.value && openCreate() });

onMounted(() => {
    loadData();
    if (canManage.value) {
        departmentStore.fetchTree();
    }
});
</script>

<style scoped>
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}
.summary-tile {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    border: 1px solid rgb(var(--v-theme-hairline));
}
.filters {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 16px;
}
.status-chips {
    max-width: 100%;
}
.project-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 16px;
}
.project-card {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 18px;
    border: 1px solid rgb(var(--v-theme-hairline));
    cursor: pointer;
    min-height: 300px;
    transition:
        transform 0.12s ease,
        border-color 0.12s ease,
        box-shadow 0.12s ease;
}
.project-card:hover,
.project-card:focus-visible {
    transform: translateY(-2px);
    border-color: rgba(var(--v-theme-primary), 0.45);
    box-shadow: var(--qlns-shadow-lg) !important;
    outline: none;
}
.project-card__name {
    font-size: 1.0625rem;
    font-weight: 600;
    line-height: 1.4;
    color: rgb(var(--v-theme-ink));
    word-break: break-word;
}
.project-card__desc {
    font-size: 0.875rem;
    line-height: 1.5;
    color: rgb(var(--v-theme-ink-muted));
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.6em;
}
.mini-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 14px;
    font-size: 12.5px;
    color: rgb(var(--v-theme-ink-muted));
}
.mini-stats span {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.project-card__foot {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding-top: 12px;
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.project-card__dates {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
    font-size: 12.5px;
    color: rgb(var(--v-theme-ink-muted));
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
.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 48px 16px;
    border: 1px dashed rgb(var(--v-theme-hairline));
}
@media (max-width: 960px) {
    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
@media (max-width: 400px) {
    .project-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
