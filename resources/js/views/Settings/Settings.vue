<template>
    <div>
        <PageHeader
            title="Cài đặt hệ thống"
            subtitle="Giờ làm việc mặc định áp dụng cho toàn bộ nhân viên."
        />

        <v-alert
            v-if="loadError"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-4"
            icon="mdi-alert-circle-outline"
        >
            {{ loadError }}
        </v-alert>

        <v-sheet
            v-if="loading"
            class="border rounded-lg pa-5 glass-panel d-flex justify-center"
            color="transparent"
        >
            <v-progress-circular indeterminate size="24" />
        </v-sheet>

        <v-sheet v-else class="border rounded-lg pa-5 glass-panel" color="transparent">
            <FormSection title="Giờ làm việc mặc định">
                <p class="text-body-2 mb-4" style="opacity: 0.75">
                    Mọi nhân viên (kể cả người đã có sẵn) tự động làm việc
                    theo đúng giờ giấc và ngày trong tuần cấu hình ở đây, tự
                    động cập nhật ngay khi lưu — không cần HR vào "Gán ca làm
                    việc" cho từng người nữa. Nhân viên muốn làm thêm/làm bù
                    ngoài các ngày này thì gửi "Xin làm ngoài lịch/OT". Cần
                    khung giờ RIÊNG cho một số nhân viên (ca đêm, part-time…)?
                    Vào trang
                    <RouterLink to="/work-shifts">Ca làm việc</RouterLink>
                    để tạo ca khác và gán riêng cho từng người.
                </p>

                <v-row dense>
                    <v-col cols="12" sm="6">
                        <div class="text-body-2 font-weight-medium mb-1">
                            Giờ vào <span class="text-error">*</span>
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
                            Giờ ra <span class="text-error">*</span>
                        </div>
                        <v-text-field
                            v-model="form.end_time"
                            type="time"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="errors.end_time"
                        />
                    </v-col>
                </v-row>

                <div class="mb-4">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Ngày làm việc trong tuần <span class="text-error">*</span>
                    </div>
                    <v-btn-toggle
                        v-model="form.work_days"
                        multiple
                        density="comfortable"
                        variant="outlined"
                        divided
                    >
                        <v-btn
                            v-for="day in WEEK_DAYS"
                            :key="day.value"
                            :value="day.value"
                            size="small"
                        >
                            {{ day.label }}
                        </v-btn>
                    </v-btn-toggle>
                    <div v-if="errors.work_days" class="text-error text-caption mt-1">
                        {{ Array.isArray(errors.work_days) ? errors.work_days[0] : errors.work_days }}
                    </div>
                    <!-- Áp dụng cho MỌI nhân viên đang theo ca mặc định NGAY
                    khi lưu — kể cả người đã được gán từ trước (xem
                    WorkShiftService::update() ->
                    resyncOpenEndedWorkDays()); nhân viên muốn làm thêm/làm bù
                    ngoài các ngày này thì dùng "Xin làm ngoài lịch/OT". -->
                </div>

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
                        <!-- Để trống cả 2 ô = không có nghỉ trưa. Khoảng này
                        được TRỪ THẬT vào giờ công thực tế nếu nhân viên chấm
                        công trùng giờ nghỉ trưa (xem
                        AttendanceService::calculateActualWorkMinutes()). -->
                    </v-col>
                </v-row>

                <div class="text-body-2" style="opacity: 0.7">
                    Số giờ công chuẩn/ngày: <strong>{{ standardHoursLabel }}</strong>
                    (tự tính = giờ ra − giờ vào − giờ nghỉ trưa)
                </div>

                <v-alert
                    v-if="saveError"
                    type="error"
                    variant="tonal"
                    density="compact"
                    class="mt-4"
                >
                    {{ saveError }}
                </v-alert>

                <div class="d-flex justify-end mt-5">
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="saving"
                        :disabled="!canManage"
                        @click="save"
                    >
                        Lưu thay đổi
                    </v-btn>
                </div>
            </FormSection>
        </v-sheet>
    </div>
