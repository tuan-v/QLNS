<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-briefcase-search-outline"
        size="md"
        :title="isEdit ? 'Sửa đợt tuyển' : 'Mở đợt tuyển dụng'"
        subtitle="Số người cần tuyển cũng là giới hạn số CV được Admin duyệt — đủ thì tự ngưng nhận CV."
        :error="loadError"
        :loading="loading"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Mở đợt tuyển'"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Vị trí tuyển" :columns="2">
            <FormField label="Tên đợt tuyển" required span="full">
                <v-text-field
                    v-model="form.title"
                    density="comfortable"
                    placeholder="Ví dụ: Thực tập sinh PHP tháng 11"
                    :rules="[
                        notEmpty('Tên đợt tuyển'),
                        maxLength(255, 'Tên đợt tuyển'),
                    ]"
                    :error-messages="errors.title"
                />
            </FormField>
            <FormField required>
                <template #label>
                    Phòng ban
                    <v-btn
                        v-if="canManageOrg"
                        variant="text"
                        size="x-small"
                        density="comfortable"
                        prepend-icon="mdi-plus"
                        class="px-1 ml-1"
                        @click.prevent="quickDepartmentOpen = true"
                    >
                        Tạo nhanh
                    </v-btn>
                </template>
                <SearchSelect
                    :model-value="form.department_id"
                    :items="departmentOptions"
                    placeholder="Chọn phòng ban"
                    :rules="[notEmpty('Phòng ban')]"
                    :error-messages="errors.department_id"
                    @update:model-value="onDepartmentChange"
                />
            </FormField>
            <FormField>
                <template #label>
                    Chức vụ
                    <v-btn
                        v-if="canManageOrg"
                        variant="text"
                        size="x-small"
                        density="comfortable"
                        prepend-icon="mdi-plus"
                        class="px-1 ml-1"
                        @click.prevent="quickPositionOpen = true"
                    >
                        Tạo nhanh
                    </v-btn>
                </template>
                <SearchSelect
                    v-model="form.position_id"
                    :items="positionOptions"
                    placeholder="Chọn chức vụ"
                    clearable
                    :error-messages="errors.position_id"
                />
            </FormField>
            <FormField label="Loại hợp đồng khi nhận việc" required>
                <v-select
                    v-model="form.contract_type"
                    :items="CONTRACT_TYPE_OPTIONS"
                    density="comfortable"
                    :rules="[notEmpty('Loại hợp đồng')]"
                    :error-messages="errors.contract_type"
                />
            </FormField>
            <FormField
                label="Số người cần tuyển"
                required
                hint="= số CV tối đa được duyệt"
            >
                <v-text-field
                    v-model.number="form.headcount"
                    type="number"
                    min="1"
                    max="100"
                    density="comfortable"
                    :rules="[
                        notEmpty('Số người cần tuyển'),
                        isInteger('Số người cần tuyển'),
                        minValue(1, 'Số người cần tuyển'),
                    ]"
                    :error-messages="errors.headcount"
                />
            </FormField>
            <FormField label="Hạn nộp CV">
                <InputDate
                    v-model="form.deadline"
                    :min="isEdit ? undefined : todayIso()"
                    :error-messages="errors.deadline"
                />
            </FormField>
            <FormField label="Mô tả công việc" span="full">
                <v-textarea
                    v-model="form.description"
                    rows="3"
                    density="comfortable"
                    :rules="[maxLength(5000, 'Mô tả')]"
                    :error-messages="errors.description"
                />
            </FormField>
        </FormSection>

        <!-- Tạo nhanh Phòng ban / Chức vụ: dùng lại đúng form của trang Phòng ban và
             Chức vụ (cùng cách EmployeeForm.vue), không viết form rút gọn riêng. -->
        <DepartmentFormDialog
            v-model="quickDepartmentOpen"
            :department="null"
            :parent-options="departmentOptions"
            @saved="onQuickDepartmentSaved"
        />
        <PositionFormDialog
            v-model="quickPositionOpen"
            :position="null"
            :department-options="positionDepartmentOptions"
            :default-department-id="form.department_id"
            @saved="onQuickPositionSaved"
        />
    </FormDialog>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import recruitmentService from "../../services/recruitmentService";
