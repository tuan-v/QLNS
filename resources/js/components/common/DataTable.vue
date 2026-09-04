<script setup>
import { ref } from 'vue';

defineProps({
    headers: {
        type: Array,
        required: true,
    },
    items: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
    search: {
        type: String,
        default: '',
    },
});

const perPageOptions = [10, 25, 50, 100];
const perPage = ref(10);
</script>

<template>
  <v-data-table
    v-model:items-per-page="perPage"
    :headers="headers"
    :items="items"
    :loading="loading"
    :search="search"
    density="comfortable"
    no-data-text="Không có dữ liệu phù hợp."
    class="border rounded-lg app-data-table"
  >
    <template v-for="(_, slotName) in $slots" #[slotName]="slotProps">
      <slot :name="slotName" v-bind="slotProps ?? {}" />
    </template>

    <!-- Footer gọn: chọn số dòng + tổng số kết quả, thay bộ phân trang mặc định -->
    <template #bottom="{ page, pageCount, itemsLength, setPage }">
      <v-divider />
      <div class="d-flex flex-wrap align-center ga-4 px-4 py-3">
        <div class="d-flex align-center ga-2">
          <span class="text-body-2" style="opacity: 0.7;">Số dòng:</span>
          <v-select
            v-model="perPage"
            :items="perPageOptions"
            density="compact"
            hide-details
            style="width: 96px;"
          />
        </div>

        <div class="text-body-2" style="opacity: 0.7;">
          Đang hiển thị
          <strong>{{ itemsLength }}</strong> / {{ items.length }} kết quả
        </div>

        <v-spacer />

        <v-pagination
          v-if="pageCount > 1"
          :model-value="page"
          :length="pageCount"
          density="comfortable"
          :total-visible="5"
          @update:model-value="setPage"
        />
      </div>
    </template>
  </v-data-table>
</template>

<style scoped>
.app-data-table :deep(thead th) {
  background: rgba(var(--v-theme-on-surface), 0.03);
  font-size: 0.72rem !important;
  font-weight: 700 !important;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  opacity: 0.7;
}
</style>
