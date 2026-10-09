<template>
    <FormDialog
        :model-value="modelValue"
        :title="account ? 'Sửa tài khoản ngân hàng' : 'Thêm tài khoản ngân hàng'"
        :subtitle="byHr
            ? 'HR nhập thì tài khoản được xác nhận ngay.'
            : 'Tài khoản sẽ chờ phòng Nhân sự xác nhận trước khi dùng để nhận lương.'"
        icon="mdi-bank-outline"
        size="sm"
        :loading="saving"
        :error="error"
        @update:model-value="$emit('update:modelValue', $event)"
        @submit="submit"
    >
        <FormSection>
            <FormField label="Ngân hàng" required>
                <SearchSelect
                    v-model="form.bank_code"
                    :items="bankOptions"
                    :rules="[(v) => !!v || 'Vui lòng chọn ngân hàng']"
                    :error-messages="errors.bank_code"
                />
            </FormField>
            <FormField label="Số tài khoản" required hint="Chỉ gồm chữ số, có thể gõ cách khoảng.">
                <v-text-field
                    v-model="form.account_number"
                    inputmode="numeric"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    :rules="[(v) => /^\d{6,20}$/.test((v ?? '').replace(/\s+/g, '')) || 'Số tài khoản chỉ gồm chữ số, từ 6 đến 20 số']"
                    :error-messages="errors.account_number"
                />
            </FormField>
            <FormField label="Tên chủ tài khoản" required hint="Viết hoa không dấu, đúng như in trên thẻ — hệ thống tự đổi khi rời ô.">
                <v-text-field
                    v-model="form.account_holder"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    :rules="[(v) => !!v?.trim() || 'Vui lòng nhập tên chủ tài khoản']"
                    :error-messages="errors.account_holder"
                    @blur="form.account_holder = toHolderName(form.account_holder)"
                />
            </FormField>
            <FormField label="Chi nhánh">
                <v-text-field
                    v-model="form.bank_branch"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    :error-messages="errors.bank_branch"
                />
            </FormField>
        </FormSection>
    </FormDialog>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import bankAccountService from "../../services/bankAccountService";
import FormDialog from "../common/FormDialog.vue";
import FormSection from "../common/FormSection.vue";
import FormField from "../common/FormField.vue";
import SearchSelect from "../common/SearchSelect.vue";
import { normalizeVietnamese } from "../../composables/normalizeVietnamese";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    // null = API "của tôi".
    employeeId: { type: [Number, String, null], default: null },
    account: { type: Object, default: null },
    banks: { type: Array, default: () => [] },
    defaultHolder: { type: String, default: "" },
});

const emit = defineEmits(["update:modelValue", "saved"]);

const byHr = computed(() => props.employeeId !== null);
const form = ref({});
const errors = ref({});
const error = ref("");
const saving = ref(false);

const bankOptions = computed(() => props.banks.map((b) => ({ title: b.name, value: b.code })));

// "Nguyễn Văn A" -> "NGUYEN VAN A" (khớp chuẩn hóa ở SaveBankAccountRequest).
function toHolderName(value) {
    return normalizeVietnamese(value ?? "").toUpperCase().replace(/[^A-Z ]/g, "").replace(/\s+/g, " ").trim();
}

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return;
        errors.value = {};
        error.value = "";
        form.value = props.account
            ? {
                  bank_code: props.account.bank_code,
                  account_number: props.account.account_number,
                  account_holder: props.account.account_holder,
                  bank_branch: props.account.bank_branch ?? "",
              }
            : { bank_code: null, account_number: "", account_holder: toHolderName(props.defaultHolder), bank_branch: "" };
    },
);

async function submit() {
    saving.value = true;
    errors.value = {};
    error.value = "";
    const payload = { ...form.value, account_holder: toHolderName(form.value.account_holder), bank_branch: form.value.bank_branch || null };
    try {
        const response = props.account
            ? await bankAccountService.update(props.employeeId, props.account.id, payload)
            : await bankAccountService.create(props.employeeId, payload);
        emit("saved", response.data.data);
        emit("update:modelValue", false);
    } catch (e) {
        const fieldErrors = e.response?.data?.errors;
        if (fieldErrors) {
            errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, v[0]]));
        } else {
            error.value = e.response?.data?.message ?? "Không lưu được tài khoản ngân hàng.";
        }
    } finally {
        saving.value = false;
    }
}
</script>
