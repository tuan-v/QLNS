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

        <StatCards :stats="statCards" class="mb-2" />

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
                            :color="
                                item.avatar_url
                                    ? undefined
                                    : avatarColor(item.full_name)
                            "
                            variant="tonal"
                            size="36"
                        >
                            <v-img
                                v-if="item.avatar_url"
                                :src="item.avatar_url"
                                cover
                            />
                            <span
                                v-else
                                class="text-caption font-weight-bold"
                                >{{ initials(item.full_name) }}</span
                            >
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
            <template #item.phone="{ item }">{{ item.phone ?? "—" }}</template>
            <template #item.agreed_salary="{ item }">{{
                formatCurrency(item.agreed_salary)
            }}</template>
            <template #item.hire_date="{ item }">{{
                formatDate(item.hire_date)
            }}</template>
            <!-- Cột "Nghỉ phép" (2026-09-24, theo yêu cầu người dùng) — còn
                 lại/tổng được cấp của "Nghỉ phép năm" NĂM NAY. null (bị ẩn vì
                 người xem là cấp dưới — EmployeeResource) hiện "—"; chưa có
                 bản ghi LeaveBalance thì API trả 0 → "0/0 ngày". -->
            <template #item.leave_remaining_days="{ item }">{{
                formatLeaveDays(item)
            }}</template>
            <template #item.employment_status="{ item }">
                <StatusChip
                    :status="item.employment_status"
                    :map="EMPLOYMENT_STATUS_MAP"
                />
                <!-- Cảnh báo nhanh (HĐ sắp/đã hết hạn, sắp hết thử việc) — xem employeeAlerts.js -->
                <div v-for="alert in employeeAlerts(item)" :key="alert.text" class="mt-1">
                    <v-chip :color="alert.color" size="x-small" variant="tonal" :prepend-icon="alert.icon">
                        {{ alert.text }}
                    </v-chip>
                </div>
            </template>
        </DataTable>

        <EmployeeQuickView
            v-model="quickViewOpen"
            :employee="quickViewEmployee"
            :can-update="canUpdate"
            @edit="(e) => { quickViewOpen = false; openEdit(e); }"
        />

        <EmployeeFormDialog
            v-model="formDialog"
            :employee="editing"
            :department-options="departmentFormOptions"
            @saved="onEmployeeSaved"
        />
    </div>
</template>

<script setup>
import { ref, watch, computed, onMounted, onUnmounted } from "vue";
import { useRouter } from "vue-router";
import { useEmployeeStore } from "../../stores/useEmployeeStore";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import { useAuthStore } from "../../stores/authStore";
import { usePresenceStore } from "../../stores/usePresenceStore";
import { useResourceSyncStore } from "../../stores/useResourceSyncStore";
import employeeService from "../../services/employeeService";
import { avatarColor, initials } from "../../composables/avatar";
import { EMPLOYMENT_STATUS_MAP } from "../../composables/employmentStatus";
import DataTable from "../../components/common/DataTable.vue";
import SearchField from "../../components/common/SearchField.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import StatCards from "../../components/dashboard/StatCards.vue";
import EmployeeFormDialog from "./EmployeeForm.vue";
import EmployeeQuickView from "./EmployeeQuickView.vue";
import { employeeAlerts } from "../../composables/employeeAlerts";
import { useRememberedRef } from "../../composables/useRememberedRef";
import { useToastStore } from "../../stores/useToastStore";
import { useRouteAction } from "../../composables/useRouteAction";
const toast = useToastStore();
const store = useEmployeeStore();
const departmentStore = useDepartmentStore();
const auth = useAuthStore();
const presence = usePresenceStore();
const resourceSync = useResourceSyncStore();
const router = useRouter();

const formDialog = ref(false);
const editing = ref(null);

const canCreate = computed(() => auth.permissions.includes("employee.create"));
const canUpdate = computed(() => auth.permissions.includes("employee.update"));
const canLockAccount = computed(() =>
    auth.permissions.includes("employee.lock_account"),
);
const search = ref("");
// Nhớ bộ lọc + số dòng/trang của lần xem trước (theo từng người dùng).
const departmentId = useRememberedRef("employees.department", null);
const positionId = ref(null);
const employmentStatus = useRememberedRef("employees.status", null);
const page = ref(1);
const perPage = useRememberedRef("employees.per-page", 10);

