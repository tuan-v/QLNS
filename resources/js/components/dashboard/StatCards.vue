<script setup>
defineProps({
    // Mỗi phần tử: { label, value, icon?, color?, trend?, trendUp?, foot?, sub? }
    // - label/value/icon/color: như cũ (Dashboard, Employees, AttendanceOverview).
    // - trend ("+2,5%"), trendUp (true/false), foot, sub: TUỲ CHỌN.
    stats: {
        type: Array,
        default: () => [],
    },
});

// Theo design.md (TailAdmin): ô icon nền xám nhạt, nhãn chữ phụ, số lớn đậm
// màu ink, badge xu hướng dạng viên thuốc xanh/đỏ. `color` của từng thẻ chỉ tô
// icon — không nhuộm cả thẻ. Màu tĩnh (purple/teal/...) vẫn chạy nhờ class
// text-{color} của Vuetify.
function iconColor(color) {
    return color && color !== "default" ? `text-${color === "primary" ? "brand-text" : color}` : "text-ink-muted";
}
</script>

<template>
    <v-row class="mt-0">
        <v-col v-for="stat in stats" :key="stat.label" cols="12" sm="6" md="3">
            <v-card class="stat-card pa-5 h-100">
                <div v-if="stat.icon" class="stat-icon mb-5">
                    <v-icon :icon="stat.icon" size="24" :class="iconColor(stat.color)" />
                </div>

                <div class="d-flex align-end justify-space-between ga-2">
                    <div class="min-w-0">
                        <div class="text-body-2 text-medium-emphasis text-truncate">
                            {{ stat.label }}
                        </div>
                        <div class="stat-value mt-2">{{ stat.value }}</div>
                    </div>

                    <v-chip
                        v-if="stat.trend"
                        :color="stat.trendUp === false ? 'error' : 'success'"
                        size="small"
                        class="flex-shrink-0 mb-1"
                    >
                        <v-icon start size="14">{{
                            stat.trendUp === false ? "mdi-arrow-down" : "mdi-arrow-up"
                        }}</v-icon>
                        {{ stat.trend }}
                    </v-chip>
                </div>

                <div v-if="stat.foot" class="text-body-2 font-weight-medium mt-3">
                    {{ stat.foot }}
                </div>
                <div v-if="stat.sub" class="text-caption text-medium-emphasis">
                    {{ stat.sub }}
                </div>
            </v-card>
        </v-col>
    </v-row>
</template>

<style scoped>
.stat-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgb(var(--v-theme-muted));
}
.stat-value {
    font-size: 30px;
    line-height: 38px;
    font-weight: 700;
    color: rgb(var(--v-theme-ink));
    font-variant-numeric: tabular-nums;
}
.min-w-0 {
    min-width: 0;
}
</style>
