<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-badge-account-outline"
        size="md"
        :title="isEdit ? 'Sửa chức vụ' : 'Thêm chức vụ'"
        :subtitle="
            isEdit
                ? 'Cập nhật thông tin chức vụ.'
                : 'Tạo chức vụ mới cho 1 phòng ban.'
        "
        :error="loadError"
        :loading="loading"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Thêm mới'"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection
            title="Thông tin cơ bản"
            description="Tên và mã dùng để nhận diện chức vụ trong hệ thống."
            :columns="2"
        >
            <FormField label="Tên chức vụ" required>
                <v-text-field
                    v-model="form.name"
                    density="comfortable"
                    placeholder="Nhập tên chức vụ"
                    :error-messages="errors.name"
                />
            </FormField>

            <FormField label="Mã chức vụ" hint="Hệ thống tự sinh khi lưu.">
                <v-text-field
                    :model-value="isEdit ? position.code : 'Tự động sau khi lưu'"
                    density="comfortable"
                    readonly
                    prepend-inner-icon="mdi-auto-fix"
                    :class="{ 'text-medium-emphasis': !isEdit }"
                />
            </FormField>

            <FormField label="Phòng ban" required span="full">
                <SearchSelect
                    v-model="form.department_id"
                    :items="departmentOptions"
                    item-title="title"
                    item-value="id"
                    placeholder="Chọn phòng ban"
                    :error-messages="errors.department_id"
                />
            </FormField>
        </FormSection>

        <FormSection
            title="Cấp bậc & phụ cấp"
            description="Không bắt buộc — dùng khi tính lương và sắp xếp chức vụ."
            :columns="2"
        >
            <FormField label="Cấp bậc">
                <v-text-field
                    v-model.number="form.level"
                    type="number"
                    min="1"
                    density="comfortable"
                    placeholder="Ví dụ: 1"
                    :error-messages="errors.level"
                />
            </FormField>

            <FormField label="Phụ cấp chức vụ">
                <InputMoney
                    v-model="form.position_allowance"
                    :error-messages="errors.position_allowance"
                />
            </FormField>

            <FormField
                label="Trạng thái hoạt động"
                hint="Chức vụ ngừng hoạt động vẫn được lưu nhưng không dùng để phân công mới."
                span="full"
                inline
            >
                <v-switch
                    v-model="form.is_active"
                    color="primary"
                    density="compact"
                    hide-details
                    inset
                />
            </FormField>
        </FormSection>

        <template #footer-note>
            <span class="text-error">*</span> Thông tin bắt buộc
        </template>
    </FormDialog>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import positionService from "../../services/positionService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import FormField from "../../components/common/FormField.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputMoney from "../../components/common/InputMoney.vue";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    // null = thêm mới, object = sửa chức vụ đang chọn
    position: {
        type: Object,
        default: null,
    },
    departmentOptions: {
        type: Array,
        default: () => [],
    },
    // Phòng ban chọn sẵn khi THÊM MỚI. Dùng cho luồng tạo nhanh từ form nhân
    // viên: người dùng đang chọn dở một phòng ban thì chức vụ mới cũng nên
    // thuộc phòng ban đó, đỡ phải chọn lại.
    defaultDepartmentId: {
        type: [Number, String],
        default: null,
    },
});

const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();

const isEdit = computed(() => props.position !== null);

// Không dùng Pinia store cho Chức vụ (chỉ 1 trang dùng, xem CODE_MAP mục 7) —
// tự giữ loading/errors cục bộ trong chính form, cùng cách xử lý lỗi
// 422 -> errors, lỗi khác -> loadError như mọi store khác trong dự án.
const loading = ref(false);
const errors = ref({});
const loadError = ref("");

const form = reactive({
    name: "",
    department_id: null,
    level: null,
    position_allowance: null,
    is_active: true,
});

function fillForm() {
    form.name = props.position?.name ?? "";
    form.department_id =
        props.position?.department_id ?? props.defaultDepartmentId ?? null;
    form.level = props.position?.level ?? null;
    form.position_allowance = props.position?.position_allowance ?? null;
    // DB trả về 1/0, ép về boolean cho v-switch
    form.is_active = Boolean(props.position?.is_active ?? true);
}

// Mỗi lần mở modal: nạp lại dữ liệu và xóa lỗi của lần mở trước
watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            errors.value = {};
            loadError.value = "";
            fillForm();
        }
    },
);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    errors.value = {};
    loadError.value = "";
    loading.value = true;
    try {
        const response = isEdit.value
            ? await positionService.update(props.position.id, { ...form })
            : await positionService.create({ ...form });
        toast.success(
            isEdit.value ? "Đã cập nhật chức vụ." : "Đã thêm chức vụ mới.",
        );
        // Kèm bản ghi vừa lưu, cùng lý do như DepartmentForm.vue. Controller trả
        // thẳng model (`response()->json($position)`) nên không bọc trong `data`.
        emit("saved", response.data);
        close();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors;
        } else {
            loadError.value =
                e.response?.data?.message ??
                "Không thể kết nối máy chủ, vui lòng thử lại.";
        }
        // Giữ modal mở để người dùng sửa lại dữ liệu.
    } finally {
        loading.value = false;
    }
}
</script>
