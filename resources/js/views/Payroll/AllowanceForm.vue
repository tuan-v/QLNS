<template>
    <FormDialog
        :model-value="modelValue"
        :title="isEdit ? 'Sửa khoản phụ cấp' : 'Thêm khoản phụ cấp / trợ cấp'"
        subtitle="Khai cách tính, thuế, bảo hiểm và mức gợi ý theo loại nhân viên."
        :loading="saving"
        :error="error"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Thêm khoản'"
        size="lg"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Thông tin">
            <v-row dense>
                <v-col cols="12" sm="8">
                    <div class="field-label">Tên khoản <span class="text-error">*</span></div>
                    <v-text-field
                        v-model="form.name"
                        placeholder="Ví dụ: Ăn trưa, Nhà ở"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :rules="[notEmpty('Tên khoản'), maxLength(150, 'Tên khoản')]"
                        :error-messages="errors.name"
                    />
                </v-col>
                <v-col cols="12" sm="4">
                    <div class="field-label">Loại</div>
                    <v-btn-toggle
                        v-model="form.component_type"
                        mandatory
                        divided
                        variant="outlined"
                        color="primary"
                        density="comfortable"
                        class="w-100"
                    >
                        <v-btn v-for="(label, key) in types" :key="key" :value="key" class="flex-grow-1">{{
                            label
                        }}</v-btn>
                    </v-btn-toggle>
                </v-col>
                <v-col cols="12">
                    <div class="field-label">Mô tả</div>
                    <v-text-field
                        v-model="form.description"
                        placeholder="Không bắt buộc"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.description"
                    />
                </v-col>
                <v-col cols="12">
                    <div class="field-label">Cách tính khi không đi làm đủ tháng</div>
                    <v-select
                        v-model="form.calculation_method"
                        :items="methodOptions"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :hint="METHOD_HINTS[form.calculation_method]"
                        persistent-hint
                        :error-messages="errors.calculation_method"
                    />
                </v-col>
            </v-row>
        </FormSection>

        <FormSection title="Thuế và bảo hiểm">
            <v-switch
                v-model="form.is_taxable"
                color="primary"
                inset
                hide-details
                label="Tính vào thu nhập chịu thuế TNCN"
            />
            <div v-if="form.is_taxable" class="mt-2 mb-2">
                <div class="field-label">Phần được miễn thuế mỗi tháng</div>
                <InputMoney
                    v-model="form.tax_free_amount"
                    placeholder="Để trống nếu chịu thuế toàn bộ"
                    suffix="đ"
                    :error-messages="errors.tax_free_amount"
                />
                <div class="text-caption text-medium-emphasis mt-1">
                    Ví dụ tiền ăn giữa ca được miễn thuế tới 730.000đ/tháng.
                </div>
            </div>
            <div v-else class="text-caption text-medium-emphasis mb-2 ml-1">
                Không chịu thuế, ví dụ khoán điện thoại, công tác phí theo quy chế công ty.
            </div>
            <v-switch
                v-model="form.in_insurance_salary"
                color="primary"
                inset
                hide-details
                label="Tính vào lương đóng bảo hiểm"
            />
            <div class="text-caption text-medium-emphasis ml-1">
                Bật cho phụ cấp ghi trong hợp đồng như chức vụ, trách nhiệm. Ăn trưa, xăng xe, điện thoại thì tắt.
            </div>
        </FormSection>

        <FormSection :title="isDaily ? 'Mức gợi ý mỗi ngày làm tại công ty' : 'Mức gợi ý mỗi tháng'">
            <v-switch
                v-model="form.applies_to_all"
                color="primary"
                inset
                hide-details
                label="Mọi nhân viên tự được hưởng theo mức gợi ý"
            />
            <div class="text-caption text-medium-emphasis mb-3 ml-1">
                Bật: không cần đặt cho từng người, HR chỉ tắt riêng ai không được hưởng. Tắt: chỉ người được HR đặt mới
                có. Ô Trưởng phòng để trống thì Trưởng phòng dùng mức theo hợp đồng.
            </div>
            <v-row dense>
                <v-col v-for="(label, key) in suggestionKeys" :key="key" cols="12" sm="6">
                    <div class="field-label">{{ label }}</div>
                    <InputMoney
                        v-model="form.suggested_amounts[key]"
                        :placeholder="key === 'head' ? 'Theo hợp đồng' : '0'"
                        :suffix="isDaily ? 'đ/ngày' : 'đ'"
                        :error-messages="errors[`suggested_amounts.${key}`]"
                    />
                </v-col>
            </v-row>
        </FormSection>

        <FormSection v-if="isEdit" title="Trạng thái">
            <v-switch
                v-model="form.is_active"
                color="primary"
                inset
                hide-details
                :label="form.is_active ? 'Đang sử dụng' : 'Ngừng sử dụng (không đặt thêm cho ai)'"
            />
        </FormSection>
    </FormDialog>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import InputMoney from "../../components/common/InputMoney.vue";
