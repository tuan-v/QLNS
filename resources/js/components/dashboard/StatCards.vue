<script setup>
import { computed } from "vue";

const props = defineProps({
    // Mỗi phần tử: { label, value, icon?, color?, trend?, trendUp?, foot?, sub? }
    // - label/value/icon/color: như cũ (Dashboard, Employees, AttendanceOverview).
    // - trend ("+2,5%"), trendUp (true/false), foot, sub: TUỲ CHỌN.
    stats: {
        type: Array,
        default: () => [],
    },
});

// Thẻ gọn (2026-10-22, rà soát giao diện): icon bên trái cạnh nhãn + số, cao ~80px thay vì ~175px (icon trên,
// nhãn giữa, số dưới) — trước đây 8 thẻ chiếm nửa màn hình, đẩy bảng chính xuống. 5 thẻ thì 5 cột để không có
// thẻ lẻ 1 hàng; còn lại tối đa 4 cột.
const columns = computed(() => (props.stats.length === 5 ? 5 : Math.min(Math.max(props.stats.length, 1), 4)));

// Theo design.md (TailAdmin): ô icon nền xám nhạt, nhãn chữ phụ, số đậm màu ink, badge xu hướng dạng viên thuốc
// xanh/đỏ. `color` của từng thẻ chỉ tô icon — không nhuộm cả thẻ.
function iconColor(color) {
    return color && color !== "default" ? `text-${color === "primary" ? "brand-text" : color}` : "text-ink-muted";
}
</script>

<template>
    <div class="stat-grid mt-3" :style="{ '--stat-cols': columns, '--stat-cols-md': Math.min(columns, 2) }">
        <v-card v-for="stat in stats" :key="stat.label" class="stat-card pa-4">
            <div class="d-flex align-center ga-3">
                <div v-if="stat.icon" class="stat-icon">
                    <v-icon :icon="stat.icon" size="22" :class="iconColor(stat.color)" />
                </div>
                <div class="min-w-0 flex-grow-1">
                    <div class="text-body-2 text-medium-emphasis stat-label">{{ stat.label }}</div>
                    <div class="d-flex align-center flex-wrap ga-2">
                        <span class="stat-value">{{ stat.value }}</span>
                        <v-chip v-if="stat.trend" :color="stat.trendUp === false ? 'error' : 'success'" size="x-small">
                            <v-icon start size="12">{{
                                stat.trendUp === false ? "mdi-arrow-down" : "mdi-arrow-up"
                            }}</v-icon>
                            {{ stat.trend }}
                        </v-chip>
                    </div>
                </div>
            </div>
            <div v-if="stat.foot" class="text-body-2 font-weight-medium mt-2">{{ stat.foot }}</div>
            <div v-if="stat.sub" class="text-caption text-medium-emphasis mt-1">{{ stat.sub }}</div>
        </v-card>
    </div>
</template>

<style scoped>
.stat-grid {
    display: grid;
    grid-template-columns: repeat(var(--stat-cols), minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 12px;
}
.stat-icon {
    flex-shrink: 0;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgb(var(--v-theme-muted));
}
/* Nhãn dài (vd "Nhân viên mới tháng này") xuống tối đa 2 dòng thay vì bị cắt "...". */
.stat-label {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    line-height: 1.3;
}
.stat-value {
    font-size: 24px;
    line-height: 32px;
    font-weight: 700;
    color: rgb(var(--v-theme-ink));
    font-variant-numeric: tabular-nums;
}
.min-w-0 {
    min-width: 0;
}
@media (max-width: 1100px) {
    .stat-grid {
        grid-template-columns: repeat(var(--stat-cols-md), minmax(0, 1fr));
    }
}
@media (max-width: 520px) {
    .stat-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
