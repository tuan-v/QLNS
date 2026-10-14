<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-folder-account-outline"
        size="md"
        :title="isEdit ? 'Sửa dự án' : 'Tạo dự án'"
        subtitle="Lead dự án sẽ tự thêm thành viên và giao việc. Quyền Lead chỉ áp dụng trong dự án này."
        :error="loadError"
        :loading="loading"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Tạo dự án'"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Thông tin dự án" :columns="2">
            <FormField label="Tên dự án" required span="full">
                <v-text-field
                    v-model="form.name"
                    density="comfortable"
                    placeholder="Ví dụ: Xây dựng hệ thống chấm công mới"
                    :rules="[notEmpty('Tên dự án'), maxLength(255, 'Tên dự án')]"
                    :error-messages="errors.name"
                />
            </FormField>
            <FormField label="Lead dự án" required>
                <SearchSelect
                    v-model="form.lead_employee_id"
                    :items="leadOptions"
                    :loading="leadLoading"
                    placeholder="Tìm theo tên hoặc mã nhân viên"
                    :rules="[notEmpty('Lead dự án')]"
                    :error-messages="errors.lead_employee_id"
                    no-filter
                    @update:search="onLeadSearch"
                />
            </FormField>
            <FormField label="Phòng ban phụ trách">
                <SearchSelect
                    v-model="form.department_id"
                    :items="departmentOptions"
                    placeholder="Không bắt buộc"
                    clearable
                    :error-messages="errors.department_id"
                />
            </FormField>
            <FormField label="Ngày bắt đầu" required>
                <InputDate v-model="form.start_date" :error-messages="errors.start_date" />
            </FormField>
            <FormField label="Ngày kết thúc dự kiến">
                <InputDate
                    v-model="form.end_date"
                    :min="form.start_date || undefined"
                    :error-messages="errors.end_date"
                />
            </FormField>
            <FormField v-if="isEdit" label="Trạng thái" span="full">
                <v-select
                    v-model="form.status"
                    :items="statusOptions"
                    density="comfortable"
                    :error-messages="errors.status"
                />
            </FormField>
            <FormField label="Mô tả / mục tiêu" span="full">
                <v-textarea
                    v-model="form.description"
                    rows="3"
                    auto-grow
                    density="comfortable"
                    placeholder="Mục tiêu, phạm vi, kết quả cần đạt..."
                    :error-messages="errors.description"
                />
            </FormField>
        </FormSection>
        <v-alert v-if="leadChanged" type="info" variant="tonal" density="compact" class="mt-2">
            Lead cũ vẫn ở lại dự án với vai trò thành viên (giữ các việc đang làm dở).
        </v-alert>
    </FormDialog>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import projectService from "../../services/projectService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import FormField from "../../components/common/FormField.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate, { todayIso } from "../../components/common/InputDate.vue";
import { useToastStore } from "../../stores/useToastStore";
import { PROJECT_STATUS_MAP, toOptions } from "../../composables/projectStatus";
import { maxLength, notEmpty, useClearErrorsOnEdit } from "../../composables/validationRules";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    // null = tạo mới, object = sửa dự án đang chọn
    project: { type: Object, default: null },
    departmentOptions: { type: Array, default: () => [] },
});
const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const isEdit = computed(() => props.project !== null);
const statusOptions = toOptions(PROJECT_STATUS_MAP);

const loading = ref(false);
const errors = ref({});
const loadError = ref("");
const leadCandidates = ref([]);
const leadLoading = ref(false);

const form = reactive({
    name: "",
    lead_employee_id: null,
    department_id: null,
    start_date: "",
    end_date: "",
    status: "active",
    description: "",
});

const leadChanged = computed(() => isEdit.value && form.lead_employee_id !== props.project.lead_employee_id);

// Lead đang chọn luôn có trong danh sách (kể cả khi không khớp từ khóa đang gõ).
const leadOptions = computed(() => {
    const list = [...leadCandidates.value];
    const current = props.project?.lead;
    if (current && !list.some((e) => e.id === current.id)) {
        list.unshift(current);
    }
    return list.map((e) => ({
        value: e.id,
        title: `${e.full_name} (${e.code})${e.position ? " · " + e.position : ""}`,
    }));
});

let searchTimer = null;
function onLeadSearch(text) {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadLeads(text), 300);
}

async function loadLeads(search = "") {
    leadLoading.value = true;
    try {
        leadCandidates.value = (await projectService.leadCandidates(search || undefined)).data.data;
    } catch {
        leadCandidates.value = [];
    } finally {
        leadLoading.value = false;
    }
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (!isOpen) {
            return;
        }
        const p = props.project;
        Object.assign(form, {
            name: p?.name ?? "",
            lead_employee_id: p?.lead_employee_id ?? null,
            department_id: p?.department_id ?? null,
            start_date: p?.start_date ?? todayIso(),
            end_date: p?.end_date ?? "",
            status: p?.status ?? "active",
            description: p?.description ?? "",
        });
        errors.value = {};
        loadError.value = "";
        loadLeads();
    },
);

useClearErrorsOnEdit(form, () => errors.value);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    errors.value = {};
    loadError.value = "";
    loading.value = true;
    const payload = {
        ...form,
        end_date: form.end_date || null,
        description: form.description || null,
        ...(isEdit.value ? {} : { status: undefined }),
    };
    try {
        const response = isEdit.value
            ? await projectService.update(props.project.id, payload)
            : await projectService.create(payload);
        toast.success(isEdit.value ? "Đã cập nhật dự án." : "Đã tạo dự án.");
        emit("saved", response.data.data);
        close();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors;
        } else {
            loadError.value = e.response?.data?.message ?? "Không thể kết nối máy chủ, vui lòng thử lại.";
        }
    } finally {
        loading.value = false;
    }
}
</script>
