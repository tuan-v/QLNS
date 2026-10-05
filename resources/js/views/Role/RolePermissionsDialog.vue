<template>
    <FormDialog
        :model-value="modelValue"
        eyebrow="PHÂN QUYỀN"
        :title="`Quản lý quyền — ${role?.name ?? ''}`"
        subtitle="Chọn những quyền vai trò này được phép sử dụng."
        :error="error"
        :loading="loading"
        :submit-disabled="isSystemAdmin"
        submit-label="Lưu quyền"
        icon="mdi-key-outline"
        max-width="720"
        @update:model-value="close"
        @submit="submit"
    >
        <!-- Một form dùng cho CẢ hai việc tạo mới và sửa (đổi nhãn theo
             editingId) — tránh dựng thêm 2 ô nhập chen vào từng dòng quyền
             trong lưới 2 cột vốn đã chật. -->
        <div ref="formAnchor">
            <FormSection :title="editingId ? `Sửa quyền — ${editingCode}` : 'Tạo quyền mới'">
                <div class="d-flex flex-wrap ga-2 align-start">
                    <v-text-field
                        v-model="permissionForm.code"
                        variant="outlined"
                        density="compact"
                        placeholder="vd: report.export"
                        hide-details="auto"
                        :error-messages="formErrors.code"
                        style="max-width: 220px"
                    />
                    <v-text-field
                        v-model="permissionForm.name"
                        variant="outlined"
                        density="compact"
                        placeholder="Tên hiển thị, vd: Xuất báo cáo"
                        hide-details="auto"
                        :error-messages="formErrors.name"
                        style="max-width: 260px"
                    />
                    <v-btn
                        color="primary"
                        variant="tonal"
                        :loading="permissionStore.loading"
                        @click="savePermission"
                    >
                        <!-- Cố ý KHÔNG đặt là "Lưu quyền" — nút đó ở chân hộp
                             thoại lưu việc GÁN quyền cho vai trò, khác với nút
                             này chỉ sửa mã/tên của chính quyền đang chọn. -->
                        {{ editingId ? "Cập nhật" : "Thêm quyền" }}
                    </v-btn>
                    <v-btn
                        v-if="editingId"
                        variant="text"
                        :disabled="permissionStore.loading"
                        @click="resetForm"
                    >
                        Hủy
                    </v-btn>
                </div>

                <!-- Route backend khóa quyền bằng CHUỖI mã (`permission:employee.view`),
                     không theo id — đổi mã của quyền đang gắn vào route sẽ làm gate đó
                     mất hiệu lực cho tới khi lập trình viên sửa lại route. Đổi tên hiển
                     thị thì vô hại. -->
                <v-alert
                    v-if="editingId"
                    type="warning"
                    variant="tonal"
                    density="compact"
                    class="mt-3"
                    icon="mdi-alert-outline"
                >
                    Đổi <strong>mã quyền</strong> sẽ làm mọi route backend đang khóa theo mã cũ
                    mất hiệu lực — chỉ đổi khi chắc chắn, hoặc chỉ sửa tên hiển thị.
                </v-alert>
            </FormSection>
        </div>

        <FormSection title="Danh sách quyền">
            <v-alert
                v-if="isSystemAdmin"
                type="info"
                variant="tonal"
                density="compact"
                class="mb-3"
                icon="mdi-shield-lock-outline"
            >
                Vai trò <strong>Admin</strong> của hệ thống luôn có toàn bộ quyền (kể cả quyền
                tạo mới sau này) — không thể bỏ quyền nào.
            </v-alert>
            <div class="d-flex flex-wrap align-center ga-3">
                <!-- Lọc ngay trên danh sách đã tải sẵn (vài chục dòng) nên để
                     debounce 0, gõ tới đâu lọc tới đó, không gọi lại API. -->
                <SearchField
                    v-model="keyword"
                    :debounce="0"
                    placeholder="Tìm mã hoặc tên quyền..."
                />
                <p class="permission-summary">{{ listSummary }}</p>
            </div>
            <v-alert
                v-if="!filteredGroups.length"
                type="info"
                variant="tonal"
                density="compact"
                class="mt-3"
                icon="mdi-magnify-close"
            >
                Không có quyền nào khớp với "{{ keyword }}".
            </v-alert>
        </FormSection>

        <FormSection
            v-for="group in filteredGroups"
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
                            :disabled="isSystemAdmin"
                        />
                        <div class="d-flex align-center flex-shrink-0">
                            <v-btn
                                icon="mdi-pencil-outline"
                                size="x-small"
                                variant="text"
                                color="primary"
                                title="Sửa mã/tên quyền"
                                @click="startEdit(permission)"
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
                    </div>
                </v-col>
            </v-row>
        </FormSection>
    </FormDialog>
</template>

<script setup>
import { computed, nextTick, reactive, ref, watch } from "vue";
import roleService from "../../services/roleService";
import { usePermissionStore } from "../../stores/usePermissionStore";
import { useToastStore } from "../../stores/useToastStore";
import { useChangeGuard } from "../../composables/useChangeGuard";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import SearchField from "../../components/common/SearchField.vue";
import { normalizeVietnamese } from "../../composables/normalizeVietnamese";
import { SYSTEM_ADMIN_ROLE } from "../../composables/roleLevels";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    role: { type: Object, default: null },
});

