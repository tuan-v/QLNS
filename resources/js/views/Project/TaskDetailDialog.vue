<template>
    <v-dialog :model-value="modelValue" :max-width="900" :fullscreen="mobile" scrollable @update:model-value="close">
        <v-card class="task-sheet" :rounded="mobile ? 0 : 'xl'">
            <!-- ================= Đầu trang ================= -->
            <div class="task-sheet__head px-6 pt-5 pb-4">
                <div class="d-flex align-center ga-2 mb-2">
                    <v-icon icon="mdi-folder-outline" size="16" class="text-medium-emphasis" />
                    <router-link
                        v-if="task?.project && showProjectLink"
                        :to="{ name: 'project-detail', params: { id: task.project.id } }"
                        class="text-caption text-medium-emphasis text-decoration-none breadcrumb-link"
                        @click="close"
                    >
                        {{ task.project.code }} · {{ task.project.name }}
                    </router-link>
                    <span v-else class="text-caption text-medium-emphasis">
                        {{ task?.project ? `${task.project.code} · ${task.project.name}` : "Công việc" }}
                    </span>
                    <v-spacer />
                    <v-btn icon="mdi-close" variant="text" size="small" density="comfortable" @click="close" />
                </div>

                <div class="d-flex align-start ga-3">
                    <v-avatar :color="statusMeta.color" variant="tonal" rounded="lg" size="44" class="flex-shrink-0">
                        <v-icon :icon="statusMeta.icon" size="22" />
                    </v-avatar>
                    <div class="min-width-0 flex-grow-1">
                        <h2 class="task-title">{{ task?.title ?? "Đang tải..." }}</h2>
                        <div v-if="task" class="d-flex flex-wrap align-center ga-2 mt-2">
                            <StatusChip :status="task.status" :map="TASK_STATUS_MAP" />
                            <v-chip size="small" :color="priority.color" variant="tonal" :prepend-icon="priority.icon">
                                Ưu tiên {{ priority.label.toLowerCase() }}
                            </v-chip>
                            <v-chip
                                v-if="due"
                                size="small"
                                :color="due.color"
                                variant="tonal"
                                prepend-icon="mdi-clock-alert-outline"
                            >
                                {{ due.text }}
                            </v-chip>
                            <v-chip
                                v-if="task.return_count"
                                size="small"
                                color="warning"
                                variant="tonal"
                                prepend-icon="mdi-undo-variant"
                            >
                                Trả lại {{ task.return_count }} lần
                            </v-chip>
                        </div>
                    </div>
                </div>

                <!-- Tiến trình: Cần làm → Đang làm → Chờ duyệt → Hoàn thành -->
                <div v-if="task && task.status !== 'cancelled'" class="flow mt-5">
                    <div
                        v-for="(step, index) in FLOW"
                        :key="step.key"
                        class="flow__step"
                        :class="{ 'flow__step--done': index < stepIndex, 'flow__step--current': index === stepIndex }"
                    >
                        <div class="flow__dot">
                            <v-icon
                                v-if="index < stepIndex || (index === stepIndex && step.key === 'done')"
                                icon="mdi-check"
                                size="14"
                            />
                            <span v-else>{{ index + 1 }}</span>
                        </div>
                        <div class="flow__label">{{ step.label }}</div>
                    </div>
                </div>
                <v-alert v-else-if="task" type="info" variant="tonal" density="compact" class="mt-4" icon="mdi-cancel">
                    Công việc đã hủy — không tính vào KPI, giữ lại để xem lịch sử.
                </v-alert>
            </div>

            <v-divider />

            <v-card-text class="pa-0">
                <v-progress-linear v-if="loading" indeterminate color="primary" />
                <v-alert v-if="error" type="error" variant="tonal" density="compact" class="ma-4">{{ error }}</v-alert>

                <div v-if="task" class="task-body">
                    <!-- ================= Cột nội dung ================= -->
                    <div class="task-main pa-6">
                        <!-- Gợi ý bước tiếp theo cho đúng người đang xem -->
                        <div v-if="nextHint && !mode" class="next-hint mb-5">
                            <v-icon :icon="nextHint.icon" :color="nextHint.color" size="20" />
                            <div>
                                <div class="font-weight-medium">{{ nextHint.title }}</div>
                                <div class="text-caption text-medium-emphasis">{{ nextHint.text }}</div>
                            </div>
                        </div>

                        <!-- Ô nhập cho thao tác đang chọn -->
                        <div v-if="mode" class="action-panel mb-5" :class="`action-panel--${modeMeta.color}`">
                            <div class="d-flex align-center ga-2 mb-3">
                                <v-icon :icon="modeMeta.icon" :color="modeMeta.color" />
                                <div class="font-weight-medium">{{ modeMeta.title }}</div>
                            </div>
                            <div v-if="modeMeta.hint" class="text-body-2 text-medium-emphasis mb-3">
                                {{ modeMeta.hint }}
                            </div>

                            <template v-if="mode === 'approve'">
                                <div class="mb-3">
                                    <ScoreLevelPicker
                                        v-model="quality"
                                        :levels="QUALITY_LEVELS"
                                        aria-label="Chất lượng công việc"
                                    />
                                    <div class="text-body-2 mt-2" :class="quality ? '' : 'text-medium-emphasis'">
                                        {{
                                            levelOf(QUALITY_LEVELS, quality)?.hint ??
                                            "Chọn mức chất lượng của công việc"
                                        }}
                                    </div>
                                </div>
                            </template>
                            <v-textarea
                                v-if="['submit', 'approve', 'return'].includes(mode)"
                                v-model="note"
                                rows="3"
                                auto-grow
                                density="comfortable"
                                hide-details="auto"
                                :placeholder="modeMeta.placeholder"
                            />
                            <div class="d-flex justify-end ga-2 mt-4">
                                <v-btn variant="text" :disabled="acting" @click="mode = null">Thôi</v-btn>
                                <v-btn
                                    :color="modeMeta.color"
                                    variant="flat"
                                    :prepend-icon="modeMeta.icon"
                                    :loading="acting"
                                    :disabled="(mode === 'approve' && !quality) || (mode === 'return' && !note.trim())"
                                    @click="confirmMode"
                                >
                                    {{ modeMeta.confirm }}
                                </v-btn>
                            </div>
                        </div>

                        <section class="mb-6">
                            <div class="section-label">Mô tả / yêu cầu đầu ra</div>
                            <div v-if="task.description" class="description">{{ task.description }}</div>
                            <div v-else class="text-body-2 text-medium-emphasis font-italic">Chưa có mô tả.</div>
                        </section>

                        <section>
                            <div class="section-label">Hoạt động</div>
                            <div class="timeline">
                                <div v-for="(event, index) in timeline" :key="index" class="timeline__item">
                                    <div class="timeline__icon" :class="`bg-${event.color}`">
                                        <v-icon :icon="event.icon" size="14" color="white" />
                                    </div>
                                    <div class="timeline__content">
                                        <div class="text-body-2">
                                            <span class="font-weight-medium">{{ event.title }}</span>
                                            <span v-if="event.actor" class="text-medium-emphasis">
                                                · {{ event.actor }}</span
                                            >
                                        </div>
                                        <div v-if="event.at" class="text-caption text-medium-emphasis">
                                            {{ formatDateTime(event.at) }}
                                        </div>
                                        <ScoreLevelChip
                                            v-if="event.quality"
                                            :value="event.quality"
                                            :levels="QUALITY_LEVELS"
                                            size="x-small"
                                            class="mt-1"
                                        />
                                        <div v-if="event.note" class="timeline__note">{{ event.note }}</div>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <!-- ================= Cột thông tin ================= -->
                    <aside class="task-side pa-6">
                        <div class="section-label">Chi tiết</div>
                        <div class="side-row">
                            <div class="side-row__label">Người làm</div>
                            <div class="d-flex align-center ga-2 min-width-0">
                                <PersonAvatar :person="task.assignee" :size="28" />
                                <div class="min-width-0">
                                    <div class="text-body-2 font-weight-medium text-truncate">
                                        {{ task.assignee?.full_name ?? "Chưa giao" }}
                                    </div>
                                    <div v-if="task.assignee" class="text-caption text-medium-emphasis">
                                        {{ task.assignee.code }}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="side-row">
                            <div class="side-row__label">Hạn hoàn thành</div>
                            <div class="text-body-2 font-weight-medium" :class="task.is_overdue ? 'text-error' : ''">
                                {{ formatDate(task.due_date) }}
                            </div>
                            <div class="text-caption text-medium-emphasis">{{ dueSentence }}</div>
                        </div>
                        <div v-if="task.start_date" class="side-row">
                            <div class="side-row__label">Ngày bắt đầu</div>
                            <div class="text-body-2">{{ formatDate(task.start_date) }}</div>
                        </div>
                        <div class="side-row">
                            <div class="side-row__label">Độ ưu tiên</div>
                            <div class="d-flex align-center ga-1 text-body-2">
                                <v-icon :icon="priority.icon" :color="priority.color" size="18" />{{ priority.label }}
                            </div>
                        </div>
                        <div class="side-row">
                            <div class="side-row__label">Độ khó</div>
                            <div class="d-flex align-center ga-2">
                                <div class="points-meter">
                                    <span v-for="p in [1, 2, 3, 5, 8]" :key="p" :class="{ active: p <= task.points }" />
                                </div>
                                <span class="text-body-2 font-weight-medium">{{ task.points }} điểm</span>
                            </div>
                            <div class="text-caption text-medium-emphasis">{{ pointsHint }}</div>
                        </div>
                        <div v-if="task.status === 'done'" class="side-row">
                            <div class="side-row__label">Kết quả</div>
                            <div class="d-flex flex-wrap ga-1">
                                <ScoreLevelChip :value="task.quality_score" :levels="QUALITY_LEVELS" />
                                <v-chip
                                    v-if="task.on_time !== null"
                                    size="small"
                                    :color="task.on_time ? 'success' : 'error'"
                                    variant="tonal"
                                    label
                                >
                                    {{ task.on_time ? "Nộp đúng hạn" : "Nộp trễ hạn" }}
                                </v-chip>
                            </div>
                        </div>
                        <div class="side-row">
                            <div class="side-row__label">Người giao</div>
                            <div class="text-body-2">{{ task.creator ?? "—" }}</div>
                        </div>
                    </aside>
                </div>
            </v-card-text>

            <!-- ================= Thanh thao tác ================= -->
            <template v-if="task && !mode && hasActions">
                <v-divider />
                <div class="task-actions px-6 py-3">
                    <v-menu v-if="secondaryActions.length" location="top start">
                        <template #activator="{ props: menuProps }">
                            <v-btn v-bind="menuProps" variant="text" prepend-icon="mdi-dots-horizontal">Thêm</v-btn>
                        </template>
                        <v-list density="compact" min-width="200">
                            <v-list-item
                                v-for="action in secondaryActions"
                                :key="action.key"
                                :prepend-icon="action.icon"
                                :title="action.label"
                                :base-color="action.color"
                                @click="action.run"
                            />
                        </v-list>
                    </v-menu>
                    <v-spacer />
                    <v-btn
                        v-if="task.can?.edit"
                        variant="flat"
                        prepend-icon="mdi-pencil-outline"
                        @click="emit('edit', task)"
                        >Sửa</v-btn
                    >
                    <v-btn
                        v-if="task.can?.start"
                        color="primary"
                        variant="tonal"
                        prepend-icon="mdi-play"
                        :loading="acting"
                        @click="start"
                    >
                        Bắt đầu làm
                    </v-btn>
                    <v-btn
                        v-if="task.can?.submit"
                        color="primary"
                        prepend-icon="mdi-send-check-outline"
                        @click="setMode('submit')"
                    >
                        Nộp kết quả
                    </v-btn>
                    <template v-if="task.can?.review">
                        <v-btn
                            color="warning"
                            variant="tonal"
                            prepend-icon="mdi-undo-variant"
                            @click="setMode('return')"
                            >Trả lại</v-btn
                        >
                        <v-btn color="success" prepend-icon="mdi-check" @click="setMode('approve')">Duyệt</v-btn>
                    </template>
                </div>
            </template>
        </v-card>
    </v-dialog>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import { useDisplay } from "vuetify";
