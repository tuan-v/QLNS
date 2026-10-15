<template>
    <v-sheet class="border rounded-lg glass-panel pa-0 h-100 d-flex flex-column">
        <div class="d-flex align-center px-5 pt-4 pb-3">
            <v-icon size="18" color="error" class="mr-2">mdi-folder-alert-outline</v-icon>
            <span class="text-body-1 font-weight-bold">Dự án cần chú ý</span>
            <span v-if="projects.length" class="count-badge ml-2">{{ projects.length }}</span>
            <v-spacer />
            <v-btn
                variant="text"
                color="primary"
                size="small"
                :to="{ name: 'projects' }"
                append-icon="mdi-chevron-right"
                >Dự án</v-btn
            >
        </div>
        <v-divider />

        <div v-if="projects.length" class="flex-grow-1">
            <div
                v-for="project in projects"
                :key="project.id"
                class="project-row px-5 py-3"
                role="link"
                tabindex="0"
                @click="router.push({ name: 'project-detail', params: { id: project.id } })"
                @keydown.enter="router.push({ name: 'project-detail', params: { id: project.id } })"
            >
                <div class="min-width-0 flex-grow-1">
                    <div class="d-flex align-center ga-2">
                        <span class="code-tag">{{ project.code }}</span>
                        <span class="text-body-2 font-weight-bold text-truncate">{{ project.name }}</span>
                    </div>
                    <div class="text-caption text-medium-emphasis mt-1">
                        Lead: {{ project.lead ?? "—"
                        }}<template v-if="project.end_date"> · kết thúc {{ formatDate(project.end_date) }}</template>
                        <template v-if="project.review_tasks"> · {{ project.review_tasks }} việc chờ duyệt</template>
                    </div>
                </div>
                <div class="d-flex flex-wrap justify-end ga-1 reasons">
                    <v-chip
                        v-for="reason in project.reasons"
                        :key="reason.key"
                        size="x-small"
                        :color="reason.color"
                        variant="tonal"
                    >
                        {{ reason.label }}
                    </v-chip>
                </div>
            </div>
        </div>
        <div v-else class="empty flex-grow-1">
            <v-icon icon="mdi-check-circle-outline" size="28" color="success" class="mb-1" />
            <div class="text-body-2">Mọi dự án đang đúng tiến độ</div>
        </div>
    </v-sheet>
</template>

<script setup>
import { useRouter } from "vue-router";
import { formatDate } from "../../composables/projectStatus";

// "Dự án cần chú ý" cho người quản lý dự án — ProjectDashboardService::attention().
defineProps({
    projects: { type: Array, default: () => [] },
});

const router = useRouter();
</script>

<style scoped>
.project-row {
    display: flex;
    align-items: center;
    gap: 12px;
    cursor: pointer;
    transition: background 0.12s ease;
}
.project-row:hover,
.project-row:focus-visible {
    background: rgba(var(--v-theme-primary), 0.05);
    outline: none;
}
.project-row + .project-row {
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.reasons {
    max-width: 50%;
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
    color: rgb(var(--v-theme-error));
    background: rgba(var(--v-theme-error), 0.12);
}
.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px 16px;
    color: rgb(var(--v-theme-ink-muted));
}
@media (max-width: 600px) {
    .project-row {
        flex-wrap: wrap;
    }
    .reasons {
        max-width: none;
        justify-content: flex-start !important;
    }
}
</style>
