<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-file-account-outline"
        size="md"
        title="Tải CV ứng viên"
        :subtitle="`Đợt tuyển: ${opening?.title ?? ''} — CV sẽ chờ Admin duyệt.`"
        :error="loadError"
        :loading="loading"
        submit-label="Gửi CV"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Thông tin ứng viên" :columns="2">
            <FormField label="Họ và tên" required span="full">
                <v-text-field
                    v-model="form.full_name"
                    density="comfortable"
                    :rules="[
                        notEmpty('Họ và tên'),
                        maxLength(255, 'Họ và tên'),
                    ]"
                    :error-messages="errors.full_name"
                />
            </FormField>
            <FormField
                label="Email"
                required
                hint="Dùng để gửi thư mời và kết quả"
            >
                <v-text-field
                    v-model="form.email"
                    type="email"
                    density="comfortable"
                    :rules="[notEmpty('Email'), isEmail('Email')]"
                    :error-messages="fieldErrors('email')"
                    :loading="checking.email"
                    @blur="checkDuplicate('email')"
                />
            </FormField>
            <FormField label="Số điện thoại">
                <v-text-field
                    v-model="form.phone"
                    density="comfortable"
                    inputmode="numeric"
                    maxlength="10"
                    :rules="[
                        (v) =>
                            !v ||
                            PHONE_PATTERN.test(v) ||
                            'Số điện thoại phải gồm đúng 10 chữ số',
                    ]"
                    :error-messages="fieldErrors('phone')"
                    :loading="checking.phone"
                    @blur="checkDuplicate('phone')"
                />
            </FormField>
            <FormField label="CV (PDF)" required span="full">
                <InputFile
                    v-model="form.cv_file"
                    :limit="CV_LIMIT"
                    :rules="[
                        (v) =>
                            (Array.isArray(v) ? v.length > 0 : !!v) ||
                            'Vui lòng đính kèm CV',
                    ]"
                    :error-messages="errors.cv_file"
                />
            </FormField>
            <FormField label="Ghi chú" span="full">
                <v-textarea
                    v-model="form.note"
                    rows="2"
                    density="comfortable"
                    :rules="[maxLength(2000, 'Ghi chú')]"
                    :error-messages="errors.note"
                />
            </FormField>
        </FormSection>
    </FormDialog>
</template>

<script setup>
import { reactive, ref, watch } from "vue";
import recruitmentService from "../../services/recruitmentService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import FormField from "../../components/common/FormField.vue";
import InputFile from "../../components/common/InputFile.vue";
import { useToastStore } from "../../stores/useToastStore";
import {
    isEmail,
    maxLength,
    notEmpty,
    useClearErrorsOnEdit,
} from "../../composables/validationRules";

const CV_LIMIT = { maxSizeMb: 10, formats: "PDF", accept: ".pdf" };

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    opening: { type: Object, default: null },
});
const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const loading = ref(false);
const errors = ref({});
const loadError = ref("");
const form = reactive({
    full_name: "",
    email: "",
    phone: "",
    note: "",
    cv_file: null,
});

/* ---- Kiểm tra trùng email/SĐT ngay khi rời ô (giống EmployeeForm.vue) ---- */
const PHONE_PATTERN = /^\d{10}$/;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const duplicateErrors = reactive({ email: null, phone: null });
const checking = reactive({ email: false, phone: false });
// Giá trị đã kiểm tra gần nhất — chỉ gọi lại API khi người dùng thật sự đổi giá trị.
const checkedValue = { email: null, phone: null };

function fieldErrors(key) {
    return [
        ...(errors.value[key] ?? []),
        ...(duplicateErrors[key] ? [duplicateErrors[key]] : []),
    ];
}

async function checkDuplicate(key) {
    const value = (form[key] ?? "").trim();
    const validFormat =
        key === "email" ? EMAIL_PATTERN.test(value) : PHONE_PATTERN.test(value);

    // Rỗng hoặc sai định dạng thì để rule định dạng báo, không hỏi server.
    if (!value || !validFormat) {
        duplicateErrors[key] = null;
        checkedValue[key] = null;
        return;
    }
    if (checkedValue[key] === value) {
        return;
    }

    checking[key] = true;
    try {
        const response = await recruitmentService.checkDuplicate(
            props.opening.id,
            { field: key, value },
        );
        // Người dùng đã gõ tiếp trong lúc chờ -> bỏ kết quả cũ.
        if ((form[key] ?? "").trim() !== value) {
            return;
        }
        duplicateErrors[key] = response.data.available
            ? null
            : response.data.message;
        checkedValue[key] = value;
    } catch {
        // Lỗi mạng: không chặn ở đây, backend vẫn kiểm tra lại khi lưu.
        duplicateErrors[key] = null;
    } finally {
        checking[key] = false;
    }
}

// Sửa ô thì xóa câu báo trùng cũ của ô đó (kiểm tra lại khi rời ô).
// Quên luôn giá trị đã kiểm tra: gõ lại đúng giá trị trùng cũ vẫn phải kiểm tra lại.
watch(
    () => form.email,
    () => {
        duplicateErrors.email = null;
        checkedValue.email = null;
    },
);
watch(
    () => form.phone,
    () => {
        duplicateErrors.phone = null;
        checkedValue.phone = null;
    },
);

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            Object.assign(form, {
                full_name: "",
                email: "",
                phone: "",
                note: "",
                cv_file: null,
            });
            Object.assign(duplicateErrors, { email: null, phone: null });
            Object.assign(checkedValue, { email: null, phone: null });
            errors.value = {};
            loadError.value = "";
        }
    },
);

useClearErrorsOnEdit(form, () => errors.value);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    // Đã biết trùng từ lúc rời ô thì không gửi (backend vẫn kiểm tra lại khi lưu).
    if (duplicateErrors.email || duplicateErrors.phone) {
        return;
    }
    errors.value = {};
    loadError.value = "";
    const file = Array.isArray(form.cv_file) ? form.cv_file[0] : form.cv_file;
    const data = new FormData();
    data.append("full_name", form.full_name);
    data.append("email", form.email);
    if (form.phone) data.append("phone", form.phone);
    if (form.note) data.append("note", form.note);
    if (file) data.append("cv_file", file);

    loading.value = true;
    try {
        await recruitmentService.addCandidate(props.opening.id, data);
        toast.success("Đã gửi CV, chờ Admin duyệt.");
        emit("saved");
        close();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors;
            // Lỗi của cả đợt tuyển (đủ CV, đã đóng, quá hạn) không thuộc ô nào.
            loadError.value = e.response.data.errors?.opening?.[0] ?? "";
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