import projectService from "../../services/projectService";
import StatusChip from "../../components/common/StatusChip.vue";
import ScoreLevelPicker from "../../components/common/ScoreLevelPicker.vue";
import ScoreLevelChip from "../../components/common/ScoreLevelChip.vue";
import { QUALITY_LEVELS, levelOf } from "../../composables/scoreLevels";
import PersonAvatar from "./PersonAvatar.vue";
import { useToastStore } from "../../stores/useToastStore";
import {
    TASK_PRIORITY_MAP,
    TASK_STATUS_MAP,
    daysLeft,
    dueLabel,
    formatDate,
    formatDateTime,
} from "../../composables/projectStatus";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    taskId: { type: Number, default: null },
    // Trang "Công việc của tôi": tên dự án thành link.
    showProjectLink: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue", "changed", "edit"]);

const { mobile } = useDisplay();
const toast = useToastStore();
const task = ref(null);
const loading = ref(false);
const error = ref("");
const acting = ref(false);
const mode = ref(null);
const note = ref("");
const quality = ref(0);

const FLOW = [
    { key: "todo", label: "Cần làm" },
    { key: "in_progress", label: "Đang làm" },
    { key: "in_review", label: "Chờ duyệt" },
    { key: "done", label: "Hoàn thành" },
];
const STATUS_ICONS = {
    todo: { icon: "mdi-circle-outline", color: "secondary" },
    in_progress: { icon: "mdi-progress-clock", color: "primary" },
    in_review: { icon: "mdi-timer-sand", color: "warning" },
    done: { icon: "mdi-check-decagram", color: "success" },
    cancelled: { icon: "mdi-cancel", color: "grey" },
};
const MODES = {
    submit: {
        title: "Nộp kết quả để Lead duyệt",
        hint: "Sau khi nộp, công việc chuyển sang “Chờ duyệt”. Lead sẽ duyệt hoặc trả lại kèm lý do.",
        placeholder: "Ghi chú cho người duyệt: link kết quả, những gì đã làm... (không bắt buộc)",
        confirm: "Nộp kết quả",
        icon: "mdi-send-check-outline",
        color: "primary",
    },
    approve: {
        title: "Duyệt hoàn thành",
        hint: "Điểm chất lượng được dùng để tính KPI của người làm.",
        placeholder: "Nhận xét cho người làm (không bắt buộc)",
        confirm: "Duyệt hoàn thành",
        icon: "mdi-check-decagram-outline",
        color: "success",
    },
    return: {
        title: "Trả lại để làm tiếp",
        hint: "Công việc quay về “Đang làm”. Ghi rõ cần sửa gì để người làm biết.",
        placeholder: "Cần sửa gì? (bắt buộc)",
        confirm: "Trả lại",
        icon: "mdi-undo-variant",
        color: "warning",
    },
    cancel: {
        title: "Hủy công việc này?",
        hint: "Việc đã hủy không tính vào KPI nhưng vẫn được giữ lại để xem lịch sử.",
        confirm: "Hủy công việc",
        icon: "mdi-cancel",
        color: "error",
    },
    delete: {
        title: "Xóa hẳn công việc này?",
        hint: "Chỉ dùng khi tạo nhầm — không khôi phục được.",
        confirm: "Xóa công việc",
        icon: "mdi-delete-outline",
        color: "error",
    },
};

