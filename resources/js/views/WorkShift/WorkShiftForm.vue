<template>
    <FormDialog
        :model-value="modelValue"
        eyebrow="HỒ SƠ CA LÀM VIỆC"
        :title="isEdit ? 'Sửa ca làm việc' : 'Thêm ca làm việc'"
        :subtitle="
            isEdit
                ? 'Cập nhật thông tin ca làm việc.'
                : 'Tạo ca làm việc mới.'
        "
        :error="loadError"
        :loading="loading"
        :submit-label="isEdit ? 'Lưu thay đổi' : 'Thêm mới'"
        @update:model-value="close"
        @submit="submit"
    >
        <FormSection title="Thông tin cơ bản">
            <v-row dense>
                <v-col cols="12" sm="7">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Tên ca làm việc <span class="text-error">*</span>
                    </div>
                    <v-text-field
                        v-model="form.name"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        placeholder="Nhập tên ca làm việc"
                        :error-messages="errors.name"
                    />
                </v-col>

                <v-col cols="12" sm="5">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Mã ca
                    </div>
                    <v-text-field
                        :model-value="isEdit ? workShift.code : 'Tự động sau khi lưu'"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        readonly
                        prepend-inner-icon="mdi-auto-fix"
                        :class="{ 'text-medium-emphasis': !isEdit }"
                    />
                </v-col>
            </v-row>

            <v-row dense>
                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Giờ bắt đầu <span class="text-error">*</span>
                    </div>
                    <v-text-field
                        v-model="form.start_time"
                        type="time"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.start_time"
                    />
                </v-col>
                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Giờ kết thúc <span class="text-error">*</span>
                    </div>
                    <v-text-field
                        v-model="form.end_time"
                        type="time"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.end_time"
                    />
                    <!-- Chưa chặn giờ kết thúc phải sau giờ bắt đầu — dự án
                         chưa có ca đêm (qua nửa đêm), tính sau khi có nhu cầu
                         thật (xem StoreWorkShiftRequest.php). -->
                </v-col>
            </v-row>

            <v-row dense>
                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Nghỉ trưa từ
                    </div>
                    <v-text-field
                        v-model="form.break_start_time"
                        type="time"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.break_start_time"
                    />
                </v-col>
                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        đến
                    </div>
                    <v-text-field
                        v-model="form.break_end_time"
                        type="time"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.break_end_time"
                    />
                    <!-- Để trống cả 2 ô = ca này không có nghỉ trưa (ca ngắn/
                    part-time) — có nghỉ trưa thì phải điền đủ cả 2, xem
                    StoreWorkShiftRequest.php. Khoảng này được TRỪ THẬT vào
                    giờ công thực tế nếu nhân viên chấm công trùng giờ nghỉ
                    trưa (xem AttendanceService::calculateActualWorkMinutes()). -->
                </v-col>
            </v-row>

            <v-row dense>
                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Số phút công chuẩn <span class="text-error">*</span>
                    </div>
                    <v-text-field
                        v-model.number="form.standard_work_minutes"
                        type="number"
                        min="1"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.standard_work_minutes"
                    />
                </v-col>
            </v-row>

            <v-row dense>
                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Châm chước đi muộn (phút)
                    </div>
                    <v-text-field
                        v-model.number="form.late_grace_minutes"
                        type="number"
                        min="0"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.late_grace_minutes"
                    />
                </v-col>
                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Châm chước về sớm (phút)
                    </div>
                    <v-text-field
                        v-model.number="form.early_leave_grace_minutes"
                        type="number"
                        min="0"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.early_leave_grace_minutes"
                    />
                </v-col>
            </v-row>

            <v-row dense>
                <v-col cols="12" sm="6">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Hệ số công
                    </div>
                    <v-text-field
                        v-model.number="form.work_coefficient"
                        type="number"
                        min="0"
                        step="0.1"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        :error-messages="errors.work_coefficient"
                    />
                </v-col>
            </v-row>

            <div class="d-flex align-center justify-space-between">
                <div>
                    <div class="text-body-2 font-weight-medium">
                        Trạng thái hoạt động
                    </div>
                    <div class="text-caption" style="opacity: 0.65">
                        Ca ngừng hoạt động vẫn được lưu nhưng không dùng để
                        phân công mới.
                    </div>
                </div>
                <v-switch
                    v-model="form.is_active"
                    color="success"
                    density="compact"
                    hide-details
                    inset
                    class="flex-grow-0 ms-4"
                    :disabled="isCurrentDefault"
                />
            </div>

            <div class="d-flex align-center justify-space-between mt-4">
                <div>
                    <div class="text-body-2 font-weight-medium">
                        Ca mặc định
                    </div>
                    <div class="text-caption" style="opacity: 0.65">
                        Nhân viên mới tạo tự động được gán ca này. Chỉ 1 ca
                        được là mặc định — bật ở đây sẽ tự tắt ở ca đang mặc
                        định trước đó.
                    </div>
                </div>
                <v-switch
                    v-model="form.is_default"
                    color="primary"
                    density="compact"
                    hide-details
                    inset
                    class="flex-grow-0 ms-4"
                />
            </div>
        </FormSection>

        <template #footer-note>
            <span class="text-error">*</span> Thông tin bắt buộc
        </template>
    </FormDialog>
