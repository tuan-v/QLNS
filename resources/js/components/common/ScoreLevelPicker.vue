<template>
    <div class="score-picker" role="radiogroup" :aria-label="ariaLabel">
        <v-btn
            v-for="level in levels"
            :key="level.value"
            role="radio"
            :aria-checked="level.value === modelValue"
            :title="`${level.value}/5: ${level.hint}`"
            :variant="level.value === modelValue ? 'flat' : 'outlined'"
            :color="level.value === modelValue ? level.color : undefined"
            :class="['score-picker__btn', { 'score-picker__btn--idle': level.value !== modelValue }]"
            :disabled="readonly && level.value !== modelValue"
            :readonly="readonly"
            size="small"
            @click="!readonly && emit('update:modelValue', level.value)"
        >
            {{ level.label }}
        </v-btn>
    </div>
</template>

<script setup>
// Chọn 1 trong 5 mức có chữ (thay v-rating sao). Thang lấy từ composables/scoreLevels.js.
// readonly: chỉ làm nổi mức đã chọn, các mức khác mờ đi.
defineProps({
    modelValue: { type: Number, default: null },
    levels: { type: Array, required: true },
    readonly: { type: Boolean, default: false },
    ariaLabel: { type: String, default: "Chọn mức đánh giá" },
});
const emit = defineEmits(["update:modelValue"]);
</script>

<style scoped>
.score-picker {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}
.score-picker__btn {
    text-transform: none;
    letter-spacing: 0;
    font-weight: 500;
}
.score-picker__btn--idle {
    color: rgb(var(--v-theme-ink-muted));
    border-color: rgb(var(--v-theme-hairline));
}
</style>
