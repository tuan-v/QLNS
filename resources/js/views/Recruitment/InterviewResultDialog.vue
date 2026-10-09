<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-clipboard-check-outline"
        size="sm"
        title="Kết quả phỏng vấn"
        :subtitle="`Ứng viên: ${candidate?.full_name ?? ''} — hệ thống tự gửi email kết quả cho ứng viên.`"
        :error="loadError"
        :loading="loading"
        submit-label="Lưu & gửi email"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="">
            <FormField label="Kết quả" required>
                <v-btn-toggle
                    v-model="form.result"
                    mandatory
                    divided
                    variant="outlined"
                    color="primary"
                >
                    <v-btn value="passed" prepend-icon="mdi-check">Đạt</v-btn>
                    <v-btn value="failed" prepend-icon="mdi-close"
                        >Không đạt</v-btn
                    >
                </v-btn-toggle>
                <div v-if="errors.result" class="text-error text-caption mt-1">
                    {{ errors.result[0] }}
                </div>
            </FormField>
            <FormField label="Nhận xét">
                <v-textarea
                    v-model="form.result_note"
                    rows="3"
                    density="comfortable"
                    :rules="[maxLength(2000, 'Nhận xét')]"
                    :error-messages="errors.result_note"
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
import { useToastStore } from "../../stores/useToastStore";
import { maxLength } from "../../composables/validationRules";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    candidate: { type: Object, default: null },
});
const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const loading = ref(false);
const errors = ref({});
const loadError = ref("");
const form = reactive({ result: "passed", result_note: "" });

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            Object.assign(form, { result: "passed", result_note: "" });
            errors.value = {};
            loadError.value = "";
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
        await recruitmentService.recordResult(props.candidate.id, {
            result: form.result,
            result_note: form.result_note || null,
        });
        toast.success("Đã lưu kết quả và gửi email cho ứng viên.");
        emit("saved");
        close();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors;
            loadError.value = e.response.data.errors?.result?.[0] ?? "";
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
