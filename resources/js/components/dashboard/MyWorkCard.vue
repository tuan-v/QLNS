<template>
    <v-sheet class="border rounded-lg glass-panel pa-0 h-100 d-flex flex-column">
        <div class="d-flex align-center px-5 pt-4 pb-3">
            <v-icon size="18" color="primary" class="mr-2">mdi-checkbox-marked-circle-outline</v-icon>
            <span class="text-body-1 font-weight-bold">Công việc hôm nay</span>
            <v-spacer />
            <v-btn
                variant="text"
                color="primary"
                size="small"
                :to="{ name: 'my-tasks' }"
                append-icon="mdi-chevron-right"
            >
                Tất cả ({{ work.counts.open }})
            </v-btn>
        </div>

        <div class="count-row px-5 pb-3">
            <div v-for="c in counts" :key="c.key" class="count-pill" :class="{ 'count-pill--zero': !c.value }">
                <span class="count-pill__value" :class="c.value ? `text-${c.color}` : ''">{{ c.value }}</span>
                <span class="count-pill__label">{{ c.label }}</span>
            </div>
        </div>
        <v-divider />

        <div v-if="work.focus.length" class="flex-grow-1">
            <div
                v-for="task in work.focus"
                :key="task.id"
                class="work-row px-5 py-3"
                role="link"
                tabindex="0"
                @click="openTask(task)"
                @keydown.enter="openTask(task)"
            >
                <span class="reason-dot" :class="`bg-${REASONS[task.reason].color}`" />
                <div class="min-width-0 flex-grow-1">
                    <div class="text-body-2 font-weight-medium text-truncate">{{ task.title }}</div>
                    <div class="text-caption text-medium-emphasis text-truncate">
                        {{ task.project.code }} · {{ task.project.name }}
                        <span v-if="task.returned" class="text-warning"> · bị trả lại, cần sửa</span>
                    </div>
                </div>
                <span class="reason-tag" :class="`text-${REASONS[task.reason].color}`">{{ reasonText(task) }}</span>
            </div>
        </div>
        <div v-else class="empty flex-grow-1">
            <v-icon icon="mdi-coffee-outline" size="28" class="mb-1" />
            <div class="text-body-2">Không có việc gấp hôm nay</div>
            <div class="text-caption text-medium-emphasis">
                {{
                    work.counts.open
                        ? `Bạn còn ${work.counts.open} việc đang mở, chưa tới hạn.`
                        : "Bạn chưa được giao việc nào."
                }}
            </div>
        </div>

        <!-- Lead / người quản lý dự án: việc đang chờ mình duyệt -->
        <template v-if="work.to_review.count">
            <v-divider />
            <div class="px-5 pt-3 pb-1 d-flex align-center ga-2">
                <v-icon size="16" color="warning">mdi-timer-sand</v-icon>
                <span class="text-body-2 font-weight-bold">Chờ bạn duyệt</span>
                <span class="count-badge">{{ work.to_review.count }}</span>
            </div>
            <div
                v-for="task in work.to_review.items"
                :key="task.id"
                class="work-row px-5 py-2"
                role="link"
                tabindex="0"
                @click="openTask(task)"
                @keydown.enter="openTask(task)"
            >
                <div class="min-width-0 flex-grow-1">
                    <div class="text-body-2 text-truncate">{{ task.title }}</div>
                    <div class="text-caption text-medium-emphasis text-truncate">
                        {{ task.assignee }} · {{ task.project.code }}
                    </div>
                </div>
                <span class="text-caption text-medium-emphasis flex-shrink-0">{{
                    submittedAgo(task.submitted_at)
                }}</span>
            </div>
            <div class="pb-2" />
        </template>
    </v-sheet>
</template>

<script setup>
import { computed } from "vue";
import { useRouter } from "vue-router";
import { daysLeft } from "../../composables/projectStatus";

// "Công việc hôm nay" trên trang Tổng quan — dữ liệu từ ProjectDashboardService::myWork().
const props = defineProps({
    work: { type: Object, required: true },
});

const router = useRouter();

const REASONS = {
    overdue: { color: "error" },
    due_today: { color: "warning" },
    start_today: { color: "primary" },
    due_soon: { color: "info" },
};

const counts = computed(() => [
    { key: "overdue", label: "Quá hạn", value: props.work.counts.overdue, color: "error" },
    { key: "due_today", label: "Hạn hôm nay", value: props.work.counts.due_today, color: "warning" },
    { key: "start_today", label: "Bắt đầu hôm nay", value: props.work.counts.start_today, color: "primary" },
    { key: "in_progress", label: "Đang làm", value: props.work.counts.in_progress, color: "primary" },
    { key: "waiting_review", label: "Đã nộp, chờ duyệt", value: props.work.counts.waiting_review, color: "warning" },
]);

function reasonText(task) {
    if (task.reason === "overdue") return `Quá hạn ${-daysLeft(task.due_date)} ngày`;
    if (task.reason === "due_today") return "Hạn hôm nay";
    if (task.reason === "start_today") return "Bắt đầu hôm nay";
    const days = daysLeft(task.due_date);
    return days === 1 ? "Hạn ngày mai" : `Còn ${days} ngày`;
}

function submittedAgo(value) {
    if (!value) return "";
    const days = Math.floor((Date.now() - new Date(value).getTime()) / 86400000);
    return days <= 0 ? "nộp hôm nay" : `nộp ${days} ngày trước`;
}

function openTask(task) {
    router.push({ name: "project-detail", params: { id: task.project.id }, query: { task: task.id } });
}
</script>

<style scoped>
.count-row {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 8px;
}
.count-pill {
    display: flex;
    flex-direction: column;
    padding: 8px 10px;
    border-radius: 10px;
    background: rgba(var(--v-theme-on-surface), 0.035);
}
.count-pill__value {
    font-size: 1.25rem;
    font-weight: 700;
    line-height: 1.2;
}
.count-pill--zero .count-pill__value {
    color: rgb(var(--v-theme-ink-muted));
}
.count-pill__label {
    font-size: 11.5px;
    color: rgb(var(--v-theme-ink-muted));
    line-height: 1.3;
}
.work-row {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    transition: background 0.12s ease;
}
.work-row:hover,
.work-row:focus-visible {
    background: rgba(var(--v-theme-primary), 0.05);
    outline: none;
}
.work-row + .work-row {
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.reason-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    flex-shrink: 0;
}
.reason-tag {
    font-size: 12px;
    font-weight: 600;
    flex-shrink: 0;
}
.count-badge {
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: rgb(var(--v-theme-warning));
    background: rgba(var(--v-theme-warning), 0.14);
}
.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 28px 16px;
    color: rgb(var(--v-theme-ink-muted));
}
@media (max-width: 600px) {
    .count-row {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
</style>