import positionService from "../../services/positionService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import FormField from "../../components/common/FormField.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate, { todayIso } from "../../components/common/InputDate.vue";
import DepartmentFormDialog from "../Department/DepartmentForm.vue";
import PositionFormDialog from "../Position/PositionForm.vue";
import { useToastStore } from "../../stores/useToastStore";
import { useAuthStore } from "../../stores/authStore";
import { CONTRACT_TYPE_LABELS } from "../../composables/recruitmentStatus";
import {
    isInteger,
    maxLength,
    minValue,
    notEmpty,
    useClearErrorsOnEdit,
} from "../../composables/validationRules";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    // null = mở đợt mới, object = sửa đợt đang chọn
    opening: { type: Object, default: null },
    departmentOptions: { type: Array, default: () => [] },
});
const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const auth = useAuthStore();
const isEdit = computed(() => props.opening !== null);
const CONTRACT_TYPE_OPTIONS = Object.entries(CONTRACT_TYPE_LABELS).map(
    ([value, title]) => ({ title, value }),
);

const loading = ref(false);
const errors = ref({});
const loadError = ref("");
const allPositions = ref([]);

const form = reactive({
    title: "",
    department_id: null,
    position_id: null,
    contract_type: "thuc_tap",
    headcount: 1,
    deadline: "",
    description: "",
});

const positionOptions = computed(() =>
    allPositions.value
        .filter((p) => p.department_id === form.department_id)
        .map((p) => ({ title: p.name, value: p.id })),
);

function onDepartmentChange(value) {
    form.department_id = value;
    form.position_id = null;
}

// Tạo phòng ban/chức vụ dùng chung quyền department.manage — không có thì ẩn nút.
const canManageOrg = computed(() =>
    auth.permissions.includes("department.manage"),
);
const quickDepartmentOpen = ref(false);
const quickPositionOpen = ref(false);

// PositionForm dùng quy ước { title, id } (xem giải thích ở EmployeeForm.vue).
const positionDepartmentOptions = computed(() =>
    props.departmentOptions.map((option) => ({
        title: option.title,
        id: option.value,
    })),
);

// useDepartmentStore.create() đã tự nạp lại cây phòng ban -> departmentOptions có
// phòng mới; chọn luôn (phòng mới chưa có chức vụ nên bỏ chức vụ đang chọn).
function onQuickDepartmentSaved(created) {
    if (created?.id) {
        onDepartmentChange(created.id);
    }
}

async function onQuickPositionSaved(created) {
    if (!created?.id) {
        return;
    }
    await loadPositions();
    // Người dùng có thể đổi phòng ban ngay trong form tạo nhanh — đồng bộ theo.
    if (created.department_id && created.department_id !== form.department_id) {
        form.department_id = created.department_id;
    }
    form.position_id = created.id;
}

async function loadPositions() {
    try {
        const response = await positionService.list({ per_page: 1000 });
        allPositions.value = response.data.data;
    } catch {
        allPositions.value = [];
    }
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (!isOpen) {
            return;
        }
        const o = props.opening;
        Object.assign(form, {
            title: o?.title ?? "",
            department_id: o?.department_id ?? null,
            position_id: o?.position_id ?? null,
            contract_type: o?.contract_type ?? "thuc_tap",
            headcount: o?.headcount ?? 1,
            deadline: o?.deadline ?? "",
            description: o?.description ?? "",
        });
        errors.value = {};
        loadError.value = "";
        loadPositions();
    },
);

useClearErrorsOnEdit(form, () => errors.value);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    errors.value = {};
    loadError.value = "";
    loading.value = true;
    const payload = {
        ...form,
        deadline: form.deadline || null,
        description: form.description || null,
    };
    try {
        const response = isEdit.value
            ? await recruitmentService.updateOpening(props.opening.id, payload)
            : await recruitmentService.createOpening(payload);
        toast.success(
            isEdit.value ? "Đã cập nhật đợt tuyển." : "Đã mở đợt tuyển dụng.",
        );
        emit("saved", response.data.data);
        close();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors;
        } else {
            loadError.value =
                e.response?.data?.message ??
                "Không thể kết nối máy chủ, vui lòng thử lại.";
        }
    } finally {
        loading.value = false;
    }
}
</script>
