<template>
    <FormDialog
        :model-value="modelValue"
        title="Tạo checklist"
        subtitle="Dùng cho nhân viên cũ hoặc trường hợp chưa tự tạo. Nhân viên mới và đơn nghỉ việc đã duyệt đều tự có checklist."
        icon="mdi-clipboard-check-outline"
        size="sm"
        :loading="saving"
        :error="error"
        submit-label="Tạo checklist"
        @update:model-value="$emit('update:modelValue', $event)"
        @submit="submit"
    >
        <FormSection>
            <FormField label="Loại checklist" required>
                <v-btn-toggle
                    v-model="form.type"
                    mandatory
                    color="primary"
                    variant="outlined"
                    density="comfortable"
                    divided
                >
                    <v-btn value="onboarding" prepend-icon="mdi-account-plus-outline">Nhận việc</v-btn>
                    <v-btn value="offboarding" prepend-icon="mdi-account-arrow-right-outline">Nghỉ việc</v-btn>
                </v-btn-toggle>
            </FormField>
            <FormField label="Nhân viên" required>
                <SearchSelect
                    v-model="form.employee_id"
                    :items="employeeOptions"
                    :loading="loadingEmployees"
                    :rules="[(v) => !!v || 'Vui lòng chọn nhân viên']"
                    :error-messages="errors.employee_id"
                />
            </FormField>
            <FormField
                label="Mẫu checklist"
                :hint="
                    form.type === 'offboarding'
                        ? 'Hạn từng việc tính theo ngày làm việc cuối trong đơn nghỉ việc (chưa có đơn thì tính từ hôm nay).'
                        : 'Hạn từng việc tính theo ngày vào làm của nhân viên.'
                "
            >
                <v-select
                    v-model="form.template_id"
                    :items="templateOptions"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    :error-messages="errors.template_id"
                    no-data-text="Chưa có mẫu nào đang dùng"
                />
            </FormField>
        </FormSection>
    </FormDialog>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import onboardingService from "../../services/onboardingService";
import employeeService from "../../services/employeeService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import FormField from "../../components/common/FormField.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    defaultType: { type: String, default: "onboarding" },
});

const emit = defineEmits(["update:modelValue", "created"]);

const form = ref({ type: "onboarding", employee_id: null, template_id: null });
const errors = ref({});
const error = ref("");
const saving = ref(false);
const employees = ref([]);
const loadingEmployees = ref(false);
const templates = ref([]);

const WORKING = ["active", "probation", "intern"];

const employeeOptions = computed(() =>
    employees.value
        .filter((e) => WORKING.includes(e.employment_status))
        .map((e) => ({ title: `${e.full_name} (${e.code})`, value: e.id })),
);

const templateOptions = computed(() =>
    templates.value
        .filter((t) => t.type === form.value.type && t.is_active)
        .map((t) => ({ title: `${t.name} — ${t.items.length} việc${t.is_default ? " (mặc định)" : ""}`, value: t.id })),
);

function pickDefaultTemplate() {
    const list = templates.value.filter((t) => t.type === form.value.type && t.is_active);
    form.value.template_id = (list.find((t) => t.is_default) ?? list[0])?.id ?? null;
}

async function loadOptions() {
    loadingEmployees.value = true;
    try {
        const [employeeResponse, templateResponse] = await Promise.all([
            employeeService.list({ per_page: 500 }),
            onboardingService.templates(),
        ]);
        employees.value = employeeResponse.data.data;
        templates.value = templateResponse.data.data;
        pickDefaultTemplate();
    } catch (e) {
        error.value = e.response?.data?.message ?? "Không tải được danh sách nhân viên/mẫu.";
    } finally {
        loadingEmployees.value = false;
    }
}

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return;
        form.value = { type: props.defaultType, employee_id: null, template_id: null };
        errors.value = {};
        error.value = "";
        loadOptions();
    },
);

watch(() => form.value.type, pickDefaultTemplate);
watch(
    () => form.value.employee_id,
    () => delete errors.value.employee_id,
);

async function submit() {
    saving.value = true;
    errors.value = {};
    error.value = "";
    try {
        const response = await onboardingService.create(form.value);
        emit("created", response.data.data);
        emit("update:modelValue", false);
    } catch (e) {
        const fieldErrors = e.response?.data?.errors;
        if (fieldErrors) {
            errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, v[0]]));
        } else {
            error.value = e.response?.data?.message ?? "Không tạo được checklist.";
        }
    } finally {
        saving.value = false;
    }
}
</script>
