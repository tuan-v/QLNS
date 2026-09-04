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
  <div class="d-flex flex-wrap align-center justify-space-between ga-4 mb-5">
    <div>
      <h1 class="text-h5 font-weight-bold">{{ title }}</h1>
      <p v-if="subtitle" class="text-body-2 mt-1 mb-0" style="opacity: 0.7;">
        {{ subtitle }}
      </p>
    </div>

    <!-- Nút hành động chính của trang (Thêm mới, Xuất file...) -->
    <slot name="actions" />
  </div>
</template>