const emit = defineEmits(["update:modelValue", "saved"]);

const isSystemAdmin = computed(() => props.role?.name === SYSTEM_ADMIN_ROLE);

const permissionStore = usePermissionStore();
const toast = useToastStore();
const guard = useChangeGuard(() => [...selectedIds.value].sort((a, b) => a - b));

const loading = ref(false);
const error = ref("");
const selectedIds = ref([]);
const keyword = ref("");
const formAnchor = ref(null);
// null = form đang ở chế độ tạo mới; có id = đang sửa đúng quyền đó.
const editingId = ref(null);
const editingCode = ref("");
const permissionForm = reactive({ code: "", name: "" });

const formErrors = computed(() =>
    editingId.value ? permissionStore.editErrors : permissionStore.errors,
);

// Nhãn tiếng Việt cho từng nhóm quyền — nhóm theo tiền tố trước dấu chấm
// (vd "employee.view" -> nhóm "employee"). Quyền có tiền tố chưa có trong
// bảng này (vd quyền tự tạo mới sau này) rơi vào nhóm "Khác" thay vì ẩn mất.
const GROUP_LABELS = {
    employee: "Nhân viên",
    department: "Phòng ban",
    shift: "Ca làm việc",
    attendance: "Chấm công",
    leave: "Nghỉ phép",
    payroll: "Lương",
    report: "Báo cáo",
    rbac: "Phân quyền",
    holiday: "Ngày nghỉ lễ",
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

function matches(text, needle) {
    return normalizeVietnamese(text).includes(needle);
}

const filteredGroups = computed(() => {
    const needle = normalizeVietnamese(keyword.value).trim();
    if (!needle) {
        return groupedPermissions.value;
    }
    return groupedPermissions.value
        .map((group) => ({
            ...group,
            // Gõ trúng tên nhóm tiếng Việt ("chấm công") thì giữ nguyên cả
            // nhóm; ngược lại lọc từng quyền theo mã + tên.
            items: matches(group.label, needle)
                ? group.items
                : group.items.filter((permission) =>
                      matches(`${permission.code} ${permission.name}`, needle),
                  ),
        }))
        .filter((group) => group.items.length);
});

const listSummary = computed(() => {
    const total = permissionStore.permissions.length;
    const selected = selectedIds.value.length;
    if (!keyword.value.trim()) {
        return `Đang chọn ${selected}/${total} quyền.`;
    }
    const shown = filteredGroups.value.reduce((sum, group) => sum + group.items.length, 0);
    // Bộ lọc chỉ ẩn bớt dòng để nhìn, KHÔNG đụng tới selectedIds — những quyền
    // đã tick nhưng đang bị ẩn vẫn được lưu bình thường khi bấm "Lưu quyền".
    return `Khớp ${shown}/${total} quyền · vai trò này đang chọn ${selected} quyền.`;
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
        guard.takeSnapshot();
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
            // Mở lại cho vai trò khác thì phải sạch: còn sót từ khóa lọc cũ sẽ
            // làm tưởng hệ thống thiếu quyền, còn sót form sửa dở sẽ lưu nhầm.
            keyword.value = "";
            resetForm();
            loadRolePermissions();
        }
    },
);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    if (guard.skipIfUnchanged()) {
        close();
        return;
    }
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

function resetForm() {
    editingId.value = null;
    editingCode.value = "";
    permissionForm.code = "";
    permissionForm.name = "";
    permissionStore.resetErrors();
}

function startEdit(permission) {
    editingId.value = permission.id;
    editingCode.value = permission.code;
    permissionForm.code = permission.code;
    permissionForm.name = permission.name;
    permissionStore.resetErrors();
    // Form nằm ở ĐẦU hộp thoại còn nút bút chì có thể ở tận cuối danh sách —
    // không kéo lên thì bấm xong tưởng như không có gì xảy ra.
    nextTick(() => formAnchor.value?.scrollIntoView({ behavior: "smooth", block: "nearest" }));
}

async function savePermission() {
    try {
        if (editingId.value) {
            await permissionStore.update(editingId.value, { ...permissionForm });
            toast.success(`Đã cập nhật quyền "${permissionForm.code}".`);
            resetForm();
            return;
        }
        const created = await permissionStore.create({ ...permissionForm });
        resetForm();
        toast.success("Đã tạo quyền mới.");
        // Tự tick sẵn quyền vừa tạo cho vai trò đang sửa — đỡ phải tìm lại
        // trong danh sách vừa dài thêm 1 dòng.
        selectedIds.value = [...selectedIds.value, created.id];
    } catch {
        // Lỗi đã hiện qua permissionStore.errors / editErrors ngay dưới 2 ô nhập.
    }
}

async function deletePermission(permission) {
    try {
        await permissionStore.remove(permission.id);
        selectedIds.value = selectedIds.value.filter((id) => id !== permission.id);
        if (editingId.value === permission.id) {
            resetForm();
        }
        toast.success(`Đã xóa quyền "${permission.code}".`);
    } catch (e) {
        error.value =
            e.response?.data?.errors?.permission?.[0] ??
            e.response?.data?.message ??
            "Không thể xóa quyền này.";
    }
}
</script>

<style scoped>
.permission-summary {
    margin: 0;
    font-size: 13px;
    line-height: 18px;
    color: rgb(var(--v-theme-ink-muted));
}
</style>
