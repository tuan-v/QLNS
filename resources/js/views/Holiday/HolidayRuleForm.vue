<template>
    <FormDialog
        :model-value="modelValue"
        icon="mdi-calendar-sync-outline"
        size="md"
        :title="isEdit ? 'Sửa quy tắc ngày nghỉ' : 'Thêm quy tắc lặp hằng năm'"
        :subtitle="
            rule?.is_system
                ? 'Ngày lễ theo luật: giữ nguyên ngày gốc, chỉ chỉnh cách nghỉ.'
                : 'Hệ thống tự tính ngày nghỉ này cho mọi năm.'
        "
        :error="loadError"
        :loading="loading"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Thêm quy tắc'"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Ngày gốc" :columns="2">
            <FormField label="Tên ngày nghỉ" required span="full">
                <v-text-field
                    v-model="form.name"
                    density="comfortable"
                    :readonly="rule?.is_system"
                    :rules="[notEmpty('Tên ngày nghỉ'), maxLength(150, 'Tên ngày nghỉ')]"
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
                    :disabled="rule?.is_system"
                >
                    <v-btn value="solar">Dương lịch</v-btn>
                    <v-btn value="lunar">Âm lịch</v-btn>
                </v-btn-toggle>
            </FormField>
            <FormField :label="form.calendar === 'lunar' ? 'Tháng âm' : 'Tháng'" required>
                <v-select
                    v-model="form.month"
                    :items="MONTH_OPTIONS"
                    density="comfortable"
                    placeholder="Chọn tháng"
                    :readonly="rule?.is_system"
                    :rules="[notEmpty('Tháng')]"
                    :error-messages="errors.month"
                />
            </FormField>
            <FormField :label="form.calendar === 'lunar' ? 'Ngày âm' : 'Ngày'" required>
                <v-select
                    v-model="form.day"
                    :items="dayOptions"
                    density="comfortable"
                    placeholder="Chọn ngày"
                    :readonly="rule?.is_system"
                    :rules="[notEmpty('Ngày')]"
                    :error-messages="errors.day"
                />
            </FormField>
        </FormSection>

        <FormSection title="Cách nghỉ" :columns="2">
            <FormField
                label="Bắt đầu nghỉ"
                :hint="
                    form.auto_adjacent && form.duration_days === 2
                        ? 'Đang tự chọn ngày liền kề nối với cuối tuần.'
                        : 'Vd Tết: trước 1 ngày = nghỉ từ 30 Tết.'
                "
            >
                <v-select
                    v-model="form.offset_days"
                    :items="OFFSET_OPTIONS"
                    density="comfortable"
                    :disabled="form.auto_adjacent && form.duration_days === 2"
                    :error-messages="errors.offset_days"
                />
            </FormField>
            <FormField
                label="Ngày liền kề"
                span="full"
                hint="Với ngày lễ 2 ngày (vd Quốc khánh): tự chọn ngày trước hoặc sau ngày gốc sao cho nối với cuối tuần; ngày gốc rơi Thứ 4 thì chọn ngày trước."
            >
                <v-switch
                    v-model="form.auto_adjacent"
                    color="primary"
                    density="compact"
                    hide-details
                    inset
                    :disabled="form.duration_days !== 2"
                    label="Tự chọn ngày liền kề nối với cuối tuần"
                />
            </FormField>
            <FormField label="Nhắc HR xác nhận" hint="Nhắc HR đối chiếu lịch chính thức trước ngày nghỉ.">
                <v-select
                    v-model="form.confirm_remind_days_before"
                    :items="REMIND_OPTIONS"
                    density="comfortable"
                    :error-messages="errors.confirm_remind_days_before"
                />
            </FormField>
            <FormField label="Số ngày nghỉ" required>
                <v-select
                    v-model="form.duration_days"
                    :items="DURATION_OPTIONS"
                    density="comfortable"
                    :rules="[notEmpty('Số ngày nghỉ')]"
                    :error-messages="errors.duration_days"
                />
            </FormField>
            <FormField label="Báo trước" hint="Tự gửi thông báo cho nhân viên trước ngày nghỉ.">
                <v-select
                    v-model="form.notify_days_before"
                    :items="NOTIFY_OPTIONS"
                    density="comfortable"
                    :error-messages="errors.notify_days_before"
                />
            </FormField>
            <FormField label="Áp dụng từ ngày" hint="Bỏ trống = áp dụng cho mọi năm.">
                <InputDate
                    v-model="form.effective_from"
                    :error-messages="errors.effective_from"
                />
            </FormField>
            <FormField label="Tùy chọn">
                <v-switch
                    v-model="form.compensate_weekend"
                    color="primary"
                    density="compact"
                    hide-details
                    inset
                    label="Nghỉ bù khi trùng T7/CN"
                />
                <v-switch
                    v-model="form.is_paid"
                    color="primary"
                    density="compact"
                    hide-details
                    inset
                    label="Hưởng lương"
                />
                <v-switch
                    v-model="form.intern_paid"
                    color="primary"
                    density="compact"
                    hide-details
                    inset
                    label="Thực tập sinh được hưởng lương"
                />
                <v-switch
                    v-model="form.is_active"
                    color="primary"
                    density="compact"
                    hide-details
                    inset
                    label="Đang áp dụng"
                />
            </FormField>
            <FormField label="Mô tả" span="full">
                <v-textarea
                    v-model="form.description"
                    rows="2"
                    density="comfortable"
                    :rules="[maxLength(500, 'Mô tả')]"
                    :error-messages="errors.description"
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
import { useChangeGuard } from "../../composables/useChangeGuard";
import {
    maxLength,
    notEmpty,
    useClearErrorsOnEdit,
} from "../../composables/validationRules";
import {
    DURATION_OPTIONS,
    MONTH_OPTIONS,
    NOTIFY_OPTIONS,
    OFFSET_OPTIONS,
    REMIND_OPTIONS,
    dayOptionsFor,
} from "./holidayOptions";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    rule: { type: Object, default: null },
});
const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();
const isEdit = computed(() => props.rule !== null);
const loading = ref(false);
const errors = ref({});
const loadError = ref("");
const form = reactive({});
const guard = useChangeGuard(() => form);