const priority = computed(() => TASK_PRIORITY_MAP[task.value?.priority] ?? TASK_PRIORITY_MAP.medium);
const due = computed(() => dueLabel(task.value));
const statusMeta = computed(() => STATUS_ICONS[task.value?.status] ?? STATUS_ICONS.todo);
const stepIndex = computed(() => FLOW.findIndex((s) => s.key === task.value?.status));
const modeMeta = computed(() => MODES[mode.value] ?? {});

const dueSentence = computed(() => {
    const t = task.value;
    if (t.status === "done") {
        return t.on_time ? "Đã nộp đúng hạn" : "Đã nộp sau hạn";
    }
    if (t.status === "cancelled") {
        return "Đã hủy";
    }
    const days = daysLeft(t.due_date);
    if (days < 0) return `Đã quá hạn ${-days} ngày`;
    if (days === 0) return "Hạn chót là hôm nay";
    return `Còn ${days} ngày`;
});

const pointsHint = computed(
    () =>
        ({
            1: "Rất nhỏ — vài giờ",
            2: "Nhỏ — khoảng 1 ngày",
            3: "Vừa — 2–3 ngày",
            5: "Lớn — khoảng 1 tuần",
            8: "Rất lớn — hơn 1 tuần",
        })[task.value?.points] ?? "",
);

