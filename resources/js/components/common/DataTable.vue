<script setup>
import { ref, watch } from "vue";

const props = defineProps({
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
        default: "",
    },
    page: {
        type: Number,
        default: 1,
    },
    itemsPerPage: {
        type: Number,
        default: 10,
    },
    serverItemsLength: {
        type: Number,
        default: null,
    },
});
const emit = defineEmits(["update:page", "update:itemsPerPage"]);

const perPageOptions = [10, 25, 50, 100];
// Ở chế độ server-side, "Số dòng" phải báo ra ngoài cho component cha gửi lên
// API (giống hệt "page") — bảng không tự cắt lại items, tin hoàn toàn vào những
// gì server trả về, nên chọn "Số dòng" mà không gửi lên thì hoàn toàn vô tác dụng.
const perPage = ref(props.itemsPerPage);
watch(
    () => props.itemsPerPage,
    (value) => {
        perPage.value = value;
    },
);
watch(perPage, (value) => {
    emit("update:itemsPerPage", value);
});

// Vuetify 3 KHÔNG có hàm setPage() trong slot #bottom (chỉ Vuetify 4 mới có) —
// nên bảng tự quản lý trang qua 1 ref nội bộ, đồng bộ 2 chiều với prop "page"
// bằng v-model (đúng API v-model:page mà VDataTable/VDataTableServer đều hỗ trợ
// ở cả 2 phiên bản), thay vì phụ thuộc vào hàm tiện ích lấy từ slot.
const internalPage = ref(props.page);
watch(
    () => props.page,
    (value) => {
        internalPage.value = value;
    },
);
watch(internalPage, (value) => {
    emit("update:page", value);
});
</script>

<template>
    <v-data-table-server
        v-if="serverItemsLength !== null"
        v-model:items-per-page="perPage"
        v-model:page="internalPage"
        :headers="headers"
        :items="items"
        :items-length="serverItemsLength"
        :loading="loading"
        density="comfortable"
        no-data-text="Không có dữ liệu phù hợp."
        class="border rounded-lg app-data-table"
    >
        <template v-for="(_, slotName) in $slots" #[slotName]="slotProps">
            <slot :name="slotName" v-bind="slotProps ?? {}" />
        </template>
        <template #bottom="{ pageCount }">
            <v-divider />
            <div class="d-flex flex-wrap align-center ga-4 px-4 py-3">
                <div class="d-flex align-center ga-2">
                    <span class="text-body-2" style="opacity: 0.7"
                        >Số dòng:</span
                    >
                    <v-select
                        v-model="perPage"
                        :items="perPageOptions"
                        density="compact"
                        hide-details
                        style="width: 96px"
                    />
                </div>

                <div class="text-body-2" style="opacity: 0.7">
                    Tổng
                    <strong>{{ serverItemsLength }}</strong> kết quả
                </div>

                <v-spacer />

                <v-pagination
                    v-if="pageCount > 1"
                    v-model="internalPage"
                    :length="pageCount"
                    density="comfortable"
                    :total-visible="5"
                />
            </div>
        </template>
    </v-data-table-server>
    <v-data-table
        v-else
        v-model:items-per-page="perPage"
        v-model:page="internalPage"
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
        <template #bottom="{ pageCount }">
            <v-divider />
            <div class="d-flex flex-wrap align-center ga-4 px-4 py-3">
                <div class="d-flex align-center ga-2">
                    <span class="text-body-2" style="opacity: 0.7"
                        >Số dòng:</span
                    >
                    <v-select
                        v-model="perPage"
                        :items="perPageOptions"
                        density="compact"
                        hide-details
                        style="width: 96px"
                    />
                </div>

                <div class="text-body-2" style="opacity: 0.7">
                    Tổng <strong>{{ items.length }}</strong> kết quả
                </div>

                <v-spacer />

                <v-pagination
                    v-if="pageCount > 1"
                    v-model="internalPage"
                    :length="pageCount"
                    density="comfortable"
                    :total-visible="5"
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
