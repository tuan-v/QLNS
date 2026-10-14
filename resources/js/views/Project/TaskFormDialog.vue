<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-clipboard-check-outline"
        size="md"
        :title="isEdit ? 'Sửa công việc' : 'Giao công việc'"
        subtitle="Độ khó (điểm) là trọng số khi tính KPI — việc khó, khối lượng lớn nên đặt điểm cao."
        :error="loadError"
        :loading="loading"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Giao việc'"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection :columns="2">
            <FormField label="Tên công việc" required span="full">
                <v-text-field
                    v-model="form.title"
                    density="comfortable"
                    placeholder="Ví dụ: Thiết kế màn hình đăng nhập"
                    :rules="[notEmpty('Tên công việc'), maxLength(255, 'Tên công việc')]"
                    :error-messages="errors.title"
                />
            </FormField>
            <FormField label="Giao cho">
                <SearchSelect
                    v-model="form.assignee_employee_id"
                    :items="memberOptions"
                    placeholder="Chưa giao (chọn sau)"
                    clearable
                    :error-messages="errors.assignee_employee_id"
                />
            </FormField>
            <FormField label="Độ ưu tiên" required span="full">
                <v-chip-group v-model="form.priority" mandatory class="py-0">
                    <v-chip
                        v-for="(meta, value) in TASK_PRIORITY_MAP"
                        :key="value"
                        :value="value"
                        :prepend-icon="meta.icon"
                        :color="form.priority === value ? meta.color : undefined"
                        variant="outlined"
                        filter
                    >
                        {{ meta.label }}
                    </v-chip>
                </v-chip-group>
                <div v-if="errors.priority" class="text-error text-caption">{{ errors.priority[0] }}</div>
            </FormField>
            <FormField label="Độ khó (điểm — trọng số KPI)" required span="full">
                <v-chip-group v-model="form.points" mandatory class="py-0">
                    <v-chip
                        v-for="option in TASK_POINT_OPTIONS"
                        :key="option.value"
                        :value="option.value"
                        :color="form.points === option.value ? 'primary' : undefined"
                        variant="outlined"
                        class="points-chip"
                    >
                        {{ option.value }}
                    </v-chip>
                </v-chip-group>
                <div class="text-caption text-medium-emphasis">{{ pointsHint }}</div>
                <div v-if="errors.points" class="text-error text-caption">{{ errors.points[0] }}</div>
            </FormField>
            <FormField label="Ngày bắt đầu">
                <InputDate v-model="form.start_date" :error-messages="errors.start_date" />
            </FormField>
            <FormField label="Hạn hoàn thành" required>
                <InputDate
                    v-model="form.due_date"
                    :min="isEdit ? form.start_date || undefined : maxDate(todayIso(), form.start_date)"
                    :rules="[notEmpty('Hạn hoàn thành')]"
                    :error-messages="errors.due_date"
                />
            </FormField>
            <FormField label="Mô tả / yêu cầu đầu ra" span="full">
                <v-textarea
                    v-model="form.description"
                    rows="3"
                    auto-grow
                    density="comfortable"
                    placeholder="Cần làm gì, kết quả thế nào thì được coi là xong..."
                    :error-messages="errors.description"
                />
            </FormField>
        </FormSection>
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
import { TASK_POINT_OPTIONS, TASK_PRIORITY_MAP } from "../../composables/projectStatus";
import { maxLength, notEmpty, useClearErrorsOnEdit } from "../../composables/validationRules";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    projectId: { type: Number, required: true },
    // Thành viên dự án (project.members) — chỉ giao được cho thành viên.
    members: { type: Array, default: () => [] },
    // null = giao mới, object = sửa
    task: { type: Object, default: null },
});
const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const isEdit = computed(() => props.task !== null);
const pointsHint = computed(() => TASK_POINT_OPTIONS.find((o) => o.value === form.points)?.title.split(" — ")[1] ?? "");

const loading = ref(false);
const errors = ref({});
const loadError = ref("");

const form = reactive({
    title: "",
    assignee_employee_id: null,
    priority: "medium",
    points: 2,
    start_date: "",
    due_date: "",
    description: "",
});

const memberOptions = computed(() =>
    props.members.map((m) => ({
        value: m.employee.id,
        title: `${m.employee.full_name}${m.role === "lead" ? " (Lead)" : ""} — đang có ${m.open_tasks} việc`,
    })),
);

function maxDate(a, b) {
    return b && b > a ? b : a;
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (!isOpen) {
            return;
        }
        const t = props.task;
        Object.assign(form, {
            title: t?.title ?? "",
            assignee_employee_id: t?.assignee_employee_id ?? null,
            priority: t?.priority ?? "medium",
            points: t?.points ?? 2,
            start_date: t?.start_date ?? "",
            due_date: t?.due_date ?? "",
            description: t?.description ?? "",
        });
        errors.value = {};
        loadError.value = "";
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
        start_date: form.start_date || null,
        description: form.description || null,
    };
    try {
        const response = isEdit.value
            ? await projectService.updateTask(props.task.id, payload)
            : await projectService.createTask(props.projectId, payload);
        toast.success(isEdit.value ? "Đã cập nhật công việc." : "Đã giao công việc.");
        emit("saved", response.data.data);
        close();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors ?? {};
            loadError.value = errors.value.project?.[0] ?? errors.value.task?.[0] ?? "";
        } else {
            loadError.value = e.response?.data?.message ?? "Không thể kết nối máy chủ, vui lòng thử lại.";
        }
    } finally {
        loading.value = false;
    }
}
</script>

<style scoped>
.points-chip {
    min-width: 44px;
    justify-content: center;
    font-weight: 600;
}
</style>
