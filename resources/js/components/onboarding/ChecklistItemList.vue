<template>
    <!-- Danh sách việc của 1 checklist, nhóm theo người phụ trách. Dùng chung cho
         ngăn kéo chi tiết (HR/Quản lý) và thẻ "Việc cần làm" của nhân viên. -->
    <div class="d-flex flex-column ga-4">
        <section v-for="group in groups" :key="group.responsible">
            <div class="d-flex align-center ga-2 mb-1 text-caption font-weight-bold text-uppercase" style="opacity: 0.7">
                <v-icon :icon="RESPONSIBLE_ICONS[group.responsible]" size="16" />
                {{ RESPONSIBLE_LABELS[group.responsible] }}
                <span class="font-weight-regular">· {{ group.done }}/{{ group.items.length }}</span>
            </div>

            <div
                v-for="item in group.items"
                :key="item.id"
                class="checklist-item d-flex align-start ga-2 py-2"
            >
                <v-checkbox-btn
                    :model-value="!!item.completed_at"
                    :disabled="!item.can_toggle || busyItemId === item.id"
                    color="success"
                    density="compact"
                    class="flex-grow-0 mt-n1"
                    @update:model-value="(value) => $emit('toggle', item, value)"
                />
                <div class="flex-grow-1 min-width-0">
                    <div :class="['text-body-2 font-weight-medium', { 'text-decoration-line-through checklist-item--done': item.completed_at }]">
                        {{ item.title }}
                        <v-progress-circular v-if="busyItemId === item.id" indeterminate size="12" width="2" class="ml-1" />
                    </div>
                    <div v-if="item.description" class="text-caption" style="opacity: 0.7">{{ item.description }}</div>
                    <div class="d-flex flex-wrap align-center ga-1 mt-1">
                        <template v-if="item.completed_at">
                            <span class="text-caption text-success">
                                <v-icon icon="mdi-check" size="14" />
                                {{ item.completed_by ? `${formatDateTime(item.completed_at)} · ${item.completed_by}` : "Hệ thống tự ghi nhận" }}
                            </span>
                        </template>
                        <template v-else-if="item.due_date">
                            <v-chip
                                :color="item.is_overdue ? 'error' : dueSoon(item) ? 'warning' : undefined"
                                size="x-small"
                                variant="tonal"
                                prepend-icon="mdi-calendar-clock"
                            >
                                {{ dueLabel(item) }}
                            </v-chip>
                        </template>
                        <v-chip v-if="!item.is_required" size="x-small" variant="outlined">Không bắt buộc</v-chip>
                        <v-chip
                            v-if="item.auto_key && !item.completed_at"
                            size="x-small"
                            variant="tonal"
                            color="primary"
                            prepend-icon="mdi-robot-outline"
                        >
                            Tự đánh dấu {{ (AUTO_KEY_LABELS[item.auto_key] ?? "").toLowerCase() }}
                        </v-chip>
                    </div>
                </div>
                <v-btn
                    v-if="canManage && !readonly"
                    icon="mdi-close"
                    variant="text"
                    size="x-small"
                    :disabled="busyItemId === item.id"
                    @click="$emit('remove', item)"
                >
                    <v-icon icon="mdi-close" />
                    <v-tooltip activator="parent" location="top">Bỏ việc này</v-tooltip>
                </v-btn>
            </div>
        </section>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { daysUntil } from "../../composables/employeeAlerts";
import { AUTO_KEY_LABELS, RESPONSIBLE_ICONS, RESPONSIBLE_LABELS, formatDate } from "../../composables/onboardingLabels";

const props = defineProps({
    items: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
    // Checklist đã hủy: chỉ xem.
    readonly: { type: Boolean, default: false },
    busyItemId: { type: [Number, null], default: null },
    // Chỉ hiện các nhóm này (vd thẻ của nhân viên chỉ hiện phần "Nhân viên").
    only: { type: Array, default: null },
});

defineEmits(["toggle", "remove"]);

const ORDER = ["hr", "manager", "employee"];

const groups = computed(() =>
    ORDER.filter((responsible) => !props.only || props.only.includes(responsible))
        .map((responsible) => {
            const items = props.items.filter((item) => item.responsible === responsible);
            return { responsible, items, done: items.filter((item) => item.completed_at).length };
        })
        .filter((group) => group.items.length),
);

function dueSoon(item) {
    const days = daysUntil(item.due_date);
    return days !== null && days >= 0 && days <= 2;
}

function dueLabel(item) {
    const days = daysUntil(item.due_date);
    if (days < 0) return `Quá hạn ${-days} ngày`;
    if (days === 0) return "Hạn hôm nay";
    return `Hạn ${formatDate(item.due_date)}`;
}

// "07/10" — tự ghép, vì toLocaleDateString("vi-VN") với chỉ ngày + tháng ra "07-10".
function formatDateTime(value) {
    const date = new Date(value);
    const pad = (n) => String(n).padStart(2, "0");
    return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}`;
}
</script>

<style scoped>
.checklist-item + .checklist-item {
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.checklist-item--done {
    opacity: 0.6;
}
</style>
