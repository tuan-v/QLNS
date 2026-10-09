<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-calendar-star"
        size="md"
        title="Thêm ngày nghỉ"
        subtitle="Ngày nghỉ riêng của công ty trong năm (lễ theo luật hệ thống tự tính)."
        :error="loadError"
        :loading="loading"
        submit-label="Thêm ngày nghỉ"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Thông tin ngày nghỉ" :columns="2">
            <FormField label="Tên ngày nghỉ" required span="full">
                <v-text-field
                    v-model="form.name"
                    density="comfortable"
                    placeholder="Ví dụ: Kỷ niệm thành lập công ty"
                    :rules="[notEmpty('Tên ngày nghỉ'), maxLength(255, 'Tên ngày nghỉ')]"
                    :error-messages="errors.name"
                />
            </FormField>

            <FormField label="Tính theo" span="full">
                <v-btn-toggle
                    v-model="form.calendar"
                    mandatory
                    density="comfortable"
                    color="primary"
                    variant="outlined"
                    divided
                >
                    <v-btn value="solar">Dương lịch</v-btn>
                    <v-btn value="lunar">Âm lịch</v-btn>
                </v-btn-toggle>
            </FormField>

            <FormField v-if="form.calendar === 'solar'" label="Ngày bắt đầu" required>
                <InputDate
                    v-model="form.start_date"
                    :rules="[notEmpty('Ngày bắt đầu')]"
                    :error-messages="errors.start_date"
                />
            </FormField>

            <template v-else>
                <FormField label="Ngày âm" required>
                    <v-select
                        v-model="form.lunar_day"
                        :items="lunarDayOptions"
                        density="comfortable"
                        placeholder="Chọn"
                        :rules="[notEmpty('Ngày âm')]"
                        :error-messages="errors.lunar_day"
                    />
                </FormField>
                <FormField label="Tháng âm" required>
                    <v-select
                        v-model="form.lunar_month"
                        :items="MONTH_OPTIONS"
                        density="comfortable"
                        placeholder="Chọn"
                        :rules="[notEmpty('Tháng âm')]"
                        :error-messages="errors.lunar_month"
                    />
                </FormField>
                <FormField label="Năm âm lịch" required>
                    <v-select
                        v-model="form.lunar_year"
                        :items="yearOptions"
                        density="comfortable"
                        placeholder="Chọn"
                        :rules="[notEmpty('Năm âm lịch')]"
                        :error-messages="errors.lunar_year"
                    />
                </FormField>
                <FormField label="Tháng nhuận">
                    <v-switch
                        v-model="form.lunar_leap"
                        color="primary"
                        density="comfortable"
                        hide-details
                        inset
                        label="Là tháng nhuận"
                    />
                </FormField>
                <FormField span="full">
                    <v-alert
                        v-if="lunarPreview"
                        type="info"
                        variant="tonal"
                        density="compact"
                    >
                        Tương ứng dương lịch:
                        <strong>{{ formatDate(lunarPreview.date) }}</strong>
                        ({{ lunarPreview.lunar_label }})
                    </v-alert>
                    <v-alert
                        v-else-if="lunarError"
                        type="warning"
                        variant="tonal"
                        density="compact"
                    >
                        {{ lunarError }}
                    </v-alert>
                </FormField>
            </template>

            <FormField label="Số ngày nghỉ" required>
                <v-select
                    v-model="form.duration_days"
                    :items="DURATION_OPTIONS"
                    density="comfortable"
                    :rules="[notEmpty('Số ngày nghỉ')]"
                    :error-messages="errors.duration_days"
                />
            </FormField>

            <FormField label="Hưởng lương">
                <v-switch
                    v-model="form.is_paid"
                    color="primary"
                    density="comfortable"
                    hide-details
                    inset
                    :label="form.is_paid ? 'Có lương' : 'Không lương'"
                />
                <v-switch
                    v-model="form.intern_paid"
                    color="primary"
                    density="comfortable"
                    hide-details
                    inset
                    :label="form.intern_paid ? 'Thực tập sinh có lương' : 'Thực tập sinh không lương'"
                />
            </FormField>

            <FormField label="Ghi chú" span="full">
                <v-textarea
                    v-model="form.note"
                    rows="2"
                    density="comfortable"
                    :rules="[maxLength(500, 'Ghi chú')]"
                    :error-messages="errors.note"
                />
            </FormField>
        </FormSection>
    </FormDialog>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import holidayService from "../../services/holidayService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import FormField from "../../components/common/FormField.vue";
