<template>
    <div>
        <PageHeader
            title="Danh sách nhân viên"
            subtitle="Quản lý hồ sơ và thông tin nhân viên."
        >
            <template #actions>
                <v-btn
                    v-if="canCreate"
                    color="primary"
                    variant="flat"
                    size="large"
                    prepend-icon="mdi-plus"
                    @click="openCreate"
                >
                    Nhân viên
                </v-btn>
            </template>
        </PageHeader>

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
            :actions="actions"
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

        <EmployeeFormDialog
            v-model="formDialog"
            :employee="editing"
            :department-options="departmentFormOptions"
            @saved="fetchData"
        />
    </div>
</template>

<script setup>
import { ref, watch, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { useEmployeeStore } from "../../stores/useEmployeeStore";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import { useAuthStore } from "../../stores/authStore";
import DataTable from "../../components/common/DataTable.vue";
import SearchField from "../../components/common/SearchField.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import EmployeeFormDialog from "./EmployeeForm.vue";

const store = useEmployeeStore();
const departmentStore = useDepartmentStore();
const auth = useAuthStore();
const router = useRouter();

const formDialog = ref(false);
const editing = ref(null);

const canCreate = computed(() => auth.permissions.includes("employee.create"));
const canUpdate = computed(() => auth.permissions.includes("employee.update"));

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

// Xóa để sau — chưa nằm trong phạm vi Ngày 28 (chỉ Thêm/Sửa).
const actions = computed(() => [
    {
        icon: "mdi-eye-outline",
        tooltip: "Xem chi tiết",
        color: "primary",
        onClick: (item) =>
            router.push({ name: "employee-detail", params: { id: item.id } }),
    },
    {
        icon: "mdi-pencil-outline",
        tooltip: "Sửa",
        color: "primary",
        hidden: !canUpdate.value,
        onClick: openEdit,
    },
]);

// Dùng cho dropdown LỌC (có thêm lựa chọn "Tất cả phòng ban" = không lọc).
const departmentOptions = computed(() => [
    { title: "Tất cả phòng ban", value: null },
    ...flattenDepartments(departmentStore.tree).map((dept) => ({
        title: dept.name,
        value: dept.id,
    })),
]);
// Dùng cho form Thêm/Sửa — không có lựa chọn "Tất cả", vì ở đây null nghĩa
// là "nhân viên chưa được gán phòng ban" chứ không phải "không lọc".
const departmentFormOptions = computed(() =>
    flattenDepartments(departmentStore.tree).map((dept) => ({
        title: dept.name,
        value: dept.id,
    })),
);
function flattenDepartments(nodes) {
    return nodes.flatMap((node) => [
        node,
        ...(node.children?.length ? flattenDepartments(node.children) : []),
    ]);
}

function openCreate() {
    editing.value = null;
    formDialog.value = true;
}

function openEdit(employee) {
    editing.value = employee;
    formDialog.value = true;
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