</template>

<script setup>
// Trang "Cài đặt hệ thống" (2026-09-23, theo yêu cầu người dùng) — CHỈ có 1
// mục "Giờ làm việc mặc định" (giờ vào-ra, nghỉ trưa từ-đến, và từ 2026-09-24
// thêm "ngày làm việc trong tuần" — theo yêu cầu người dùng: "bất kỳ nhân
// viên nào cũng tự động set giờ làm việc mặc định... chứ không có quản lý ca
// làm gán ca làm nữa"). Không phải trang riêng ở Backend — "Ca mặc định" vẫn
// là 1 dòng WorkShift bình thường (is_default=true, xem WorkShiftService),
// trang này chỉ là màn hình rút gọn CHỈ sửa 5 field người dùng quan tâm, tái
// dùng nguyên API GET/POST/PUT /work-shifts đã có — các field còn lại
// (standard_work_minutes/late_grace_minutes/early_leave_grace_minutes/
// work_coefficient) muốn chỉnh chi tiết vẫn vào trang "Ca làm việc" (mục
// 12) sửa như 1 ca bình thường. Đổi work_days ở đây propagate NGAY cho mọi
// nhân viên đang theo ca mặc định (kể cả người đã gán từ trước) — xem
// WorkShiftService::update() -> EmployeeShiftAssignmentService::resyncOpenEndedWorkDays().
import { computed, onMounted, onUnmounted, reactive, ref, watch } from "vue";
import workShiftService from "../../services/workShiftService";
import { useAuthStore } from "../../stores/authStore";
import { useResourceSyncStore } from "../../stores/useResourceSyncStore";
import PageHeader from "../../components/common/PageHeader.vue";
import FormSection from "../../components/common/FormSection.vue";
import { useToastStore } from "../../stores/useToastStore";

const auth = useAuthStore();
const toast = useToastStore();
const resourceSync = useResourceSyncStore();
const canManage = computed(() => auth.permissions.includes("shift.manage"));

const loading = ref(true);
const loadError = ref("");
const saving = ref(false);
const saveError = ref("");
const errors = ref({});

// null = CHƯA có ca mặc định nào (lần đầu thiết lập) — currentShift giữ lại
// toàn bộ bản ghi hiện có (kể cả các field không hiện ở form này) để lúc lưu
// gộp vào, không làm mất standard_work_minutes/late_grace_minutes đã có.
const currentShift = ref(null);

const form = reactive({
    start_time: "",
    end_time: "",
    break_start_time: "",
    break_end_time: "",
    work_days: [],
});

// Cùng danh sách/nhãn với tab "Gán ca làm việc"
// (EmployeeShiftAssignmentsTab.vue) — 2 nơi hiển thị cùng khái niệm "ngày
// trong tuần" (1=T2...7=CN, theo Carbon::dayOfWeekIso) nên giữ đúng thứ tự,
// nhãn để không lệch nhau.
const WEEK_DAYS = [
    { value: 1, label: "T2" },
    { value: 2, label: "T3" },
    { value: 3, label: "T4" },
    { value: 4, label: "T5" },
    { value: 5, label: "T6" },
    { value: 6, label: "T7" },
    { value: 7, label: "CN" },
];

// "HH:mm" -> số phút trong ngày, để tính số giờ công chuẩn hiển thị tham khảo.
function toMinutes(value) {
    if (!value) {
        return null;
    }
    const [h, m] = value.split(":").map(Number);
    return h * 60 + m;
}

// null nếu chưa đủ dữ liệu hợp lệ để tính (giờ ra phải sau giờ vào).
function computeStandardMinutes() {
    const start = toMinutes(form.start_time);
    const end = toMinutes(form.end_time);
    if (start === null || end === null || end <= start) {
        return null;
    }
    const breakStart = toMinutes(form.break_start_time);
    const breakEnd = toMinutes(form.break_end_time);
    const breakMinutes = breakStart !== null && breakEnd !== null && breakEnd > breakStart ? breakEnd - breakStart : 0;
    return Math.max(0, end - start - breakMinutes);
}

