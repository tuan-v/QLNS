<template>
    <div>
        <PageHeader
            title="Danh sách chức vụ"
            subtitle="Quản lý chức vụ theo từng phòng ban."
        >
            <template #actions>
                <v-btn
                    v-if="canManage"
                    color="primary"
                    variant="flat"
                    size="large"
                    prepend-icon="mdi-plus"
                    @click="openCreate"
                >
                    Chức vụ
                </v-btn>
            </template>
        </PageHeader>

        <v-alert
            v-if="loadError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ loadError }}
        </v-alert>

        <!-- Thanh lọc: tìm kiếm + phòng ban -->
        <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
            <div class="d-flex flex-wrap align-center ga-3">
                <SearchField
                    v-model="search"
                    placeholder="Tìm mã hoặc tên chức vụ..."
                />
                <v-select
                    v-model="departmentFilter"
                    :items="departmentOptions"
                    item-title="title"
                    item-value="id"
                    density="compact"
                    hide-details
                    clearable
                    placeholder="Tất cả phòng ban"
                    style="max-width: 260px"
                />
            </div>
        </v-sheet>

        <DataTable
            :headers="headers"
            :items="positions"
            :loading="loading"
            :search="search"
            :actions="actions"
        >
            <template #item.index="{ index }">
                <span style="opacity: 0.6">{{ index + 1 }}</span>
            </template>
            <template #item.department_name="{ item }">
                {{ item.department_name }}
            </template>
            <template #item.level="{ item }">
                <span v-if="item.level">{{ item.level }}</span>
                <span v-else style="opacity: 0.4">—</span>
            </template>
            <template #item.position_allowance="{ item }">
                <span v-if="item.position_allowance">
                    {{ formatMoney(item.position_allowance) }}
                </span>
                <span v-else style="opacity: 0.4">—</span>
            </template>
            <template #item.type="{ item }">
                <v-chip
                    v-if="item.type"
                    size="small"
                    variant="tonal"
                    color="info"
                >
                    {{
                        item.type === "head"
                            ? "Trưởng phòng — hệ thống"
                            : "Mặc định — hệ thống"
                    }}
                </v-chip>
                <span v-else style="opacity: 0.4">—</span>
            </template>
            <template #item.is_active="{ item }">
                <StatusChip :status="item.is_active" :map="ACTIVE_STATUS_MAP" />
            </template>
        </DataTable>

        <PositionFormDialog
            v-model="formDialog"
            :position="editing"
            :department-options="departmentOptions"
            @saved="fetchData"
        />
    </div>
</template>
<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import { useAuthStore } from "../../stores/authStore";
import positionService from "../../services/positionService";
import DataTable from "../../components/common/DataTable.vue";
import SearchField from "../../components/common/SearchField.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import PositionFormDialog from "./PositionForm.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import { useToastStore } from "../../stores/useToastStore";

const ACTIVE_STATUS_MAP = {
    1: { label: "Hoạt động", color: "success" },
    0: { label: "Ngừng hoạt động", color: "default" },
};

const departmentStore = useDepartmentStore();
const auth = useAuthStore();
const toast = useToastStore();

const search = ref("");
const departmentFilter = ref(null);
const positions = ref([]);
const loading = ref(false);
const loadError = ref("");

const formDialog = ref(false);
const editing = ref(null);

const canManage = computed(() =>
    auth.permissions.includes("department.manage"),
);

const headers = [
    { title: "#", key: "index", sortable: false, width: 56 },
    { title: "Mã", key: "code", width: 110 },
    { title: "Tên chức vụ", key: "name" },
    { title: "Phòng ban", key: "department_name" },
    { title: "Cấp bậc", key: "level", width: 100 },
    { title: "Phụ cấp", key: "position_allowance", width: 140 },
    { title: "Loại", key: "type", width: 170 },
    { title: "Trạng thái", key: "is_active", width: 150 },
];

function flattenDepartments(nodes) {
    return nodes.flatMap((node) => [
        { id: node.id, name: node.name },
        ...(node.children?.length ? flattenDepartments(node.children) : []),
    ]);
}

// Danh sách chọn phòng ban dùng cho cả bộ lọc lẫn form Thêm/Sửa — cùng cách
// làm phẳng cây với Employees.vue (xem CODE_MAP mục 8), không cần thông tin
// độ sâu vì chỉ dùng để chọn 1 phòng ban, không hiển thị dạng cây thụt lề.
const departmentOptions = computed(() =>
    flattenDepartments(departmentStore.tree).map((dept) => ({
        id: dept.id,
        title: dept.name,
    })),
);

function formatMoney(value) {
    return new Intl.NumberFormat("vi-VN").format(Number(value)) + " ₫";
}

async function fetchData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await positionService.list({
            per_page: 1000,
            department_id: departmentFilter.value || undefined,
        });
        positions.value = response.data.data.map((position) => ({
            ...position,
            department_name: position.department?.name ?? "—",
        }));
    } catch (e) {
        positions.value = [];
        loadError.value =
            e.response?.data?.message ??
            "Không thể kết nối máy chủ, vui lòng thử lại.";
    } finally {
        loading.value = false;
    }
}

const actions = computed(() => [
    {
        icon: "mdi-pencil-outline",
        tooltip: "Sửa",
        color: "primary",
        hidden: !canManage.value,
        disabled: (item) => Boolean(item.type),
        onClick: openEdit,
    },
    {
        icon: "mdi-delete-outline",
        tooltip: "Xóa",
        color: "error",
        hidden: !canManage.value,
        disabled: (item) => Boolean(item.type),
        confirm: {
            title: "Xóa chức vụ",
            message: (item) =>
                `Bạn có chắc muốn xóa chức vụ "${item.name}" không?`,
            confirmText: "Xóa",
        },
        onClick: async (item) => {
            await positionService.remove(item.id);
            await fetchData();
            toast.success(`Đã xóa chức vụ "${item.name}".`);
        },
    },
]);

function openCreate() {
    editing.value = null;
    formDialog.value = true;
}

function openEdit(position) {
    editing.value = position;
    formDialog.value = true;
}

watch(departmentFilter, fetchData);

onMounted(() => {
    departmentStore.fetchTree();
    fetchData();
});
</script>