// Nhãn + màu employment_status lấy từ composables/employmentStatus.js (dùng
// chung toàn app) — cho cả StatusChip trong bảng lẫn dropdown lọc.
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
const stats = ref({
    total: 0,
    active: 0,
    probation: 0,
    intern: 0,
    resigned: 0,
});
const statCards = computed(() => [
    {
        label: "Tổng nhân viên",
        value: stats.value.total,
        color: "primary",
        icon: "mdi-account-group-outline",
    },
    {
        label: "Chính thức",
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
        label: "Thực tập",
        value: stats.value.intern,
        color: "info",
        icon: "mdi-school-outline",
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

// Gộp Mã/Họ tên/Phòng ban/Chức vụ/Email vào 1 cột "Nhân viên" cho gọn bảng
// (2026-09-24, theo yêu cầu người dùng — xem slot #item.full_name), thêm
// riêng "Lương" (agreed_salary — hợp đồng đang hiệu lực, mục 8 CODE_MAP,
// EmployeeResource tự ẩn nếu người xem là cấp dưới), "Ngày vào làm" và
// "Nghỉ phép" (leave_remaining_days/leave_allocated_days — quỹ "Nghỉ phép
// năm" của năm nay, cùng cơ chế ẩn với cấp dưới như "Lương").
const headers = [
    { title: "Nhân viên", key: "full_name" },
    { title: "Điện thoại", key: "phone" },
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
// null (bị ẩn vì người xem là cấp dưới — EmployeeResource) hiện "—", giống
// cột "Lương"; chưa có bản ghi LeaveBalance thì API đã trả 0 → "0/0 ngày".
function formatLeaveDays(item) {
    if (
        item.leave_allocated_days === null ||
        item.leave_allocated_days === undefined
    ) {
        return "—";
    }
    return `${item.leave_remaining_days}/${item.leave_allocated_days} ngày`;
}

// Xóa để sau — chưa nằm trong phạm vi Ngày 28 (chỉ Thêm/Sửa).
const actions = computed(() => [
    {
        icon: "mdi-eye-outline",
        tooltip: "Xem nhanh",
        color: "primary",
        // Mở ngăn bên phải, không rời danh sách; trong ngăn có nút "Mở hồ sơ đầy đủ".
        onClick: (item) => {
            quickViewEmployee.value = item;
            quickViewOpen.value = true;
        },
    },
    {
        icon: "mdi-pencil-outline",
        tooltip: "Sửa",
        color: "primary",
        hidden: !canUpdate.value,
        onClick: openEdit,
    },
    {
        icon: "mdi-lock-outline",
        tooltip: "khóa",
        color: "error",
        hidden: (item) =>
            !canLockAccount.value ||
            !item.user ||
            item.user.status === "inactive",
        confirm: {
            title: "Khóa tài khoản đăng nhập",
            message: (item) =>
                `Bạn có chắc muốn khóa tài khoản đăng nhập của ${item.full_name}?`,
            confirmText: "Khóa tài khoản",
            warning: "Nhân viên sẽ không đăng nhập được ngay lập tức.",
        },

        onClick: async (item) => {
            await employeeService.deactivateAccount(item.id);
            fetchData();
            toast.success("Khóa tài khoản thành công.");
        },
    },
    {
        icon: "mdi-lock-open-outline",
        tooltip: "mở khóa",
        color: "success",
        hidden: (item) =>
            !canLockAccount.value ||
            !item.user ||
            item.user.status === "active",
        confirm: {
            title: "Mở khóa tài khoản đăng nhập",
            message: (item) =>
                `Bạn có chắc muốn mở khóa tài khoản đăng nhập của ${item.full_name}?`,
            confirmText: "Mở khóa tài khoản",
        },

        onClick: async (item) => {
            await employeeService.activateAccount(item.id);
            fetchData();
            toast.success("Mở khóa tài khoản thành công.");
        },
    },
]);

// Dùng cho dropdown LỌC (có thêm lựa chọn "Tất cả phòng ban" = không lọc).
// Phòng ban đã nhớ mà nay không còn (bị xóa) -> bỏ lọc, tránh ô lọc hiện mã thô + danh sách trống.
watch(
    () => departmentStore.tree,
    () => {
        const ids = flattenDepartments(departmentStore.tree).map((d) => d.id);
        if (departmentId.value && ids.length && !ids.includes(departmentId.value)) {
            departmentId.value = null;
        }
    },
);

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

const quickViewOpen = ref(false);
const quickViewEmployee = ref(null);

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

// Phiên KHÁC vừa Thêm/Sửa/Xóa nhân viên — xem ResourceChanged (mục 34
// CODE_MAP). Kết nối THEO TRANG (khác presence ở trên — join/rời theo vòng
// đời riêng trang này, không dùng chung App.vue).
watch(
    () => resourceSync.signals.employees,
    () => {
        fetchData();
        fetchStats();
    },
);

// Mở thẳng thao tác khi vào trang bằng ?action=... (lệnh Ctrl+K).
useRouteAction({ create: () => canCreate.value && openCreate() });

onMounted(() => {
    fetchData();
    fetchStats();
    departmentStore.fetchTree();
    resourceSync.connect("employees");
});

onUnmounted(() => {
    resourceSync.disconnect("employees");
});
</script>
