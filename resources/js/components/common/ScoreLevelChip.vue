<template>
    <v-chip
        v-if="level"
        :color="level.color"
        variant="tonal"
        :size="size"
        label
        class="font-weight-medium"
        :title="`${level.value}/5: ${level.hint}`"
    >
        {{ level.label }}
    </v-chip>
</template>

<script setup>
import { computed } from "vue";
import { levelOf } from "../../composables/scoreLevels";

// Hiện điểm 1-5 thành nhãn mức có màu ("Tốt", "Xuất sắc"...) thay cho sao. Rê chuột xem điểm số.
const props = defineProps({
    value: { type: Number, default: null },
    levels: { type: Array, required: true },
    size: { type: String, default: "small" },
});
const level = computed(() => levelOf(props.levels, props.value));
</script>