// Câu hướng dẫn "bạn cần làm gì tiếp" theo đúng quyền của người đang xem.
const nextHint = computed(() => {
    const t = task.value;
    if (!t) return null;
    if (t.can?.review) {
        return {
            icon: "mdi-account-check-outline",
            color: "warning",
            title: "Công việc đang chờ bạn duyệt",
            text: "Xem kết quả rồi bấm “Duyệt” (chọn mức chất lượng, từ “Chưa đạt” tới “Xuất sắc”) hoặc “Trả lại” kèm lý do.",
        };
    }
    if (t.can?.start) {
        return {
            icon: "mdi-play-circle-outline",
            color: "primary",
            title: "Bạn được giao công việc này",
            text: "Bấm “Bắt đầu làm” khi bắt tay vào việc, làm xong thì “Nộp kết quả”.",
        };
    }
    if (t.can?.submit) {
        return t.return_count
            ? {
                  icon: "mdi-undo-variant",
                  color: "warning",
                  title: "Công việc bị trả lại — cần sửa",
                  text: "Xem lý do ở phần Hoạt động bên dưới, sửa xong thì nộp lại.",
              }
            : {
                  icon: "mdi-progress-clock",
                  color: "primary",
                  title: "Bạn đang làm công việc này",
                  text: "Làm xong bấm “Nộp kết quả” để Lead duyệt.",
              };
    }
    if (t.status === "in_review") {
        return {
            icon: "mdi-timer-sand",
            color: "warning",
            title: "Đang chờ duyệt",
            text: "Kết quả đã nộp, đang chờ người duyệt.",
        };
    }
    return null;
});

