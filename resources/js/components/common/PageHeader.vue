<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    subtitle: {
        type: String,
        default: '',
    },
});
</script>

<template>
  <div class="d-flex flex-wrap align-center justify-space-between ga-4 mb-6">
    <div>
      <h1 class="page-title">{{ title }}</h1>
      <p v-if="subtitle" class="text-body-2 mt-1 mb-0 text-medium-emphasis">
        {{ subtitle }}
      </p>
    </div>

    <!-- Nút hành động chính của trang (Thêm mới, Xuất file...). Bọc chung 1
         nhóm: nếu đặt thẳng nhiều nút vào khung justify-space-between thì mỗi
         nút bị coi là 1 cột riêng và bị dàn cách xa nhau (lỗi ở trang Chấm
         công/Chi tiết bảng lương, 2026-09-29). -->
    <div v-if="$slots.actions" class="page-header__actions d-flex flex-wrap align-center ga-2">
      <slot name="actions" />
    </div>
  </div>
</template>

<style scoped>
/* design.md: tiêu đề trang dùng cấp heading, màu ink */
.page-title {
  font-size: 24px;
  line-height: 32px;
  font-weight: 600;
  color: rgb(var(--v-theme-ink));
}
/* Màn hình điện thoại (xs của Vuetify): nhóm nút chiếm trọn 1 hàng để nút
   `block` (vd CheckInMobile.vue) vẫn giãn hết chiều ngang như trước. */
@media (max-width: 599.98px) {
  .page-header__actions {
    width: 100%;
  }
}
</style>
