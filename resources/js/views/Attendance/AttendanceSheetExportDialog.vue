<template>
    <FormDialog
        :model-value="modelValue"
        eyebrow="CHẤM CÔNG"
        title="Xuất bảng chấm công"
        subtitle="File Excel theo tháng: công từng ngày (1, 0.5…), nghỉ lễ, nghỉ phép, vắng và tổng công — tính đúng như bảng lương."
        :error="loadError"
        :loading="loading"
        submit-label="Tải file Excel"
        icon="mdi-microsoft-excel"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Kỳ chấm công">
            <v-row dense>
                <v-col cols="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Tháng <span class="text-error">*</span>
                    </div>
                    <v-select
                        v-model="form.month"
                        :items="monthOptions"
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
                    <v-select
                        v-model="form.year"
                        :items="yearOptions"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.year"
                    />
                </v-col>
                <v-col cols="12">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Phòng ban
                    </div>
                    <SearchSelect
                        v-model="form.department_id"
                        :items="departmentOptions"
                        placeholder="Toàn công ty"
                        :error-messages="errors.department_id"
                    />
                </v-col>
            </v-row>
        </FormSection>
    </FormDialog>
</template>

<script setup>
import { reactive, ref, watch } from "vue";
import attendanceService from "../../services/attendanceService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    // Mặc định lấy theo bộ lọc đang chọn ở trang Tổng hợp chấm công.
    date: { type: String, default: "" },
    departmentId: { type: [Number, null], default: null },
    departmentOptions: { type: Array, default: () => [] },
});
const emit = defineEmits(["update:modelValue"]);

const toast = useToastStore();
const loading = ref(false);
const errors = ref({});
const loadError = ref("");

const monthOptions = Array.from({ length: 12 }, (_, i) => ({
    title: `Tháng ${i + 1}`,
    value: i + 1,
}));
const currentYear = new Date().getFullYear();
const yearOptions = Array.from({ length: 6 }, (_, i) => currentYear + 1 - i);

const form = reactive({ month: 1, year: currentYear, department_id: null });

watch(
    () => props.modelValue,
    (isOpen) => {
        if (!isOpen) {
            return;
        }
        const base = props.date ? new Date(props.date) : new Date();
        form.month = base.getMonth() + 1;
        form.year = base.getFullYear();
        form.department_id = props.departmentId || null;
        errors.value = {};
        loadError.value = "";
    },
);

function close() {
    emit("update:modelValue", false);
}

// responseType "blob" nên lỗi 422 cũng về dạng Blob — đọc lại thành JSON để
// hiện lỗi dưới đúng ô nhập.
async function readBlobError(e) {
    try {
        return JSON.parse(await e.response.data.text());
    } catch {
        return null;
    }
}

async function submit() {
    errors.value = {};
    loadError.value = "";
    loading.value = true;
    try {
        const response = await attendanceService.exportSheet({
            month: form.month,
            year: form.year,
            department_id: form.department_id || undefined,
        });
        const url = URL.createObjectURL(response.data);
        const link = document.createElement("a");
        link.href = url;
        link.download = `bang-cham-cong-${String(form.month).padStart(2, "0")}-${form.year}.xlsx`;
        link.click();
        URL.revokeObjectURL(url);
        toast.success("Đã xuất bảng chấm công.");
        close();
    } catch (e) {
        const body = e.response ? await readBlobError(e) : null;
        if (e.response?.status === 422 && body?.errors) {
            errors.value = body.errors;
        } else {
            loadError.value =
                body?.message ?? "Không thể xuất bảng chấm công, vui lòng thử lại.";
        }
    } finally {
        loading.value = false;
    }
}
</script>
