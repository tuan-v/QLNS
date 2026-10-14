<template>
    <div
        class="task-card"
        :class="[`task-card--${task.priority}`, { 'task-card--cancelled': task.status === 'cancelled' }]"
        role="button"
        tabindex="0"
        @click="emit('open', task)"
        @keydown.enter="emit('open', task)"
    >
        <div class="d-flex align-center ga-2 mb-2">
            <span class="priority-pill" :class="`priority-pill--${task.priority}`">
                <v-icon :icon="priority.icon" size="14" />{{ priority.label }}
            </span>
            <v-spacer />
            <v-tooltip v-if="task.return_count && task.status !== 'done'" text="Đã bị trả lại — cần sửa" location="top">
                <template #activator="{ props: tipProps }">
                    <v-icon v-bind="tipProps" icon="mdi-undo-variant" size="16" color="warning" />
                </template>
            </v-tooltip>
            <span class="points-badge" :title="`Độ khó ${task.points} điểm`">{{ task.points }}</span>
        </div>

        <div class="task-card__title">{{ task.title }}</div>
        <div v-if="task.description" class="task-card__desc">{{ task.description }}</div>

        <div class="task-card__foot">
            <span v-if="due" class="due-tag" :class="`due-tag--${due.color}`">
                <v-icon icon="mdi-clock-outline" size="13" />{{ due.text }}
            </span>
            <span v-else-if="task.status === 'done'" class="d-inline-flex align-center">
                <v-rating
                    v-if="task.quality_score"
                    :model-value="task.quality_score"
                    readonly
                    density="compact"
                    size="x-small"
                    color="amber"
                    active-color="amber"
                />
            </span>
            <span v-else class="due-tag">
                <v-icon icon="mdi-calendar-blank-outline" size="13" />{{ shortDate(task.due_date) }}
            </span>
            <v-spacer />
            <PersonAvatar :person="task.assignee" :size="26" :tooltip="task.assignee?.full_name ?? 'Chưa giao'" />
        </div>
    </div>
</template>

<script setup>
import { computed } from "vue";
import PersonAvatar from "./PersonAvatar.vue";
import { TASK_PRIORITY_MAP, dueLabel } from "../../composables/projectStatus";

// Thẻ công việc trên bảng Kanban — viền trái màu theo độ ưu tiên.
const props = defineProps({
    task: { type: Object, required: true },
});
const emit = defineEmits(["open"]);

const priority = computed(() => TASK_PRIORITY_MAP[props.task.priority] ?? TASK_PRIORITY_MAP.medium);
const due = computed(() => dueLabel(props.task));

function shortDate(value) {
    return value ? new Date(value).toLocaleDateString("vi-VN", { day: "2-digit", month: "2-digit" }) : "";
}
</script>

<style scoped>
.task-card {
    position: relative;
    padding: 12px 12px 10px 14px;
    border-radius: 12px;
    border: 1px solid rgb(var(--v-theme-hairline));
    background: rgb(var(--v-theme-surface));
    box-shadow: var(--qlns-shadow-sm);
    cursor: pointer;
    transition:
        transform 0.12s ease,
        box-shadow 0.12s ease,
        border-color 0.12s ease;
}
.task-card::before {
    content: "";
    position: absolute;
    left: 0;
    top: 10px;
    bottom: 10px;
    width: 3px;
    border-radius: 0 3px 3px 0;
    background: rgb(var(--v-theme-hairline));
}
.task-card--medium::before {
    background: rgb(var(--v-theme-info));
}
.task-card--high::before {
    background: rgb(var(--v-theme-warning));
}
.task-card--urgent::before {
    background: rgb(var(--v-theme-error));
}
.task-card:hover,
.task-card:focus-visible {
    transform: translateY(-1px);
    border-color: rgba(var(--v-theme-primary), 0.45);
    box-shadow: var(--qlns-shadow-lg);
    outline: none;
}
.task-card--cancelled {
    opacity: 0.55;
}
.task-card--cancelled .task-card__title {
    text-decoration: line-through;
}
.task-card__title {
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.4;
    color: rgb(var(--v-theme-ink));
    word-break: break-word;
}
.task-card__desc {
    margin-top: 4px;
    font-size: 0.8125rem;
    line-height: 1.45;
    color: rgb(var(--v-theme-ink-muted));
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.task-card__foot {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 10px;
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
    min-width: 22px;
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
.due-tag {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 12px;
    color: rgb(var(--v-theme-ink-muted));
}
.due-tag--error {
    color: rgb(var(--v-theme-error));
    font-weight: 600;
}
.due-tag--warning {
    color: rgb(var(--v-theme-warning));
    font-weight: 600;
}
</style>