import allowanceService from "../../services/allowanceService";
import { useToastStore } from "../../stores/useToastStore";
import { maxLength, notEmpty } from "../../composables/validationRules";

// Thêm / sửa 1 khoản trong danh mục phụ cấp & trợ cấp (trang Allowances.vue).
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    component: { type: Object, default: null },
    types: { type: Object, default: () => ({}) },
    methods: { type: Object, default: () => ({}) },
    suggestionKeys: { type: Object, default: () => ({}) },
});
const emit = defineEmits(["update:modelValue", "saved"]);
const toast = useToastStore();

const METHOD_HINTS = {
    fixed: "Trả đủ mức tháng, không phụ thuộc ngày công. Ví dụ: điện thoại, trách nhiệm.",
    per_workday: "Mức tháng × ngày đi làm thực tế / công chuẩn. Nghỉ phép không được. Ví dụ: ăn trưa.",
    prorated: "Mức tháng × (ngày đi làm + ngày phép có lương) / công chuẩn. Ví dụ: xăng xe.",
    per_office_day:
        "Đơn giá × số ngày làm TẠI CÔNG TY (chọn khi chấm công vào). Ngày làm online không tính, nửa ngày vẫn tính 1 ngày. Ví dụ: ăn trưa.",
};

const isEdit = computed(() => !!props.component);
const isDaily = computed(() => form.calculation_method === "per_office_day");
const methodOptions = computed(() => Object.entries(props.methods).map(([value, title]) => ({ value, title })));
const saving = ref(false);
const error = ref("");
const errors = ref({});

const blank = () => ({
    name: "",
    description: "",
    component_type: "allowance",
    calculation_method: "fixed",
    is_taxable: true,
    tax_free_amount: null,
    in_insurance_salary: false,
    applies_to_all: false,
    suggested_amounts: { chinh_thuc: 0, thu_viec: 0, thuc_tap: 0, head: null },
    is_active: true,
});
const form = reactive(blank());

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return;
        error.value = "";
        errors.value = {};
        const c = props.component;
        Object.assign(
            form,
            blank(),
            c
                ? {
                      name: c.name,
                      description: c.description ?? "",
                      component_type: c.component_type,
                      calculation_method: c.calculation_method ?? "fixed",
                      is_taxable: c.is_taxable,
                      tax_free_amount: c.tax_free_amount,
                      in_insurance_salary: c.in_insurance_salary,
                      applies_to_all: c.applies_to_all,
                      suggested_amounts: { ...blank().suggested_amounts, ...(c.suggested_amounts ?? {}) },
                      is_active: c.is_active,
                  }
                : {},
        );
    },
);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    saving.value = true;
    error.value = "";
    errors.value = {};
    try {
        const payload = { ...form, suggested_amounts: { ...form.suggested_amounts } };
        if (isEdit.value) {
            await allowanceService.update(props.component.id, payload);
            toast.success("Đã lưu khoản phụ cấp.");
        } else {
            await allowanceService.create(payload);
            toast.success("Đã thêm khoản phụ cấp.");
        }
        emit("saved");
        close();
    } catch (e) {
        errors.value = e.response?.data?.errors ?? {};
        error.value = e.response?.status === 422 ? "" : (e.response?.data?.message ?? "Không lưu được.");
    } finally {
        saving.value = false;
    }
}
</script>

<style scoped>
.field-label {
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: 4px;
}
</style>
