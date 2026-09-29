<template>
    <!-- 1 trường nhập: nhãn phía trên + ô nhập (slot) + gợi ý phía dưới.
         Dùng trong FormSection :columns="2|3" để tự vào lưới; span="full" chiếm cả hàng.
         Ví dụ:
           <FormField label="Tên chức vụ" required>
             <v-text-field v-model="form.name" :error-messages="errors.name" />
           </FormField> -->
    <div class="form-field" :class="{ 'form-field--full': span === 'full', 'form-field--inline': inline }">
        <div v-if="label || $slots.label" class="form-field__label-wrap">
            <label class="form-field__label">
                <slot name="label">{{ label }}</slot>
                <span v-if="required" class="text-error"> *</span>
            </label>
            <div v-if="inline && hint" class="form-field__hint">{{ hint }}</div>
        </div>
        <div class="form-field__control">
            <slot />
        </div>
        <div v-if="!inline && hint" class="form-field__hint">{{ hint }}</div>
    </div>
</template>

<script setup>
defineProps({
    label: { type: String, default: "" },
    required: { type: Boolean, default: false },
    hint: { type: String, default: "" },
    // "full": chiếm trọn 1 hàng trong lưới FormSection
    span: { type: String, default: "" },
    // Nhãn bên trái, điều khiển bên phải (dùng cho switch/checkbox)
    inline: { type: Boolean, default: false },
});
</script>

<style scoped>
.form-field {
    min-width: 0;
}
.form-field--full {
    grid-column: 1 / -1;
}
.form-field__label {
    display: block;
    margin-bottom: 6px;
    font-size: 14px;
    line-height: 20px;
    font-weight: 500;
    color: rgb(var(--v-theme-link));
}
.form-field__hint {
    margin-top: 4px;
    font-size: 12px;
    line-height: 16px;
    color: rgb(var(--v-theme-ink-muted));
}
.form-field--inline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 12px 16px;
    border: 1px solid rgb(var(--v-theme-hairline));
    border-radius: 12px;
}
.form-field--inline .form-field__label {
    margin-bottom: 0;
}
.form-field--inline .form-field__hint {
    margin-top: 2px;
}
.form-field--inline .form-field__control {
    flex-shrink: 0;
}
</style>
