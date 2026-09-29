<script setup>
import { computed } from "vue";

const props = defineProps({
    // Giá trị trạng thái thô từ API (chuỗi enum, hoặc 1/0/boolean cho cờ bật-tắt)
    status: {
        type: [String, Number, Boolean],
        default: null,
    },
    // Object ánh xạ: { "<giá trị thô>": { label: "Nhãn tiếng Việt", color: "success" } }
    // Khóa object trong JS luôn là chuỗi, nên 1/0/true/false đều tự động khớp đúng
    // khi so bằng chuỗi (map[String(status)]).
    map: {
        type: Object,
        required: true,
    },
});

const config = computed(
    () => props.map[String(props.status)] ?? { label: props.status ?? "—", color: "default" },
);

const isNeutral = computed(() => !config.value.color || ["default", "grey"].includes(config.value.color));
</script>

<template>
    <!-- Badge trạng thái (design system): nền nhạt + chấm tròn cùng màu;
         màu "default"/"grey" hiển thị viền trung tính. -->
    <v-chip
        :color="isNeutral ? undefined : config.color"
        :variant="isNeutral ? 'outlined' : 'tonal'"
        size="small"
        class="status-chip"
    >
        <span v-if="!isNeutral" class="status-dot" />
        {{ config.label }}
    </v-chip>
</template>

<style scoped>
.status-chip.v-chip--variant-outlined {
    border-color: rgb(var(--v-theme-hairline));
    color: rgb(var(--v-theme-ink-muted));
}
.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: currentColor;
    margin-right: 6px;
}
</style>
