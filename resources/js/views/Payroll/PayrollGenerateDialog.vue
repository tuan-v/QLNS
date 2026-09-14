<template>
    <FormDialog
        :model-value="modelValue"
        eyebrow="BẢNG LƯƠNG"
        title="Tạo bảng lương"
        subtitle="Hệ thống tự tính lương cho toàn bộ nhân viên đang làm việc trong kỳ đã chọn."
        :error="loadError"
        :loading="loading"
        submit-label="Tạo bảng lương"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Kỳ lương">
            <v-row dense>
                <v-col cols="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Tháng <span class="text-error">*</span>
                    </div>
                    <v-select
                        v-model="form.month"
                        :items="monthOptions"
                        item-title="title"
                        item-value="value"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.month"
                    />
                </v-col>
                <v-col cols="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Năm <span class="text-error">*</span>
                    </div>
                    <v-text-field
                        v-model.number="form.year"
                        type="number"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.year"
                    />
                </v-col>
            </v-row>
        </FormSection>
    </FormDialog>
</template>

<script setup>
import { reactive, ref, watch } from "vue";
import payrollService from "../../services/payrollService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
});
const emit = defineEmits(["update:modelValue", "generated"]);

const toast = useToastStore();

const loading = ref(false);
const errors = ref({});
const loadError = ref("");

const monthOptions = Array.from({ length: 12 }, (_, i) => ({
    title: `Tháng ${i + 1}`,
    value: i + 1,
}));

const now = new Date();
const form = reactive({
    month: now.getMonth() + 1,
    year: now.getFullYear(),
});

// Giống PositionForm.vue: xóa lỗi lần trước mỗi khi mở dialog.
watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
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
        const response = await payrollService.generate({ ...form });
        toast.success("Đã tạo bảng lương.");
        // PayrollController::generate() bọc trong "data" (đã sửa lúc review
        // Backend) nên đọc response.data.data, khác PositionForm.vue.
        emit("generated", response.data.data);
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
