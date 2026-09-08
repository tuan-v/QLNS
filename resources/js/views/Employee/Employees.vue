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

        <StatCards :stats="statCards" />

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
            <template #item.full_name="{ item }">
                <div class="d-flex align-center ga-2">
                    <v-avatar
                        :color="avatarColor(item.full_name)"
                        variant="tonal"
                        size="32"
                    >
                        <span class="text-caption font-weight-bold">{{
                            initials(item.full_name)
                        }}</span>
                    </v-avatar>
                    <span>{{ item.full_name }}</span>
                </div>
            </template>
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
            @saved="onEmployeeSaved"
        />
    </div>
</template>

<script setup>
import { ref, watch, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import { useEmployeeStore } from "../../stores/useEmployeeStore";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import { useAuthStore } from "../../stores/authStore";
import employeeService from "../../services/employeeService";
import DataTable from "../../components/common/DataTable.vue";
import SearchField from "../../components/common/SearchField.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
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

// 4 thẻ thống kê đầu trang — chỉ đếm theo employment_status thật có trong DB
// (xem EmployeeService::stats()), KHÔNG có "Đang nghỉ phép" vì đó là trạng
// thái tạm thời theo ngày, thuộc module Nghỉ phép (Ngày 36-40) chưa xây.
const stats = ref({ total: 0, active: 0, probation: 0, resigned: 0 });
const statCards = computed(() => [
    {
        label: "Tổng nhân viên",
        value: stats.value.total,
        color: "primary",
        icon: "mdi-account-group-outline",
    },
    {
        label: "Đang làm việc",
        value: stats.value.active,
        color: "success",
        icon: "mdi-check-circle-outline",
    },
    {
        label: "Thử việc",
        value: stats.value.probation,
        color: "warning",
        icon: "mdi-clock-outline",
    },
    {
        label: "Đã nghỉ việc",
        value: stats.value.resigned,
        color: "secondary",
        icon: "mdi-account-off-outline",
    },
]);

async function fetchStats() {
    try {
        const response = await employeeService.stats();
        stats.value = response.data;
    } catch {
        // Số liệu phụ, không phải luồng chính của trang — lỗi tải bảng chính
        // đã có store.loadError lo, ở đây chỉ cần giữ nguyên số liệu cũ.
    }
}

// Ghép sẵn màu để tránh đổi màu ngẫu nhiên mỗi lần render (tô theo tên nên
// cùng 1 người luôn ra cùng 1 màu).
const AVATAR_COLORS = [
    "primary",
    "success",
    "info",
    "warning",
    "purple",
    "teal",
    "indigo",
    "deep-orange",
];

function initials(fullName) {
    const parts = String(fullName ?? "")
        .trim()
        .split(/\s+/)
        .filter(Boolean);
    if (!parts.length) {
        return "?";
    }
    const first = parts[0][0];
    const last = parts[parts.length - 1][0];
    return (parts.length > 1 ? first + last : first).toUpperCase();
}

function avatarColor(fullName) {
    const text = String(fullName ?? "");
    let hash = 0;
    for (let i = 0; i < text.length; i += 1) {
        hash = (hash * 31 + text.charCodeAt(i)) >>> 0;
    }
    return AVATAR_COLORS[hash % AVATAR_COLORS.length];
}

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

function onEmployeeSaved() {
    fetchData();
    fetchStats();
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
    fetchStats();
    departmentStore.fetchTree();
});
</script>
