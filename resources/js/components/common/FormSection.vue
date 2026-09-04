<template>
    <div class="mb-4">
        <div class="form-section-title mb-2">{{ title }}</div>
        <div
            class="border rounded-lg pa-4"
            style="background: rgba(var(--v-theme-on-surface), 0.025)"
        >
            <slot />
        </div>
    </div>
</template>

<script setup>
defineProps({
    // Tiêu đề nhỏ phía trên mỗi khối, ví dụ "THÔNG TIN CƠ BẢN"
    title: {
        type: String,
        required: true,
    },
});
</script>

<style scoped>
.form-section-title {
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    opacity: 0.55;
}
</style>
