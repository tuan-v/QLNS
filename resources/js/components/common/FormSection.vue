<template>
    <!-- Khối nhóm trường trong form (design system QLNS / TailAdmin):
         tiêu đề + mô tả ngắn ở trên, các trường bên dưới. Các khối liên tiếp
         tự ngăn cách bằng 1 đường hairline — KHÔNG đóng khung/tô nền từng khối
         (bản cũ lồng khung trong khung, tốn khoảng trắng). -->
    <section class="form-section">
        <header v-if="title || $slots['header-actions']" class="form-section__head">
            <div class="min-w-0">
                <div class="d-flex align-center ga-2">
                    <v-icon v-if="icon" :icon="icon" size="18" class="form-section__icon" />
                    <h3 class="form-section__title">{{ title }}</h3>
                </div>
                <p v-if="description" class="form-section__desc">{{ description }}</p>
            </div>
            <slot name="header-actions" />
        </header>

        <!-- columns > 1: tự xếp các FormField thành lưới (1 cột trên mobile) -->
        <div :class="columns > 1 ? ['form-grid', `form-grid--${columns}`] : 'form-section__body'">
            <slot />
        </div>
    </section>
</template>

<script setup>
defineProps({
    // Tiêu đề khối, viết thường có dấu: "Thông tin cơ bản"
    title: { type: String, default: "" },
    // 1 câu giải thích ngắn (tuỳ chọn)
    description: { type: String, default: "" },
    // Icon MDI nhỏ cạnh tiêu đề (tuỳ chọn)
    icon: { type: String, default: "" },
    // Số cột lưới cho FormField con (1–3). 1 = để slot tự bố cục (v-row cũ vẫn chạy).
    columns: { type: Number, default: 1 },
});
</script>

<style scoped>
.form-section + .form-section {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid rgb(var(--v-theme-hairline));
}
.form-section__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 16px;
}
.form-section__title {
    margin: 0;
    font-size: 15px;
    line-height: 22px;
    font-weight: 600;
    color: rgb(var(--v-theme-ink));
}
.form-section__icon {
    color: rgb(var(--v-theme-brand-text));
}
.form-section__desc {
    margin: 2px 0 0;
    font-size: 13px;
    line-height: 18px;
    color: rgb(var(--v-theme-ink-muted));
}
.min-w-0 {
    min-width: 0;
}
</style>
