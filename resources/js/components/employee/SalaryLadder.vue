<template>
    <div v-if="!steps.length" class="text-body-2 py-4 text-center" style="opacity: 0.6">Chưa có hợp đồng.</div>
    <div v-else class="ladder" role="list" aria-label="Các bậc lương theo hợp đồng">
        <div v-for="step in steps" :key="step.contract_id" class="ladder__col" role="listitem">
            <!-- Khoảng trống phía trên = bậc thấp hơn bậc cao nhất -> nhìn ra bậc thang lên/xuống -->
            <div class="ladder__spacer" :style="{ flexBasis: `${step.offset}%` }" />
            <div class="ladder__step" :class="{ 'ladder__step--current': step.current }">
                <div class="d-flex align-center justify-space-between ga-2">
                    <span class="text-caption text-uppercase font-weight-bold ladder__type">{{ step.typeLabel }}</span>
                    <v-chip v-if="step.current" size="x-small" color="primary" variant="flat" label
                        >Đang áp dụng</v-chip
                    >
                </div>
                <div class="text-h6 font-weight-bold mt-1">{{ money(step.salary) }}</div>
                <v-chip
                    size="small"
                    label
                    variant="tonal"
                    class="mt-1 font-weight-bold"
                    :color="step.change > 0 ? 'success' : step.change < 0 ? 'error' : undefined"
                    :prepend-icon="
                        step.change > 0
                            ? 'mdi-arrow-up-bold'
                            : step.change < 0
                              ? 'mdi-arrow-down-bold'
                              : 'mdi-flag-outline'
                    "
                >
                    {{ step.changeText }}
                </v-chip>
                <div
                    v-if="step.change"
                    class="text-body-2 mt-2 ladder__reason"
                    :class="{ 'ladder__reason--missing': !step.note }"
                >
                    {{ step.note || "Chưa ghi lý do" }}
                </div>
                <div class="text-caption mt-2 ladder__period">
                    {{ formatMonth(step.start_date) }} → {{ step.end_date ? formatMonth(step.end_date) : "nay" }} ·
                    {{ step.contract_number }}
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from "vue";
import { CONTRACT_TYPE_LABELS } from "../../composables/recruitmentStatus";

// "Bậc lương" của tab Hành trình (2026-10-22): mỗi hợp đồng 1 bậc, cao thấp theo mức lương, ghi +/−% và lý do.
// Điện thoại: xếp dọc (bậc mới nhất trên cùng), bỏ phần chênh cao.
const props = defineProps({
    salaryHistory: { type: Array, default: () => [] },
});

const money = (value) => `${Math.round(value).toLocaleString("vi-VN")} ₫`;
const formatMonth = (iso) => {
    const [year, month] = String(iso).split("-");
    return `${month}/${year}`;
};

const steps = computed(() => {
    const rows = props.salaryHistory;
    if (!rows.length) return [];
    const max = Math.max(...rows.map((r) => r.salary));
    const min = Math.min(...rows.map((r) => r.salary));
    const currentIndex = rows.findLastIndex((r) => r.status === "active");

    return rows.map((row, index) => {
        const change = row.change_amount;
        return {
            ...row,
            typeLabel: CONTRACT_TYPE_LABELS[row.contract_type] ?? row.contract_type,
            change,
            changeText:
                change === null
                    ? "Khởi điểm"
                    : change === 0
                      ? "Giữ nguyên"
                      : `${change > 0 ? "+" : "−"}${Math.abs(row.change_percent)}% (${change > 0 ? "+" : "−"}${money(Math.abs(change))})`,
            // Bậc thấp nhất lùi xuống 40%, cao nhất sát đỉnh — đủ thấy chênh lệch mà không làm thẻ quá thấp.
            offset: max === min ? 0 : ((max - row.salary) / (max - min)) * 40,
            current: index === (currentIndex === -1 ? rows.length - 1 : currentIndex),
        };
    });
});
</script>

<style scoped>
.ladder {
    display: flex;
    align-items: stretch;
    gap: 10px;
    min-height: 260px;
    overflow-x: auto;
    padding-bottom: 4px;
}
.ladder__col {
    display: flex;
    flex-direction: column;
    flex: 1 0 200px;
    max-width: 320px;
}
.ladder__spacer {
    flex-shrink: 0;
}
.ladder__step {
    flex: 1;
    padding: 14px;
    border-radius: 12px;
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-bottom-width: 4px;
    border-bottom-color: rgba(var(--v-theme-primary), 0.35);
    background: rgba(var(--v-theme-primary), 0.04);
}
.ladder__step--current {
    border-color: rgba(var(--v-theme-primary), 0.6);
    border-bottom-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.09);
}
.ladder__type {
    letter-spacing: 0.04em;
    opacity: 0.65;
}
.ladder__reason--missing {
    opacity: 0.55;
    font-style: italic;
}
.ladder__period {
    opacity: 0.65;
}
@media (max-width: 600px) {
    .ladder {
        flex-direction: column-reverse;
        min-height: 0;
    }
    .ladder__col {
        flex: none;
        max-width: none;
    }
    .ladder__spacer {
        display: none;
    }
}
</style>
