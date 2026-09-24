<template>
    <FormDialog
        :model-value="modelValue"
        eyebrow="PHÂN QUYỀN"
        :title="`Quản lý quyền — ${role?.name ?? ''}`"
        subtitle="Chọn những quyền vai trò này được phép sử dụng."
        :error="error"
        :loading="loading"
        submit-label="Lưu quyền"
        icon="mdi-key-outline"
        max-width="720"
        @update:model-value="close"
        @submit="submit"
    >
        <v-alert
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-outline"
        >
            Quyền mới tạo chưa tự khóa API nào — cần lập trình viên gắn
            <code>permission:&lt;mã quyền&gt;</code> vào route liên quan thì mới thực
            sự có hiệu lực chặn truy cập.
        </v-alert>

        <FormSection title="Tạo quyền mới">
            <div class="d-flex flex-wrap ga-2 align-start">
                <v-text-field
                    v-model="newPermission.code"
                    variant="outlined"
                    density="compact"
                    placeholder="vd: report.export"
                    hide-details="auto"
                    :error-messages="permissionStore.errors.code"
                    style="max-width: 220px"
                />
                <v-text-field
                    v-model="newPermission.name"
                    variant="outlined"
                    density="compact"
                    placeholder="Tên hiển thị, vd: Xuất báo cáo"
                    hide-details="auto"
                    :error-messages="permissionStore.errors.name"
                    style="max-width: 260px"
                />
                <v-btn
                    color="primary"
                    variant="tonal"
                    :loading="permissionStore.loading"
                    @click="createPermission"
                >
                    Thêm quyền
                </v-btn>
            </div>
        </FormSection>

        <FormSection
            v-for="group in groupedPermissions"
            :key="group.prefix"
            :title="group.label"
        >
            <v-row dense>
                <v-col
                    v-for="permission in group.items"
                    :key="permission.id"
                    cols="12"
                    sm="6"
                >
                    <div class="d-flex align-center justify-space-between ga-2">
                        <v-checkbox
                            v-model="selectedIds"
                            :value="permission.id"
                            :label="`${permission.name} (${permission.code})`"
                            density="compact"
                            hide-details
                        />
                        <v-btn
                            icon="mdi-close"
                            size="x-small"
                            variant="text"
                            color="error"
                            title="Xóa quyền khỏi hệ thống"
                            @click="deletePermission(permission)"
                        />
                    </div>
                </v-col>
            </v-row>
        </FormSection>
    </FormDialog>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import roleService from "../../services/roleService";
import { usePermissionStore } from "../../stores/usePermissionStore";
import { useToastStore } from "../../stores/useToastStore";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    role: { type: Object, default: null },
});

const emit = defineEmits(["update:modelValue", "saved"]);

const permissionStore = usePermissionStore();
const toast = useToastStore();

const loading = ref(false);
const error = ref("");
const selectedIds = ref([]);
const newPermission = reactive({ code: "", name: "" });

// Nhãn tiếng Việt cho từng nhóm quyền — nhóm theo tiền tố trước dấu chấm
// (vd "employee.view" -> nhóm "employee"). Quyền có tiền tố chưa có trong
// bảng này (vd quyền tự tạo mới sau này) rơi vào nhóm "Khác" thay vì ẩn mất.
const GROUP_LABELS = {
    employee: "Nhân viên",
    department: "Phòng ban",
    shift: "Ca làm việc",
    location: "Điểm chấm công",
    attendance: "Chấm công",
    leave: "Nghỉ phép",
    payroll: "Lương",
    report: "Báo cáo",
    rbac: "Phân quyền",
    system: "Hệ thống",
};

const groupedPermissions = computed(() => {
    const groups = new Map();
    for (const permission of permissionStore.permissions) {
        const prefix = permission.code.split(".")[0];
        if (!groups.has(prefix)) {
            groups.set(prefix, []);
        }
        groups.get(prefix).push(permission);
    }
    return [...groups.entries()]
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([prefix, items]) => ({
            prefix,
            label: GROUP_LABELS[prefix] ?? "Khác",
            items,
        }));
});

async function loadRolePermissions() {
    if (!props.role) {
        return;
    }
    loading.value = true;
    error.value = "";
    try {
        const [roleDetail] = await Promise.all([
            roleService.show(props.role.id).then((r) => r.data),
            permissionStore.permissions.length ? Promise.resolve() : permissionStore.fetchList(),
        ]);
        selectedIds.value = (roleDetail.permissions ?? []).map((p) => p.id);
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không thể tải dữ liệu quyền.";
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            error.value = "";
            loadRolePermissions();
        }
    },
);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    loading.value = true;
    error.value = "";
    try {
        const response = await roleService.updatePermissions(props.role.id, selectedIds.value);
        toast.success("Đã cập nhật quyền cho vai trò.");
        emit("saved", response.data);
        close();
    } catch (e) {
        error.value =
            e.response?.data?.errors?.permission_ids?.[0] ??
            e.response?.data?.message ??
            "Không thể lưu thay đổi quyền.";
    } finally {
        loading.value = false;
    }
}

async function createPermission() {
    try {
        const created = await permissionStore.create({ ...newPermission });
        newPermission.code = "";
        newPermission.name = "";
        toast.success("Đã tạo quyền mới.");
        // Tự tick sẵn quyền vừa tạo cho vai trò đang sửa — đỡ phải tìm lại
        // trong danh sách vừa dài thêm 1 dòng.
        selectedIds.value = [...selectedIds.value, created.id];
    } catch {
        // Lỗi đã hiện qua permissionStore.errors ngay dưới 2 ô nhập.
    }
}

async function deletePermission(permission) {
    try {
        await permissionStore.remove(permission.id);
        selectedIds.value = selectedIds.value.filter((id) => id !== permission.id);
        toast.success(`Đã xóa quyền "${permission.code}".`);
    } catch (e) {
        error.value =
            e.response?.data?.errors?.permission?.[0] ??
            e.response?.data?.message ??
            "Không thể xóa quyền này.";
    }
}
</script>
