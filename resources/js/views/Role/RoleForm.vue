<template>
    <FormDialog
        :model-value="modelValue"
        eyebrow="VAI TRÒ"
        :title="isEdit ? 'Sửa vai trò' : 'Thêm vai trò'"
        :subtitle="
            isEdit
                ? 'Cập nhật tên và mô tả vai trò.'
                : 'Tạo vai trò mới — sau khi lưu có thể gán quyền qua nút \'Quản lý quyền\'.'
        "
        :error="store.loadError"
        :loading="store.loading"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Thêm mới'"
        icon="mdi-shield-account-outline"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Thông tin vai trò">
            <div class="mb-3">
                <div class="text-body-2 font-weight-medium mb-1">
                    Tên vai trò <span class="text-error">*</span>
                </div>
                <v-text-field
                    v-model="form.name"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    placeholder="Ví dụ: Kế toán"
                    :error-messages="store.errors.name"
                />
            </div>

            <div class="mb-3">
                <div class="text-body-2 font-weight-medium mb-1">Mô tả</div>
                <v-textarea
                    v-model="form.description"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    rows="3"
                    no-resize
                    placeholder="Vai trò này dùng cho ai, làm việc gì..."
                    :error-messages="store.errors.description"
                />
            </div>
        </FormSection>

        <template #footer-note>
            <span class="text-error">*</span> Thông tin bắt buộc
        </template>
    </FormDialog>
</template>

<script setup>
import { computed, reactive, watch } from "vue";
import { useRoleStore } from "../../stores/useRoleStore";
import { useToastStore } from "../../stores/useToastStore";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    // null = thêm mới, object = sửa vai trò đang chọn
    role: { type: Object, default: null },
});

const emit = defineEmits(["update:modelValue", "saved"]);

const store = useRoleStore();
const toast = useToastStore();

const isEdit = computed(() => props.role !== null);

const form = reactive({
    name: "",
    description: "",
});

function fillForm() {
    form.name = props.role?.name ?? "";
    form.description = props.role?.description ?? "";
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            store.resetErrors();
            fillForm();
        }
    },
);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    try {
        const saved = isEdit.value
            ? await store.update(props.role.id, { ...form })
            : await store.create({ ...form });
        toast.success(isEdit.value ? "Đã cập nhật vai trò." : "Đã thêm vai trò mới.");
        emit("saved", saved);
        close();
    } catch {
        // Lỗi đã được store xử lý (422 -> store.errors, còn lại -> store.loadError).
    }
}
</script>