const standardHoursLabel = computed(() => {
    const minutes = computeStandardMinutes();
    return minutes === null ? "—" : `${(minutes / 60).toFixed(1)} giờ (${minutes} phút)`;
});

async function loadData() {
    loading.value = true;
    loadError.value = "";
    try {
        const response = await workShiftService.getDefault();
        currentShift.value = response.data.data;
        form.start_time = currentShift.value?.start_time?.slice(0, 5) ?? "08:00";
        form.end_time = currentShift.value?.end_time?.slice(0, 5) ?? "17:00";
        form.break_start_time = currentShift.value?.break_start_time?.slice(0, 5) ?? "12:00";
        form.break_end_time = currentShift.value?.break_end_time?.slice(0, 5) ?? "13:00";
        // NULL (chưa từng cấu hình, hoặc ca tạo trước khi có tính năng này)
        // -> hiện T2-T6 làm gợi ý ban đầu, khớp phương án dự phòng ở
        // EmployeeShiftAssignmentService::DEFAULT_WORK_DAYS.
        form.work_days = currentShift.value?.work_days?.length
            ? [...currentShift.value.work_days]
            : [1, 2, 3, 4, 5];
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không thể tải cấu hình giờ làm việc.";
    } finally {
        loading.value = false;
    }
}

async function save() {
    errors.value = {};
    saveError.value = "";
    saving.value = true;
    try {
        // Ô trống ("") phải gửi thành null — không đúng định dạng H:i.
        const timePayload = {
            start_time: form.start_time,
            end_time: form.end_time,
            break_start_time: form.break_start_time || null,
            break_end_time: form.break_end_time || null,
            work_days: form.work_days,
        };
        // Sửa ca mặc định ĐÃ có: giữ nguyên các field khác không hiện ở đây
        // (tên, châm chước, hệ số công) — chỉ ghi đè 4 field giờ giấc VÀ số
        // phút công chuẩn (luôn tính lại theo đúng công thức đang hiển thị ở
        // "standardHoursLabel" ngay phía trên nút Lưu, để không lệch giữa
        // cái người dùng THẤY và cái thật sự được lưu). Tạo mới lần đầu:
        // dùng giá trị mặc định hợp lý cho các field còn lại (HR chỉnh chi
        // tiết sau ở trang "Ca làm việc" nếu cần).
        const standardWorkMinutes = Math.max(1, computeStandardMinutes() ?? 1);
        const payload = currentShift.value
            ? { ...currentShift.value, ...timePayload, standard_work_minutes: standardWorkMinutes, is_default: true }
            : {
                  name: "Ca mặc định",
                  ...timePayload,
                  standard_work_minutes: standardWorkMinutes,
                  late_grace_minutes: 5,
                  early_leave_grace_minutes: 5,
                  work_coefficient: 1,
                  is_active: true,
                  is_default: true,
              };

        const response = currentShift.value
            ? await workShiftService.update(currentShift.value.id, payload)
            : await workShiftService.create(payload);

        currentShift.value = response.data;
        toast.success("Đã lưu giờ làm việc mặc định.");
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors;
            saveError.value = "Vui lòng kiểm tra lại thông tin đã nhập.";
        } else {
            saveError.value = e.response?.data?.message ?? "Không thể lưu, vui lòng thử lại.";
        }
    } finally {
        saving.value = false;
    }
}

// Phiên KHÁC vừa sửa Ca mặc định (kể cả sửa ở trang "Ca làm việc" —
// WorkShifts.vue dùng chung resource 'work_shifts') — xem ResourceChanged
// (mục 34 CODE_MAP).
watch(() => resourceSync.signals.work_shifts, loadData);

onMounted(() => {
    loadData();
    resourceSync.connect("work_shifts");
});

onUnmounted(() => {
    resourceSync.disconnect("work_shifts");
});
</script>
