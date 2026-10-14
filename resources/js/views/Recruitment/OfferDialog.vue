<template>
    <FormDialog
        :model-value="modelValue"
        :title="isEdit ? 'Sửa thư mời nhận việc' : 'Soạn thư mời nhận việc'"
        :subtitle="
            candidate
                ? isEdit
                    ? `Cho ${candidate.full_name} — offer đang chờ duyệt, sửa xong vẫn cần Admin duyệt để gửi.`
                    : `Cho ${candidate.full_name} — gửi Admin duyệt, duyệt xong hệ thống tự gửi email cho ứng viên.`
                : ''
        "
        icon="mdi-email-check-outline"
        size="sm"
        :loading="saving"
        :error="error"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Gửi Admin duyệt'"
        @update:model-value="$emit('update:modelValue', $event)"
        @submit="submit"
    >
        <FormSection>
            <!-- Phòng ban / chức vụ chốt cho ứng viên — điền sẵn theo đợt tuyển hoặc vị trí ứng tuyển. -->
            <FormField label="Phòng ban" required>
                <v-autocomplete
                    v-model="form.department_id"
                    :items="departmentOptions"
                    placeholder="Chọn phòng ban"
                    :rules="[(v) => !!v || 'Vui lòng chọn phòng ban']"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    :error-messages="errors.department_id"
                    @update:model-value="onDepartmentChange"
                />
            </FormField>
            <FormField label="Chức vụ">
                <v-autocomplete
                    v-model="form.position_id"
                    :items="positionOptions"
                    :placeholder="form.department_id ? 'Chọn chức vụ' : 'Chọn phòng ban trước'"
                    :disabled="!form.department_id"
                    clearable
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    :error-messages="errors.position_id"
                />
            </FormField>
            <FormField label="Loại hợp đồng" required>
                <v-select
                    v-model="form.contract_type"
                    :items="contractOptions"
                    placeholder="Chọn loại hợp đồng"
                    :rules="[(v) => !!v || 'Vui lòng chọn loại hợp đồng']"
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
            <FormField
                label="Hạn ứng viên trả lời"
                required
                hint="Quá hạn mà ứng viên chưa trả lời thì thư mời tự hết hiệu lực và trả lại suất."
            >
                <InputDate
                    v-model="form.response_deadline"
                    :min="todayIso()"
                    :max="form.start_date || undefined"
                    :rules="[(v) => !!v || 'Vui lòng chọn hạn trả lời']"
                    :error-messages="errors.response_deadline || deadlineError"
                />
            </FormField>
            <!-- Email không có link: ứng viên liên hệ người này để báo chấp nhận / từ chối. -->
            <FormField label="Người liên hệ" required>
                <v-text-field
                    v-model="form.contact_name"
                    :rules="[(v) => !!v?.trim() || 'Vui lòng nhập người liên hệ']"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    :error-messages="errors.contact_name"
                />
            </FormField>
            <FormField label="Điện thoại / Zalo liên hệ" required>
                <v-text-field
                    v-model="form.contact_phone"
                    inputmode="tel"
                    :rules="[(v) => !!v?.trim() || 'Vui lòng nhập số điện thoại liên hệ']"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
                    :error-messages="errors.contact_phone"
                />
            </FormField>
            <FormField label="Email liên hệ" span="full">
                <v-text-field
                    :model-value="form.contact_email || 'Chưa cấu hình email công ty'"
                    readonly
                    prepend-inner-icon="mdi-lock-outline"
                    variant="outlined"
                    density="comfortable"
                    rounded="lg"
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
import { ANY_CONTRACT_TYPE, CONTRACT_TYPE_LABELS } from "../../composables/recruitmentStatus";
import { useRecruitmentPositions } from "../../composables/useRecruitmentPositions";
import { useAuthStore } from "../../stores/authStore";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    candidate: { type: Object, default: null },
    // Có giá trị = SỬA offer đang chờ duyệt (PUT /offers/{id}); null = soạn mới.
    offer: { type: Object, default: null },
    // Đợt tuyển — lấy phòng ban/chức vụ mặc định (đợt chung: theo vị trí ứng tuyển của ứng viên).
    opening: { type: Object, default: null },
    // [{ title, value }] — cây phòng ban đã làm phẳng.
    departmentOptions: { type: Array, default: () => [] },
    // Loại hợp đồng mặc định = loại của đợt tuyển ("tat_ca" -> HR tự chọn).
    defaultContractType: { type: String, default: "thu_viec" },
});

const emit = defineEmits(["update:modelValue", "saved"]);
const auth = useAuthStore();
const isEdit = computed(() => !!props.offer);

const form = ref({});
const errors = ref({});
const error = ref("");
const saving = ref(false);

const contractOptions = Object.entries(CONTRACT_TYPE_LABELS).map(([value, title]) => ({ title, value }));
const { positions, positionById } = useRecruitmentPositions();
const positionOptions = computed(() =>
    positions.value
        .filter((p) => p.department_id === form.value.department_id)
        .map((p) => ({ title: p.name, value: p.id })),
);

function onDepartmentChange() {
    if (form.value.position_id && positionById(form.value.position_id)?.department_id !== form.value.department_id) {
        form.value.position_id = null;
    }
}

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
        if (props.offer) {
            const o = props.offer;
            form.value = {
                department_id: o.department_id,
                position_id: o.position_id,
                contract_type: o.contract_type,
                salary: o.salary,
                start_date: o.start_date,
                response_deadline: o.response_deadline,
                message: o.message ?? "",
                contact_name: o.contact_name ?? "",
                contact_phone: o.contact_phone ?? "",
                contact_email: o.contact_email ?? "",
            };
            return;
        }
        const applied = positionById(props.candidate?.applied_position_id);
        form.value = {
            department_id: props.opening?.department_id ?? applied?.department_id ?? null,
            position_id: props.opening?.position_id ?? applied?.id ?? null,
            // Đợt "tất cả" -> theo hình thức ứng viên mong muốn (nếu có).
            contract_type:
                props.defaultContractType === ANY_CONTRACT_TYPE
                    ? (props.candidate?.desired_contract_type ?? null)
                    : props.defaultContractType,
            salary: null,
            start_date: shiftIsoDate(todayIso(), 7),
            response_deadline: shiftIsoDate(todayIso(), 3),
            message: "",
            // Người liên hệ = HR đang soạn (sửa được); email = email hệ thống công ty (cố định).
            contact_name: auth.user?.user_name ?? "",
            contact_phone: "",
            contact_email: "",
        };
        loadDefaults();
    },
);

async function loadDefaults() {
    try {
        const d = (await recruitmentService.offerDefaults()).data.data;
        form.value.contact_name = d.contact_name ?? form.value.contact_name;
        form.value.contact_phone = d.contact_phone ?? form.value.contact_phone;
        form.value.contact_email = d.contact_email ?? "";
    } catch {
        // giữ giá trị tạm; backend vẫn tự điền khi lưu
    }
}

async function submit() {
    if (deadlineError.value) return;
    saving.value = true;
    errors.value = {};
    error.value = "";
    try {
        const payload = {
            ...form.value,
            message: form.value.message || null,
            contact_email: undefined, // backend luôn dùng email công ty
        };
        if (isEdit.value) {
            await recruitmentService.updateOffer(props.offer.id, payload);
        } else {
            await recruitmentService.createOffer(props.candidate.id, payload);
        }
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
