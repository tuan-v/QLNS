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
            <div class="d-flex flex-wrap ga-3">
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
        >
            <template #item.index="{ index }">
                <span style="opacity: 0.6">{{ index + 1 }}</span>
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
                <v-chip
                    :color="item.is_active ? 'success' : 'default'"
                    variant="tonal"
                    size="small"
                >
                    {{ item.is_active ? "Hoạt động" : "Ngừng hoạt động" }}
                </v-chip>
            </template>
            <template #item.actions="{ item }">
                <div class="d-flex justify-end ga-2">
                    <v-btn
                        icon="mdi-pencil-outline"
                        variant="tonal"
                        color="primary"
                        size="small"
                        rounded="lg"
                        @click="openEdit(item)"
                    >
                        <v-icon icon="mdi-pencil-outline" />
                        <v-tooltip activator="parent" location="top">Sửa</v-tooltip>
                    </v-btn>
                    <v-btn
                        icon="mdi-delete-outline"
                        variant="tonal"
                        size="small"
                        rounded="lg"
                        color="error"
                        @click="openDelete(item)"
                    >
                        <v-icon icon="mdi-delete-outline" />
                        <v-tooltip activator="parent" location="top">Xóa</v-tooltip>
                    </v-btn>
                </div>
            </template>
        </DataTable>

        <DepartmentFormDialog
            v-model="formDialog"
            :department="editing"
            :parent-options="parentOptions"
        />

        <v-dialog v-model="deleteDialog" max-width="440">
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Xóa phòng ban
                </v-card-title>
                <v-card-text class="px-5">
                    Bạn có chắc muốn xóa phòng ban
                    <strong>{{ deleting?.name }}</strong> không?
                    <v-alert
                        v-if="deletingHasChildren"
                        type="warning"
                        variant="tonal"
                        density="compact"
                        class="mt-3"
                        icon="mdi-alert-outline"
                    >
                        Phòng ban này đang có phòng ban trực thuộc. Hãy chuyển
                        các phòng ban con sang phòng ban cha khác trước khi xóa.
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="store.loading"
                        @click="deleteDialog = false"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="error"
                        variant="flat"
                        :loading="store.loading"
                        :disabled="deletingHasChildren"
                        @click="confirmDelete"
                    >
                        Xóa
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
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

const store = useDepartmentStore();
const auth = useAuthStore();
const search = ref("");

const formDialog = ref(false);
const deleteDialog = ref(false);
const editing = ref(null);
const deleting = ref(null);

const canManage = computed(() =>
    auth.permissions.includes("department.manage"),
);

const statusFilter = ref("all");
const statusOptions = [
    { title: "Tất cả trạng thái", value: "all" },
    { title: "Đang hoạt động", value: "active" },
    { title: "Ngừng hoạt động", value: "inactive" },
];

const headers = computed(() => {
    const columns = [
        { title: "#", key: "index", sortable: false, width: 56 },
        { title: "Mã", key: "code", width: 110 },
        { title: "Tên phòng ban", key: "name" },
        { title: "Phòng ban cha", key: "parent_name" },
        { title: "Mô tả", key: "description", sortable: false },
        { title: "Trạng thái", key: "is_active", width: 150 },
    ];

    if (canManage.value) {
        columns.push({
            title: "Thao tác",
            key: "actions",
            sortable: false,
            align: "end",
            width: 120,
        });
    }

    return columns;
});

function flattenTree(nodes, parentName = "", depth = 0) {
    return nodes.flatMap((node) => {
        const row = {
            id: node.id,
            code: node.code,
            name: node.name,
            parent_id: node.parent_id,
            parent_name: parentName || "—",
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

const deletingHasChildren = computed(() =>
    allDepartments.value.some((row) => row.parent_id === deleting.value?.id),
);

function openCreate() {
    editing.value = null;
    formDialog.value = true;
}

function openEdit(department) {
    editing.value = department;
    formDialog.value = true;
}

function openDelete(department) {
    deleting.value = department;
    deleteDialog.value = true;
}

async function confirmDelete() {
    try {
        await store.remove(deleting.value.id);
        deleteDialog.value = false;
    } catch {
        // store.loadError đã giữ thông báo lỗi, giữ hộp thoại mở
    }
}

onMounted(() => {
    store.fetchTree();
});
</script>
