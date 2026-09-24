<template>
    <div>
        <PageHeader
            title="Vai trò & Phân quyền"
            subtitle="Quản lý vai trò và quyền hạn tương ứng của từng vai trò."
        >
            <template #actions>
                <v-btn
                    color="primary"
                    variant="flat"
                    size="large"
                    prepend-icon="mdi-plus"
                    @click="openCreate"
                >
                    Vai trò
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

        <DataTable
            :headers="headers"
            :items="store.roles"
            :loading="store.loading"
            :actions="actions"
        >
            <template #item.description="{ item }">
                <span v-if="item.description">{{ item.description }}</span>
                <span v-else style="opacity: 0.4">—</span>
            </template>
        </DataTable>

        <RoleForm v-model="formDialog" :role="editing" @saved="fetchData" />

        <RolePermissionsDialog v-model="permissionsDialog" :role="editingPermissionsFor" />
    </div>
</template>

<script setup>
import { onMounted, ref } from "vue";
import { useRoleStore } from "../../stores/useRoleStore";
import { useToastStore } from "../../stores/useToastStore";
import DataTable from "../../components/common/DataTable.vue";
import PageHeader from "../../components/common/PageHeader.vue";
import RoleForm from "./RoleForm.vue";
import RolePermissionsDialog from "./RolePermissionsDialog.vue";

const store = useRoleStore();
const toast = useToastStore();

const formDialog = ref(false);
const editing = ref(null);

const permissionsDialog = ref(false);
const editingPermissionsFor = ref(null);

const headers = [
    { title: "Tên vai trò", key: "name" },
    { title: "Mô tả", key: "description", sortable: false },
];

const actions = [
    {
        icon: "mdi-key-outline",
        tooltip: "Quản lý quyền",
        color: "primary",
        onClick: openPermissions,
    },
    {
        icon: "mdi-pencil-outline",
        tooltip: "Sửa",
        color: "primary",
        onClick: openEdit,
    },
    {
        icon: "mdi-delete-outline",
        tooltip: "Xóa",
        color: "error",
        confirm: {
            title: "Xóa vai trò",
            message: (item) => `Bạn có chắc muốn xóa vai trò "${item.name}" không?`,
            // Xóa mềm — khôi phục được, nhưng nhân viên đang giữ vai trò này sẽ
            // mất quyền tương ứng ngay lập tức. Không biết trước có bao nhiêu
            // nhân viên (tránh phải gọi API riêng chỉ để hiện số đếm), nên cảnh
            // báo chung thay vì con số cụ thể.
            warning: () =>
                "Nhân viên đang giữ vai trò này sẽ mất toàn bộ quyền tương ứng ngay lập tức (có thể khôi phục vai trò lại sau nếu cần).",
            confirmText: "Xóa",
        },
        onClick: async (item) => {
            await store.remove(item.id);
            toast.success(`Đã xóa vai trò "${item.name}".`);
        },
    },
];

function openCreate() {
    editing.value = null;
    formDialog.value = true;
}

function openEdit(role) {
    editing.value = role;
    formDialog.value = true;
}

function openPermissions(role) {
    editingPermissionsFor.value = role;
    permissionsDialog.value = true;
}

function fetchData() {
    store.fetchList();
}

onMounted(fetchData);
</script>
