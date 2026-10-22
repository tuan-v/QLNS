<template>
    <div>
        <PageHeader
            title="Phụ cấp & trợ cấp"
            subtitle="Ai được hưởng khoản nào, bao nhiêu mỗi tháng; danh mục khoản và mức gợi ý theo loại nhân viên."
        />

        <v-tabs v-model="tab" color="primary" class="mb-4">
            <v-tab value="employees" prepend-icon="mdi-account-group-outline">Theo nhân viên</v-tab>
            <v-tab value="requests" prepend-icon="mdi-clipboard-check-outline">
                Đề xuất
                <v-badge v-if="pendingCount" :content="pendingCount" color="warning" inline class="ml-1" />
            </v-tab>
            <v-tab value="catalog" prepend-icon="mdi-format-list-bulleted">Danh mục khoản</v-tab>
        </v-tabs>

        <v-window v-model="tab">
            <v-window-item value="employees">
                <AllowanceMatrix :active="tab === 'employees'" />
            </v-window-item>
            <v-window-item value="requests">
                <AllowanceRequests
                    v-if="opened.requests"
                    :active="tab === 'requests'"
                    :initial-status="initialStatus"
                    @pending="pendingCount = $event"
                />
            </v-window-item>
            <v-window-item value="catalog">
                <AllowanceCatalog v-if="opened.catalog" />
            </v-window-item>
        </v-window>
    </div>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import PageHeader from "../../components/common/PageHeader.vue";
import AllowanceMatrix from "./AllowanceMatrix.vue";
import AllowanceRequests from "./AllowanceRequests.vue";
import AllowanceCatalog from "./AllowanceCatalog.vue";
import allowanceService from "../../services/allowanceService";
import { useRememberedRef } from "../../composables/useRememberedRef";
import { useRealtimeRefresh } from "../../composables/useRealtimeRefresh";

// Trang Phụ cấp & trợ cấp (Kế hoạch Ngày 48): "Theo nhân viên" (ai hưởng khoản nào), "Đề xuất" (HR đổi phụ cấp
// -> Admin duyệt, 2026-10-21) và "Danh mục khoản". Thông báo đề xuất mở thẳng ?tab=requests&status=...
const route = useRoute();
const router = useRouter();
const TABS = ["employees", "requests", "catalog"];
const tab = useRememberedRef("allowances.tab", "employees");
const initialStatus = ref("pending");
const opened = reactive({ requests: false, catalog: false });
const pendingCount = ref(0);

function openFromQuery() {
    if (!TABS.includes(route.query.tab)) return;
    tab.value = route.query.tab;
    if (route.query.status) initialStatus.value = String(route.query.status);
    router.replace({ query: {} }); // bỏ query để F5 không mở lại đúng bộ lọc cũ
}
openFromQuery();
watch(() => route.query.tab, openFromQuery);
watch(
    tab,
    (value) => {
        if (value in opened) opened[value] = true;
    },
    { immediate: true },
);

// Số đề xuất chờ duyệt trên nhãn tab — cả khi tab "Đề xuất" chưa mở.
async function loadPendingCount() {
    try {
        const { data } = await allowanceService.requests({ status: "pending", per_page: 1 });
        pendingCount.value = data.meta.pending;
    } catch {
        // chỉ là số trên nhãn tab, lỗi thì bỏ qua
    }
}
onMounted(loadPendingCount);
useRealtimeRefresh(loadPendingCount, {
    shared: [{ resource: "allowance_requests", permission: "payroll.view_all" }],
});
</script>