import InputDate from "../../components/common/InputDate.vue";
import { useToastStore } from "../../stores/useToastStore";
import {
    maxLength,
    notEmpty,
    useClearErrorsOnEdit,
} from "../../composables/validationRules";
import {
    DURATION_OPTIONS,
    MONTH_OPTIONS,
    dayOptionsFor,
    lunarYearOptions,
} from "./holidayOptions";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    year: { type: Number, required: true },
});
const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const loading = ref(false);
const errors = ref({});
const loadError = ref("");
const lunarPreview = ref(null);
const lunarError = ref("");

const form = reactive({});
const lunarDayOptions = dayOptionsFor("lunar");
const yearOptions = computed(() => lunarYearOptions(props.year));

function resetForm() {
    Object.assign(form, {
        name: "",
        calendar: "solar",
        start_date: "",
        lunar_day: null,
        lunar_month: null,
        lunar_year: props.year,
        lunar_leap: false,
        duration_days: 1,
        is_paid: true,
        intern_paid: true,
        note: "",
    });
    lunarPreview.value = null;
    lunarError.value = "";
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            errors.value = {};
            loadError.value = "";
            resetForm();
        }
    },
);

useClearErrorsOnEdit(form, () => errors.value);

// Xem trước ngày dương lịch ngay khi nhập đủ ngày/tháng/năm âm.
let previewToken = 0;
watch(
    () => [form.calendar, form.lunar_day, form.lunar_month, form.lunar_year, form.lunar_leap],
    async () => {
        lunarPreview.value = null;
        lunarError.value = "";
        if (form.calendar !== "lunar" || !form.lunar_day || !form.lunar_month || !form.lunar_year) {
            return;
        }
        const token = ++previewToken;
        try {
            const response = await holidayService.convertLunar({
                lunar_day: form.lunar_day,
                lunar_month: form.lunar_month,
                lunar_year: form.lunar_year,
                lunar_leap: form.lunar_leap ? 1 : 0,
            });
            if (token === previewToken) lunarPreview.value = response.data;
        } catch (e) {
            if (token === previewToken) {
                const data = e.response?.data;
                lunarError.value =
                    Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? "Không quy đổi được ngày âm lịch.";
            }
        }
    },
);

function formatDate(value) {
    if (!value) return "";
    const [y, m, d] = value.split("-");
    return `${d}/${m}/${y}`;
}

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    loading.value = true;
    errors.value = {};
    loadError.value = "";
    try {
        const payload = { ...form, lunar_leap: form.lunar_leap ? 1 : 0 };
        if (form.calendar === "solar") {
            delete payload.lunar_day;
            delete payload.lunar_month;
            delete payload.lunar_year;
        } else {
            delete payload.start_date;
        }
        await holidayService.create(payload);
        toast.success(`Đã thêm ngày nghỉ "${form.name}".`);
        emit("saved");
        close();
    } catch (e) {
        if (e.response?.status === 422 && e.response.data?.errors) {
            errors.value = Object.fromEntries(
                Object.entries(e.response.data.errors).map(([key, value]) => [key, value[0]]),
            );
        } else {
            loadError.value = e.response?.data?.message ?? "Không thể thêm ngày nghỉ.";
        }
    } finally {
        loading.value = false;
    }
}
</script>