// Dòng thời gian dựng từ các mốc đã lưu của công việc.
const timeline = computed(() => {
    const t = task.value;
    const events = [{ icon: "mdi-plus", color: "secondary", title: "Giao việc", actor: t.creator, at: t.created_at }];
    if (t.started_at) {
        events.push({
            icon: "mdi-play",
            color: "primary",
            title: "Bắt đầu làm",
            actor: t.assignee?.full_name,
            at: t.started_at,
        });
    }
    if (t.submitted_at) {
        events.push({
            icon: "mdi-send-check",
            color: "info",
            title: t.return_count ? "Nộp kết quả (lần gần nhất)" : "Nộp kết quả",
            actor: t.assignee?.full_name,
            at: t.submitted_at,
            note: t.submission_note,
        });
    }
    if (t.status === "done") {
        events.push({
            icon: "mdi-check",
            color: "success",
            title: "Duyệt hoàn thành",
            actor: t.reviewer,
            at: t.completed_at,
            quality: t.quality_score,
            note: t.review_note,
        });
    } else if (t.return_count && t.review_note) {
        events.push({
            icon: "mdi-undo-variant",
            color: "warning",
            title: `Bị trả lại (${t.return_count} lần)`,
            actor: t.reviewer,
            note: t.review_note,
        });
    }
    if (t.status === "cancelled") {
        events.push({ icon: "mdi-cancel", color: "grey", title: "Đã hủy" });
    }
    return events;
});

