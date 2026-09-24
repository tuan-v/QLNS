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
            <!-- Gộp Mã/Họ tên/Phòng ban/Chức vụ/Email vào 1 cột "Nhân viên" cho
                 gọn bảng (2026-09-24, theo yêu cầu người dùng), tách "Lương"/
                 "Ngày vào làm" ra thành cột riêng bên cạnh "Trạng thái". -->
            <template #item.full_name="{ item }">
                <div class="d-flex align-center ga-3 py-1">
                    <v-badge
                        :model-value="presence.isOnline(item.id)"
                        dot
                        color="success"
                        location="bottom end"
                        offset-x="3"
                        offset-y="3"
                    >
                        <v-avatar
                            :color="avatarColor(item.full_name)"
                            variant="tonal"
                            size="36"
                        >
                            <span class="text-caption font-weight-bold">{{
                                initials(item.full_name)
                            }}</span>
                        </v-avatar>
                    </v-badge>
                    <div>
                        <div class="d-flex align-center ga-2">
                            <span class="font-weight-medium">{{
                                item.full_name
                            }}</span>
                            <span class="text-caption" style="opacity: 0.55">{{
                                item.code
                            }}</span>
                        </div>
                        <div class="text-caption" style="opacity: 0.7">
                            {{ item.company_email }}
                        </div>
                        <div class="text-caption" style="opacity: 0.55">
                            {{ item.department?.name ?? "—"
                            }}<template v-if="item.position">
                                · {{ item.position.name }}</template
                            >
                        </div>
                    </div>
                </div>
            </template>
            <template #item.agreed_salary="{ item }">{{
                formatCurrency(item.agreed_salary)
            }}</template>
            <template #item.hire_date="{ item }">{{
                formatDate(item.hire_date)
            }}</template>
            <!-- Cột "Nghỉ phép" (2026-09-24, theo yêu cầu người dùng) — còn
                 lại/tổng được cấp của "Nghỉ phép năm" NĂM NAY. null (chưa có
                 bản ghi LeaveBalance nào, hoặc bị ẩn vì người xem là cấp
                 dưới — EmployeeResource) hiện "—", giống cột "Lương". -->
            <template #item.leave_remaining_days="{ item }">{{
                formatLeaveDays(item)
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
import { usePresenceStore } from "../../stores/usePresenceStore";
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
const presence = usePresenceStore();
const router = useRouter();

const formDialog = ref(false);
const editing = ref(null);

const canCreate = computed(() => auth.permissions.includes("employee.create"));
const canUpdate = computed(() => auth.permissions.includes("employee.update"));

const search = ref("");
const departmentId = ref(null);
const positionId = ref(null);
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

// Gộp Mã/Họ tên/Phòng ban/Chức vụ/Email vào 1 cột "Nhân viên" cho gọn bảng
// (2026-09-24, theo yêu cầu người dùng — xem slot #item.full_name), thêm
// riêng "Lương" (agreed_salary — hợp đồng đang hiệu lực, mục 8 CODE_MAP,
// EmployeeResource tự ẩn nếu người xem là cấp dưới), "Ngày vào làm" và
// "Nghỉ phép" (leave_remaining_days/leave_allocated_days — quỹ "Nghỉ phép
// năm" của năm nay, cùng cơ chế ẩn với cấp dưới như "Lương").
const headers = [
    { title: "Nhân viên", key: "full_name" },
    { title: "Lương", key: "agreed_salary" },
    { title: "Ngày vào làm", key: "hire_date" },
    { title: "Nghỉ phép", key: "leave_remaining_days" },
    { title: "Trạng thái", key: "employment_status" },
];

function formatDate(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

// Lương ẩn (null) với người xem là cấp dưới (EmployeeResource) — hiện gạch
// ngang thay vì "0 ₫" để không hiểu nhầm là lương thật bằng 0.
function formatCurrency(value) {
    if (value === null || value === undefined) {
        return "—";
    }
    return new Intl.NumberFormat("vi-VN", {
        style: "currency",
        currency: "VND",
    }).format(value);
}

// "còn lại/tổng được cấp" — cùng cách trình bày "X/Y ngày" đã dùng ở thẻ
// "Quỹ phép còn lại" của LeaveRequests.vue, cho nhất quán trong cả app.
// null (chưa có bản ghi LeaveBalance nào, hoặc bị ẩn vì người xem là cấp
// dưới — EmployeeResource) hiện "—", giống cột "Lương".
function formatLeaveDays(item) {
    if (item.leave_allocated_days === null || item.leave_allocated_days === undefined) {
        return "—";
    }
    return `${item.leave_remaining_days}/${item.leave_allocated_days} ngày`;
}

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
        position_id: positionId.value || undefined,
        employment_status: employmentStatus.value || undefined,
        page: page.value,
        per_page: perPage.value,
    });
}

watch([search, departmentId, positionId, employmentStatus, perPage], () => {
    page.value = 1;
    fetchData();
});
watch(page, fetchData);

// Trạng thái online/offline (2026-09-24, theo yêu cầu người dùng) — kết nối
// presence dùng CHUNG cho toàn app, mở ngay khi đăng nhập (App.vue +
// usePresenceStore, xem CODE_MAP mục 32), trang này CHỈ đọc lại (presence.isOnline
// dùng thẳng trong template), không tự join/leave — nếu gắn theo vòng đời
// riêng trang này, nhân viên đang ở trang khác (vd "Hồ sơ của tôi") sẽ không
// được tính là online, dù thật sự đang mở app (lỗi thật đã gặp, chỉ hiện
// đúng người đang đứng ở trang Nhân viên).

onMounted(() => {
    fetchData();
    fetchStats();
    departmentStore.fetchTree();
});
</script>
