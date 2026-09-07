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
</script>

<template>
    <v-chip :color="config.color" variant="tonal" size="small">
        {{ config.label }}
    </v-chip>
</template>
