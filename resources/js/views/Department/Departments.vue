<template>
    <div>
        <PageHeader
            title="Danh sách phòng ban"
            subtitle="Quản lý cơ cấu tổ chức và danh sách phòng ban."
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
                    Phòng ban
                </v-btn>
            </template>
        </PageHeader>

        <v-alert
            v-if="store.loadError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ store.loadError }}
        </v-alert>

        <!-- Thanh lọc: tìm kiếm + trạng thái -->
        <v-sheet class="border rounded-lg pa-4 mb-4 glass-panel" color="transparent">
            <div class="d-flex flex-wrap align-center ga-3">
                <SearchField
                    v-model="search"
                    placeholder="Tìm mã hoặc tên phòng ban..."
                />
                <v-select
                    v-model="statusFilter"
                    :items="statusOptions"
                    density="compact"
                    hide-details
                    style="max-width: 220px"
                />
            </div>
        </v-sheet>

        <DataTable
            :headers="headers"
            :items="departments"
            :loading="store.loading"
            :search="search"
            :actions="actions"
        >
            <template #item.index="{ index }">
                <span style="opacity: 0.6">{{ index + 1 }}</span>
            </template>
            <template #item.manager_name="{ item }">
                <span v-if="item.manager_name">{{ item.manager_name }}</span>
                <span v-else style="opacity: 0.4">—</span>
            </template>
            <template #item.description="{ item }">
                <span v-if="item.description">{{ item.description }}</span>
                <span v-else style="opacity: 0.4">—</span>
            </template>
            <template #item.name="{ item }">
                <span :style="{ paddingLeft: `${item.depth * 20}px` }">
                    <v-icon
                        v-if="item.depth > 0"
                        size="14"
                        class="mr-1"
                        style="opacity: 0.5"
                        >mdi-subdirectory-arrow-right</v-icon
                    >
                    {{ item.name }}
                </span>
            </template>
            <template #item.is_active="{ item }">
                <StatusChip :status="item.is_active" :map="ACTIVE_STATUS_MAP" />
            </template>
        </DataTable>

        <DepartmentFormDialog
            v-model="formDialog"
            :department="editing"
            :parent-options="parentOptions"
        />

    </div>
</template>
<script setup>
import { computed, onMounted, ref } from "vue";
import { useDepartmentStore } from "../../stores/useDepartmentStore";
import { useAuthStore } from "../../stores/authStore";
import DataTable from "../../components/common/DataTable.vue";
import SearchField from "../../components/common/SearchField.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import DepartmentFormDialog from "./DepartmentForm.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import { useToastStore } from "../../stores/useToastStore";

const ACTIVE_STATUS_MAP = {
    1: { label: "Hoạt động", color: "success" },
    0: { label: "Ngừng hoạt động", color: "default" },
};

const store = useDepartmentStore();
const toast = useToastStore();
const auth = useAuthStore();
const search = ref("");

const formDialog = ref(false);
const editing = ref(null);

const canManage = computed(() =>
    auth.permissions.includes("department.manage"),
);

const statusFilter = ref("all");
const statusOptions = [
    { title: "Tất cả trạng thái", value: "all" },
    { title: "Đang hoạt động", value: "active" },
    { title: "Ngừng hoạt động", value: "inactive" },
];

// Cột "Thao tác" do DataTable tự chèn khi có thao tác hiển thị được — ở đây
// chỉ khai báo các cột dữ liệu.
const headers = [
    { title: "#", key: "index", sortable: false, width: 56 },
    { title: "Mã", key: "code", width: 110 },
    { title: "Tên phòng ban", key: "name" },
    { title: "Phòng ban cha", key: "parent_name" },
    { title: "Trưởng phòng", key: "manager_name" },
    { title: "Mô tả", key: "description", sortable: false },
    { title: "Trạng thái", key: "is_active", width: 150 },
];

function flattenTree(nodes, parentName = "", depth = 0) {
    return nodes.flatMap((node) => {
        const row = {
            id: node.id,
            code: node.code,
            name: node.name,
            parent_id: node.parent_id,
            parent_name: parentName || "—",
            manager_id: node.manager_id,
            manager_name: node.manager?.full_name ?? null,
            description: node.description,
            is_active: node.is_active,
            depth,
        };
        const children = node.children?.length
            ? flattenTree(node.children, node.name, depth + 1)
            : [];
        return [row, ...children];
    });
}

// Danh sách đầy đủ, KHÔNG lọc theo trạng thái — dùng cho mọi việc cần biết
// đúng quan hệ cha-con thật sự (chọn cha, kiểm tra còn con hay không).
// Nếu dùng nhầm danh sách đã lọc, một phòng ban con đang "Ngừng hoạt động"
// có thể bị lọt qua kiểm tra khi đang bật bộ lọc "Đang hoạt động".
const allDepartments = computed(() => flattenTree(store.tree));

// Danh sách hiển thị lên bảng — có áp bộ lọc trạng thái.
const departments = computed(() => {
    if (statusFilter.value === "all") {
        return allDepartments.value;
    }

    const wantActive = statusFilter.value === "active";
    return allDepartments.value.filter(
        (row) => Boolean(row.is_active) === wantActive,
    );
});

// Danh sách chọn "phòng ban cha": khi sửa phải loại chính nó và toàn bộ cấp dưới,
// nếu không sẽ tạo vòng lặp và bị backend trả về lỗi 422.
const parentOptions = computed(() => {
    const rows = allDepartments.value;
    let excluded = [];

    if (editing.value) {
        const index = rows.findIndex((row) => row.id === editing.value.id);
        if (index !== -1) {
            excluded = [rows[index].id];
            for (let i = index + 1; i < rows.length; i += 1) {
                if (rows[i].depth <= rows[index].depth) {
                    break;
                }
                excluded.push(rows[i].id);
            }
        }
    }

    return rows
        .filter((row) => !excluded.includes(row.id))
        .map((row) => ({
            id: row.id,
            title: `${"— ".repeat(row.depth)}${row.name}`,
        }));
});

// Xóa phòng ban còn phòng ban con sẽ bị backend từ chối, nên chặn ngay ở giao
// diện: vẫn mở hộp xác nhận để giải thích lý do, nhưng khóa nút Xóa.
const hasChildren = (department) =>
    allDepartments.value.some((row) => row.parent_id === department.id);

const actions = computed(() => [
    {
        icon: "mdi-pencil-outline",
        tooltip: "Sửa",
        color: "primary",
        hidden: !canManage.value,
        onClick: openEdit,
    },
    {
        icon: "mdi-delete-outline",
        tooltip: "Xóa",
        color: "error",
        hidden: !canManage.value,
        confirm: {
            title: "Xóa phòng ban",
            message: (item) =>
                `Bạn có chắc muốn xóa phòng ban "${item.name}" không?`,
            confirmText: "Xóa",
            warning: (item) =>
                hasChildren(item)
                    ? "Phòng ban này đang có phòng ban trực thuộc. Hãy chuyển các phòng ban con sang phòng ban cha khác trước khi xóa."
                    : null,
            disabled: hasChildren,
        },
        onClick: async (item) => {
            await store.remove(item.id);
            toast.success(`Đã xóa phòng ban "${item.name}".`);
        },
    },
]);

function openCreate() {
    editing.value = null;
    formDialog.value = true;
}

function openEdit(department) {
    editing.value = department;
    formDialog.value = true;
}

onMounted(() => {
    store.fetchTree();
});
</script>