const secondaryActions = computed(() =>
    [
        task.value?.can?.cancel && {
            key: "cancel",
            icon: "mdi-cancel",
            label: "Hủy công việc",
            color: "error",
            run: () => setMode("cancel"),
        },
        task.value?.can?.delete && {
            key: "delete",
            icon: "mdi-delete-outline",
            label: "Xóa (tạo nhầm)",
            color: "error",
            run: () => setMode("delete"),
        },
    ].filter(Boolean),
);

const hasActions = computed(() => {
    const c = task.value?.can ?? {};
    return c.edit || c.start || c.submit || c.review || secondaryActions.value.length;
});

async function load() {
    if (!props.taskId) {
        return;
    }
    loading.value = true;
    error.value = "";
    try {
        task.value = (await projectService.showTask(props.taskId)).data.data;
    } catch (e) {
        task.value = null;
        error.value = e.response?.data?.message ?? "Không tải được công việc.";
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.modelValue, props.taskId],
    ([open]) => {
        if (open) {
            mode.value = null;
            task.value = null;
            load();
        }
    },
    { immediate: true },
);

function setMode(value) {
    mode.value = value;
    note.value = "";
    quality.value = 0;
}

function close() {
    emit("update:modelValue", false);
}

function firstError(e) {
    const errors = e.response?.data?.errors;
    return (errors ? Object.values(errors)[0]?.[0] : e.response?.data?.message) ?? "Thao tác không thành công.";
}

async function run(action, message, { closeAfter = false } = {}) {
    acting.value = true;
    try {
        await action();
        toast.success(message);
        emit("changed");
        mode.value = null;
        if (closeAfter) {
            close();
        } else {
            // Tải lại bản đầy đủ (người giao, người duyệt...) để dòng thời gian đúng.
            await load();
        }
    } catch (e) {
        toast.error(firstError(e));
    } finally {
        acting.value = false;
    }
}

function start() {
    run(() => projectService.startTask(task.value.id), "Đã bắt đầu công việc.");
}

function confirmMode() {
    const id = task.value.id;
    const actions = {
        submit: () => run(() => projectService.submitTask(id, note.value || null), "Đã nộp — chờ Lead duyệt."),
        approve: () =>
            run(
                () =>
                    projectService.reviewTask(id, {
                        decision: "approve",
                        quality_score: quality.value,
                        note: note.value || null,
                    }),
                "Đã duyệt công việc.",
            ),
        return: () =>
            run(() => projectService.reviewTask(id, { decision: "return", note: note.value }), "Đã trả lại công việc."),
        cancel: () => run(() => projectService.cancelTask(id), "Đã hủy công việc."),
        delete: () => run(() => projectService.removeTask(id), "Đã xóa công việc.", { closeAfter: true }),
    };
    actions[mode.value]?.();
}
</script>

<style scoped>
.task-title {
    font-size: 1.375rem;
    font-weight: 600;
    line-height: 1.35;
    color: rgb(var(--v-theme-ink));
    word-break: break-word;
}
.breadcrumb-link:hover {
    color: rgb(var(--v-theme-primary)) !important;
}