</template>

<script setup>
import { computed, reactive, ref, watch } from "vue";
import workShiftService from "../../services/workShiftService";
import FormDialog from "../../components/common/FormDialog.vue";
import FormSection from "../../components/common/FormSection.vue";
import { useToastStore } from "../../stores/useToastStore";

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
    // null = thêm mới, object = sửa ca làm việc đang chọn
    workShift: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(["update:modelValue", "saved"]);

const toast = useToastStore();

const isEdit = computed(() => props.workShift !== null);

// Không dùng Pinia store cho Ca làm việc (chỉ 1 trang dùng, giống Position ở
// CODE_MAP mục 7) — tự giữ loading/errors cục bộ trong chính form.
const loading = ref(false);
const errors = ref({});
const loadError = ref("");

const form = reactive({
    name: "",
    start_time: "",
    end_time: "",
    break_start_time: "",
    break_end_time: "",
    standard_work_minutes: null,
    late_grace_minutes: 0,
    early_leave_grace_minutes: 0,
    work_coefficient: 1,
    is_active: true,
    is_default: false,
});

// Ca mặc định thì không cho tự tắt "Trạng thái hoạt động" ngay trên switch —
// Backend cũng chặn (WorkShiftService::update()), khóa ở đây để người dùng
// không bấm xong mới thấy lỗi.
const isCurrentDefault = computed(() => Boolean(props.workShift?.is_default));

function fillForm() {
    form.name = props.workShift?.name ?? "";
    form.start_time = props.workShift?.start_time?.slice(0, 5) ?? "";
    form.end_time = props.workShift?.end_time?.slice(0, 5) ?? "";
    form.break_start_time = props.workShift?.break_start_time?.slice(0, 5) ?? "";
    form.break_end_time = props.workShift?.break_end_time?.slice(0, 5) ?? "";
    form.standard_work_minutes = props.workShift?.standard_work_minutes ?? null;
    form.late_grace_minutes = props.workShift?.late_grace_minutes ?? 0;
    form.early_leave_grace_minutes =
        props.workShift?.early_leave_grace_minutes ?? 0;
    form.work_coefficient = props.workShift?.work_coefficient ?? 1;
    // DB trả về 1/0, ép về boolean cho v-switch
    form.is_active = Boolean(props.workShift?.is_active ?? true);
    form.is_default = Boolean(props.workShift?.is_default ?? false);
}

// Mỗi lần mở modal: nạp lại dữ liệu và xóa lỗi của lần mở trước
watch(
    () => props.modelValue,
    (isOpen) => {
        if (isOpen) {
            errors.value = {};
            loadError.value = "";
            fillForm();
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
        // Ô trống ("") phải gửi thành null — "" không đúng định dạng H:i,
        // Backend sẽ báo lỗi 422 dù ý người dùng là "không có nghỉ trưa".
        const payload = {
            ...form,
            break_start_time: form.break_start_time || null,
            break_end_time: form.break_end_time || null,
        };
        const response = isEdit.value
            ? await workShiftService.update(props.workShift.id, payload)
            : await workShiftService.create(payload);
        toast.success(
            isEdit.value ? "Đã cập nhật ca làm việc." : "Đã thêm ca làm việc mới.",
        );
        emit("saved", response.data);
        close();
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors;
        } else {
            loadError.value =
                e.response?.data?.message ??
                "Không thể kết nối máy chủ, vui lòng thử lại.";
        }
        // Giữ modal mở để người dùng sửa lại dữ liệu.
    } finally {
        loading.value = false;
    }
}
</script>