function fillForm() {
    const r = props.rule;
    Object.assign(form, {
        name: r?.name ?? "",
        calendar: r?.calendar ?? "solar",
        day: r?.day ?? null,
        month: r?.month ?? null,
        offset_days: r?.offset_days ?? 0,
        duration_days: r?.duration_days ?? 1,
        notify_days_before: r?.notify_days_before ?? 7,
        compensate_weekend: r?.compensate_weekend ?? false,
        is_paid: r?.is_paid ?? true,
        intern_paid: r?.intern_paid ?? true,
        is_active: r?.is_active ?? true,
        auto_adjacent: r?.auto_adjacent ?? false,
        confirm_remind_days_before: r?.confirm_remind_days_before ?? 45,
        effective_from: r?.effective_from ?? null,
        description: r?.description ?? "",
    });
}

watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            errors.value = {};
            loadError.value = "";
            fillForm();
            guard.takeSnapshot();
        }
    },
);

useClearErrorsOnEdit(form, () => errors.value);

// Danh sách ngày theo tháng: dương lịch đúng số ngày của tháng (tháng 2 tới 29),
// âm lịch tối đa 30. Đổi tháng mà ngày đang chọn không còn hợp lệ thì bỏ chọn.
const dayOptions = computed(() => dayOptionsFor(form.calendar, form.month));
watch(
    () => [form.calendar, form.month],
    () => {
        if (form.day && !dayOptions.value.some((o) => o.value === form.day)) {
            form.day = null;
        }
    },
);

function close() {
    emit("update:modelValue", false);
}

async function submit() {
    if (isEdit.value && guard.skipIfUnchanged()) {
        close();
        return;
    }
    loading.value = true;
    errors.value = {};
    loadError.value = "";
    try {
        if (isEdit.value) {
            await holidayService.updateRule(props.rule.id, { ...form });
            toast.success("Đã cập nhật quy tắc và tính lại lịch nghỉ.");
        } else {
            await holidayService.createRule({ ...form });
            toast.success("Đã thêm quy tắc và tính lịch nghỉ năm nay, năm sau.");
        }
        emit("saved");
        close();
    } catch (e) {
        if (e.response?.status === 422 && e.response.data?.errors) {
            errors.value = Object.fromEntries(
                Object.entries(e.response.data.errors).map(([key, value]) => [key, value[0]]),
            );
        } else {
            loadError.value = e.response?.data?.message ?? "Không thể lưu quy tắc.";
        }
    } finally {
        loading.value = false;
    }
}
</script>
