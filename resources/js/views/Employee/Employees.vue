<template>
    <div>
        <PageHeader
            title="Danh sách nhân viên"
            subtitle="Quản lý hồ sơ và thông tin nhân viên."
        />

        <v-alert
            v-if="store.loadError"
            type="error"
            variant="tonal"
            density="compact"
            class="mt-4"
            icon="mdi-alert-circle-outline"
        >
            {{ store.loadError }}
        </v-alert>

        <v-sheet
            class="border rounded-lg pa-2 mb-4 glass-panel"
            color="transparent"
        >
            <div class="d-flex flex-wrap align-center ga-3">
                <SearchField
                    v-model="search"
                    placeholder="Tìm theo tên, mã, email..."
                />
                <v-select
                    v-model="departmentId"
                    :items="departmentOptions"
                    placeholder="Phòng ban"
                    clearable
                    density="compact"
                    hide-details
                    style="max-width: 220px"
                />
                <v-select
                    v-model="employmentStatus"
                    :items="statusOptions"
                    density="compact"
                    hide-details
                    style="max-width: 220px"
                />
            </div>
        </v-sheet>

        <DataTable
            :headers="headers"
            :items="store.employees"
            :loading="store.loading"
            :server-items-length="store.pagination?.total ?? 0"
            v-model:page="page"
            v-model:items-per-page="perPage"
        >
            <template #item.department="{ item }">{{
                item.department?.name ?? "—"
            }}</template>
            <template #item.employment_status="{ item }">
                <StatusChip
                    :status="item.employment_status"
                    :map="EMPLOYMENT_STATUS_MAP"
                />
            </template>
        </DataTable>
    </div>
</template>

<script setup>
import { ref, watch, computed, onMounted } from "vue";
import { useEmployeeStore } from "../../stores/useEmployeeStore";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import DataTable from "../../components/common/DataTable.vue";
import SearchField from "../../components/common/SearchField.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";

const store = useEmployeeStore();
const departmentStore = useDepartmentStore();

const search = ref("");
const departmentId = ref(null);
const employmentStatus = ref(null);
const page = ref(1);
const perPage = ref(10);

// Nguồn duy nhất cho nhãn + màu của employment_status — dùng chung cho cả
// StatusChip (hiển thị trong bảng) lẫn dropdown lọc, tránh 2 nơi ghi nhãn
// khác nhau rồi lệch nhau dần theo thời gian.
const EMPLOYMENT_STATUS_MAP = {
    probation: { label: "Thử việc", color: "warning" },
    active: { label: "Đang làm việc", color: "success" },
    resigned: { label: "Đã nghỉ việc", color: "default" },
    terminated: { label: "Đã chấm dứt HĐ", color: "error" },
};

const statusOptions = [
    { title: "Tất cả trạng thái", value: null },
    ...Object.entries(EMPLOYMENT_STATUS_MAP).map(([value, { label }]) => ({
        title: label,
        value,
    })),
];

const headers = [
    { title: "Mã", key: "code" },
    { title: "Họ tên", key: "full_name" },
    { title: "Phòng ban", key: "department" },
    { title: "Email", key: "company_email" },
    { title: "Trạng thái", key: "employment_status" },
];

const departmentOptions = computed(() => [
    { title: "Tất cả phòng ban", value: null },
    ...flattenDepartments(departmentStore.tree).map((dept) => ({
        title: dept.name,
        value: dept.id,
    })),
]);
function flattenDepartments(nodes) {
    return nodes.flatMap((node) => [
        node,
        ...(node.children?.length ? flattenDepartments(node.children) : []),
    ]);
}

function fetchData() {
    store.fetchList({
        search: search.value || undefined,
        department_id: departmentId.value || undefined,
        employment_status: employmentStatus.value || undefined,
        page: page.value,
        per_page: perPage.value,
    });
}

watch([search, departmentId, employmentStatus, perPage], () => {
    page.value = 1;
    fetchData();
});
watch(page, fetchData);

onMounted(() => {
    fetchData();
    departmentStore.fetchTree();
});
</script>