/* Tiến trình 4 bước */
.flow {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    position: relative;
}
.flow__step {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    position: relative;
}
.flow__step:not(:last-child)::after {
    content: "";
    position: absolute;
    top: 13px;
    left: calc(50% + 18px);
    right: calc(-50% + 18px);
    height: 2px;
    background: rgb(var(--v-theme-hairline));
}
.flow__step--done:not(:last-child)::after {
    background: rgb(var(--v-theme-success));
}
.flow__dot {
    width: 28px;
    height: 28px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 600;
    border: 2px solid rgb(var(--v-theme-hairline));
    color: rgb(var(--v-theme-ink-muted));
    background: rgb(var(--v-theme-surface));
    z-index: 1;
}
.flow__step--done .flow__dot {
    background: rgb(var(--v-theme-success));
    border-color: rgb(var(--v-theme-success));
    color: #fff;
}
.flow__step--current .flow__dot {
    border-color: rgb(var(--v-theme-primary));
    color: rgb(var(--v-theme-primary));
    box-shadow: 0 0 0 4px rgba(var(--v-theme-primary), 0.12);
}
.flow__step--current:last-child .flow__dot {
    background: rgb(var(--v-theme-success));
    border-color: rgb(var(--v-theme-success));
    color: #fff;
    box-shadow: 0 0 0 4px rgba(var(--v-theme-success), 0.14);
}
.flow__label {
    font-size: 12px;
    color: rgb(var(--v-theme-ink-muted));
    text-align: center;
}
.flow__step--current .flow__label {
    color: rgb(var(--v-theme-ink));
    font-weight: 600;
}

/* Thân: 2 cột */
.task-body {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 260px;
}
.task-side {
    border-left: 1px solid rgb(var(--v-theme-hairline));
    background: rgba(var(--v-theme-on-surface), 0.015);
}
.section-label {
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: rgb(var(--v-theme-ink-muted));
    margin-bottom: 10px;
}
.description {
    white-space: pre-line;
    font-size: 0.9375rem;
    line-height: 1.6;
    color: rgb(var(--v-theme-ink));
}
.side-row {
    padding: 10px 0;
    border-bottom: 1px dashed rgb(var(--v-theme-hairline));
}
.side-row:last-child {
    border-bottom: 0;
}
.side-row__label {
    font-size: 12px;
    color: rgb(var(--v-theme-ink-muted));
    margin-bottom: 4px;
}
.points-meter {
    display: inline-flex;
    gap: 3px;
}
.points-meter span {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    background: rgb(var(--v-theme-hairline));
}
.points-meter span.active {
    background: rgb(var(--v-theme-primary));
}

.next-hint {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid rgb(var(--v-theme-hairline));
    background: rgba(var(--v-theme-primary), 0.04);
}
.action-panel {
    padding: 16px;
    border-radius: 12px;
    border: 1px solid rgb(var(--v-theme-hairline));
}
.action-panel--primary {
    background: rgba(var(--v-theme-primary), 0.04);
    border-color: rgba(var(--v-theme-primary), 0.3);
}
.action-panel--success {
    background: rgba(var(--v-theme-success), 0.05);
    border-color: rgba(var(--v-theme-success), 0.35);
}
.action-panel--warning {
    background: rgba(var(--v-theme-warning), 0.06);
    border-color: rgba(var(--v-theme-warning), 0.4);
}
.action-panel--error {
    background: rgba(var(--v-theme-error), 0.05);
    border-color: rgba(var(--v-theme-error), 0.35);
}

/* Dòng thời gian */
.timeline__item {
    display: flex;
    gap: 12px;
    position: relative;
    padding-bottom: 16px;
}
.timeline__item:not(:last-child)::before {
    content: "";
    position: absolute;
    left: 11px;
    top: 26px;
    bottom: 0;
    width: 2px;
    background: rgb(var(--v-theme-hairline));
}
.timeline__icon {
    width: 24px;
    height: 24px;
    border-radius: 999px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.timeline__content {
    min-width: 0;
    flex: 1;
}
.timeline__note {
    margin-top: 6px;
    padding: 8px 12px;
    border-radius: 8px;
    background: rgba(var(--v-theme-on-surface), 0.04);
    font-size: 0.875rem;
    white-space: pre-line;
    word-break: break-word;
}
.task-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}

@media (max-width: 760px) {
    .task-body {
        grid-template-columns: minmax(0, 1fr);
    }
    .task-side {
        border-left: 0;
        border-top: 1px solid rgb(var(--v-theme-hairline));
    }
    .flow__label {
        font-size: 11px;
    }
}
</style>
