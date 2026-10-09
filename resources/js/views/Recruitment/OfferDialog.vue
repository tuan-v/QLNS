<template>
    <FormDialog
        :model-value="modelValue"
        title="Soạn thư mời nhận việc"
        :subtitle="candidate ? `Cho ${candidate.full_name} — gửi Admin duyệt, duyệt xong hệ thống tự gửi email cho ứng viên.` : ''"
        icon="mdi-email-check-outline"
        size="sm"
        :loading="saving"
        :error="error"
        submit-label="Gửi Admin duyệt"
        @update:model-value="$emit('update:modelValue', $event)"
        @submit="submit"
    >
        <FormSection>
            <FormField label="Loại hợp đồng" required>
                <v-select
                    v-model="form.contract_type"
                    :items="contractOptions"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    :error-messages="errors.contract_type"
                />
            </FormField>
            <FormField label="Mức lương (₫/tháng)" required>
                <InputMoney
                    v-model="form.salary"
                    :rules="[(v) => (v !== null && v !== undefined && v !== '') || 'Vui lòng nhập mức lương']"
                    :error-messages="errors.salary"
                />
            </FormField>
            <FormField label="Ngày bắt đầu làm việc" required>
                <InputDate
                    v-model="form.start_date"
                    :min="todayIso()"
                    :rules="[(v) => !!v || 'Vui lòng chọn ngày bắt đầu']"
                    :error-messages="errors.start_date"
                />
            </FormField>
            <FormField label="Hạn ứng viên trả lời" required hint="Quá hạn mà ứng viên chưa trả lời thì thư mời tự hết hiệu lực và trả lại suất.">
                <InputDate
                    v-model="form.response_deadline"
                    :min="todayIso()"
                    :max="form.start_date || undefined"
                    :rules="[(v) => !!v || 'Vui lòng chọn hạn trả lời']"
                    :error-messages="errors.response_deadline || deadlineError"
                />
            </FormField>
            <FormField label="Lời nhắn gửi ứng viên" span="full">
                <v-textarea
                    v-model="form.message"
                    rows="3"
                    auto-grow
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    placeholder="VD: Mời bạn có mặt lúc 8h30 tại tầng 3 để làm thủ tục nhận việc."
                    :error-messages="errors.message"
                />
            </FormField>
        </FormSection>
    </FormDialog>
</template>

<script setup>
import { computed, ref, watch } from "vue";
import recruitmentService from "../../services/recruitmentService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import FormField from "../../components/common/FormField.vue";
import InputMoney from "../../components/common/InputMoney.vue";
import InputDate, { shiftIsoDate, todayIso } from "../../components/common/InputDate.vue";
import { CONTRACT_TYPE_LABELS } from "../../composables/recruitmentStatus";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    candidate: { type: Object, default: null },
    // Loại hợp đồng mặc định = loại của đợt tuyển.
    defaultContractType: { type: String, default: "thu_viec" },
});

const emit = defineEmits(["update:modelValue", "saved"]);

const form = ref({});
const errors = ref({});
const error = ref("");
const saving = ref(false);

const contractOptions = Object.entries(CONTRACT_TYPE_LABELS).map(([value, title]) => ({ title, value }));

const deadlineError = computed(() =>
    form.value.start_date && form.value.response_deadline && form.value.response_deadline > form.value.start_date
        ? "Hạn trả lời phải trước hoặc bằng ngày bắt đầu"
        : "",
);

watch(
    () => props.modelValue,
    (open) => {
        if (!open) return;
        errors.value = {};
        error.value = "";
        // Mặc định: bắt đầu sau 1 tuần, trả lời trong 3 ngày.
        form.value = {
            contract_type: props.defaultContractType,
            salary: null,
            start_date: shiftIsoDate(todayIso(), 7),
            response_deadline: shiftIsoDate(todayIso(), 3),
            message: "",
        };
    },
);

async function submit() {
    if (deadlineError.value) return;
    saving.value = true;
    errors.value = {};
    error.value = "";
    try {
        await recruitmentService.createOffer(props.candidate.id, { ...form.value, message: form.value.message || null });
        emit("saved");
        emit("update:modelValue", false);
    } catch (e) {
        const fieldErrors = e.response?.data?.errors;
        if (fieldErrors) {
            errors.value = Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, v[0]]));
            if (fieldErrors.candidate) error.value = fieldErrors.candidate[0];
        } else {
            error.value = e.response?.data?.message ?? "Không gửi được thư mời.";
        }
    } finally {
        saving.value = false;
    }
}
</script>
