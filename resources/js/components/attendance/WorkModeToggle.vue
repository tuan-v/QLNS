<template>
    <div class="work-mode">
        <span class="work-mode__label">Hôm nay làm ở đâu?</span>
        <v-btn-toggle
            :model-value="modelValue"
            mandatory
            divided
            density="comfortable"
            variant="outlined"
            color="primary"
            @update:model-value="emit('update:modelValue', $event)"
        >
            <v-btn value="office" prepend-icon="mdi-office-building-outline">Tại công ty</v-btn>
            <v-btn value="online" prepend-icon="mdi-laptop">Làm online</v-btn>
        </v-btn-toggle>
        <span v-if="modelValue === 'online'" class="work-mode__hint">Ngày làm online không có phụ cấp ăn trưa.</span>
    </div>
</template>

<script setup>
// Chọn nơi làm việc trước khi bấm "Chấm công vào" (attendances.work_mode). Mặc định "Tại công ty";
// HR sửa lại được ở Tổng hợp chấm công nếu chọn nhầm.
defineProps({
    modelValue: { type: String, default: "office" },
});
const emit = defineEmits(["update:modelValue"]);
</script>

<style scoped>
.work-mode {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 12px;
}
.work-mode__label {
    font-size: 0.875rem;
    font-weight: 500;
}
.work-mode__hint {
    font-size: 0.8125rem;
    color: rgb(var(--v-theme-warning));
}
</style>
