<template>
    <div>
        <PageHeader
            title="Chấm công"
            subtitle="Chấm công vào/ra theo Wifi, GPS hoặc mã QR."
        >
            <template #actions>
                <v-btn
                    color="secondary"
                    variant="tonal"
                    prepend-icon="mdi-calendar-edit"
                    @click="openSupplementDialog"
                >
                    Xin bổ sung chấm công
                </v-btn>
            </template>
        </PageHeader>

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

        <!-- Chọn phương thức -->
        <v-sheet class="border rounded-lg mb-4 glass-panel" color="transparent">
            <v-tabs v-model="method">
                <v-tab value="wifi">Wifi</v-tab>
                <v-tab value="gps">GPS</v-tab>
                <v-tab value="qr">Mã QR</v-tab>
            </v-tabs>
        </v-sheet>

        <v-sheet class="border rounded-lg pa-5 mb-4 glass-panel" color="transparent">
            <v-window v-model="method">
                <v-window-item value="wifi">
                    <div class="text-body-2" style="opacity: 0.75">
                        Chấm công qua mạng Wifi công ty — hệ thống tự nhận diện
                        theo địa chỉ mạng, không cần nhập gì thêm. Bấm nút ở
                        ca tương ứng bên dưới khi đang kết nối đúng Wifi tại
                        nơi làm việc.
                    </div>
                </v-window-item>

                <v-window-item value="gps">
                    <div class="text-body-2" style="opacity: 0.75">
                        Chấm công theo vị trí hiện tại — trình duyệt sẽ hỏi
                        quyền truy cập vị trí khi bạn bấm nút ở ca tương ứng
                        bên dưới.
                    </div>
                </v-window-item>

                <v-window-item value="qr">
                    <div class="text-body-2 font-weight-medium mb-1">
                        Mã QR tại điểm chấm công
                    </div>
                    <v-text-field
                        v-model="qrReference"
                        placeholder="Quét hoặc dán nội dung mã QR"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                    />
                </v-window-item>
            </v-window>

            <v-alert
                v-if="submitError"
                type="error"
                variant="tonal"
                density="compact"
                class="mt-3"
            >
                {{ submitError }}
            </v-alert>
        </v-sheet>

        <!-- Trạng thái từng ca hôm nay — 1 nhân viên có thể có nhiều ca cùng
        ngày (mục 14), mỗi ca chấm công độc lập. -->
        <div v-if="loadingToday" class="d-flex justify-center py-6">
            <v-progress-circular indeterminate size="24" />
        </div>
        <v-sheet
            v-else-if="!todayShifts.length"
            class="border rounded-lg pa-5 mb-4 glass-panel text-center"
            color="transparent"
            style="opacity: 0.7"
        >
            Hôm nay bạn không có ca làm việc nào.
        </v-sheet>
        <v-row v-else dense class="mb-4">
            <v-col
                v-for="entry in todayShifts"
                :key="entry.work_shift.id"
                cols="12"
                md="6"
            >
                <v-sheet class="border rounded-lg pa-5 glass-panel h-100" color="transparent">
                    <div class="d-flex justify-space-between align-start mb-3">
                        <div>
                            <div class="text-subtitle-1 font-weight-bold">
                                {{ entry.work_shift.name }}
                            </div>
                            <div class="text-body-2" style="opacity: 0.7">
                                {{ entry.work_shift.start_time }} - {{ entry.work_shift.end_time }}
                            </div>
                        </div>
                        <StatusChip
                            v-if="entry.attendance"
                            :status="entry.attendance.status"
                            :map="ATTENDANCE_STATUS_MAP"
                        />
                    </div>

                    <div class="d-flex justify-space-between text-body-2 py-1">
                        <span style="opacity: 0.75">Check-in</span>
                        <span class="d-flex align-center font-weight-medium">
                            {{ formatTime(entry.attendance?.first_check_in_at) }}
                            <v-icon
                                v-if="entry.attendance?.first_check_in_at"
                                icon="mdi-check-circle"
                                color="success"
                                size="16"
                                class="ml-1"
                            />
                        </span>
                    </div>
                    <div class="d-flex justify-space-between text-body-2 py-1 mb-2">
                        <span style="opacity: 0.75">Check-out</span>
                        <span class="d-flex align-center font-weight-medium">
                            {{ formatTime(entry.attendance?.last_check_out_at) }}
                            <v-icon
                                v-if="entry.attendance?.last_check_out_at"
                                icon="mdi-check-circle"
                                color="success"
                                size="16"
                                class="ml-1"
                            />
                        </span>
                    </div>

                    <v-btn
                        v-if="!entry.attendance?.first_check_in_at"
                        color="primary"
                        variant="flat"
                        block
                        :loading="submittingShiftId === entry.work_shift.id"
                        :disabled="submitting"
                        @click="submit(entry.work_shift.id, false)"
                    >
                        Chấm công vào
                    </v-btn>
                    <v-btn
                        v-else-if="!entry.attendance?.last_check_out_at"
                        color="warning"
                        variant="flat"
                        block
                        :loading="submittingShiftId === entry.work_shift.id"
                        :disabled="submitting"
                        @click="submit(entry.work_shift.id, true)"
                    >
                        Chấm công ra
                    </v-btn>
                </v-sheet>
            </v-col>
        </v-row>

        <!-- Lịch sử gần đây -->
        <div class="d-flex justify-space-between align-center mb-3">
            <div class="text-subtitle-1 font-weight-bold">Lịch sử gần đây</div>
            <v-btn
                variant="text"
                color="primary"
                size="small"
                append-icon="mdi-arrow-right"
                :to="{ name: 'attendance-history' }"
            >
                Xem lịch sử đầy đủ
            </v-btn>
        </div>
        <v-sheet class="border rounded-lg glass-panel" color="transparent">
            <v-table density="comfortable">
                <thead>
                    <tr>
                        <th>Ngày</th>
                        <th>Ca</th>
                        <th>Giờ vào</th>
                        <th>Giờ ra</th>
                        <th>Trễ</th>
                        <th>Về sớm</th>
                        <th>OT</th>
                        <th>Trạng thái</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="loadingHistory">
                        <td colspan="9" class="text-center py-6">
                            <v-progress-circular indeterminate size="24" />
                        </td>
                    </tr>
                    <tr v-else-if="!history.length">
                        <td colspan="9" class="text-center py-6" style="opacity: 0.6">
                            Chưa có lịch sử chấm công.
                        </td>
                    </tr>
                    <tr v-for="a in history" v-else :key="a.id">
                        <td>{{ formatDate(a.attendance_date) }}</td>
                        <td>{{ a.work_shift?.name ?? "—" }}</td>
                        <td>{{ formatTime(a.first_check_in_at) }}</td>
                        <td>{{ formatTime(a.last_check_out_at) }}</td>
                        <td>
                            {{ a.late_minutes ? `${a.late_minutes} phút` : "—" }}
                            <span v-if="a.late_minutes && a.late_excused" class="text-success" style="opacity: 0.8">
                                (đã miễn trừ)
                            </span>
                        </td>
                        <td>{{ a.early_leave_minutes ? `${a.early_leave_minutes} phút` : "—" }}</td>
                        <td>{{ a.overtime_minutes ? `${a.overtime_minutes} phút` : "—" }}</td>
                        <td>
                            <StatusChip :status="a.status" :map="ATTENDANCE_STATUS_MAP" />
                        </td>
                        <td class="text-end">
                            <v-btn
                                icon="mdi-file-edit-outline"
                                variant="tonal"
                                size="small"
                                rounded="lg"
                                class="mr-1"
                                @click="openAdjustDialog(a)"
                            >
                                <v-icon icon="mdi-file-edit-outline" />
                                <v-tooltip activator="parent" location="top"
                                    >Xin điều chỉnh</v-tooltip
                                >
                            </v-btn>
                            <v-btn
                                v-if="a.late_minutes > 0 && !a.late_excused"
                                icon="mdi-shield-check-outline"
                                variant="tonal"
                                color="secondary"
                                size="small"
                                rounded="lg"
                                @click="openExcuseDialog(a)"
                            >
                                <v-icon icon="mdi-shield-check-outline" />
                                <v-tooltip activator="parent" location="top"
                                    >Xin miễn trừ đi muộn</v-tooltip
                                >
                            </v-btn>
                        </td>
                    </tr>
                </tbody>
            </v-table>
        </v-sheet>

        <!-- Xin điều chỉnh (correction — sửa 1 bản ghi ĐÃ CÓ) -->
        <v-dialog v-model="adjustDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Xin điều chỉnh công
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div class="text-body-2" style="opacity: 0.75">
                        Ngày công: <strong>{{ formatDate(adjustTarget?.attendance_date) }}</strong>.
                        Chỉ cần đề xuất giờ nào bị sai, để trống giờ còn lại
                        nếu không cần sửa.
                    </div>

                    <v-row dense>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Giờ vào đúng
                            </div>
                            <v-text-field
                                v-model="adjustForm.checkInTime"
                                type="time"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                            />
                        </v-col>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Giờ ra đúng
                            </div>
                            <v-text-field
                                v-model="adjustForm.checkOutTime"
                                type="time"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                            />
                        </v-col>
                    </v-row>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Lý do <span class="text-error">*</span>
                        </div>
                        <v-textarea
                            v-model="adjustForm.reason"
                            rows="3"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="adjustErrors.reason"
                        />
                    </div>

                    <v-alert
                        v-if="adjustGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ adjustGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="adjustSubmitting"
                        @click="closeAdjustDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="adjustSubmitting"
                        @click="submitAdjustRequest"
                    >
                        Gửi yêu cầu
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Xin bổ sung chấm công (supplement — quên chấm công hoàn toàn,
        chưa có bản ghi nào cho ca+ngày đó) -->
        <v-dialog v-model="supplementDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Xin bổ sung chấm công
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div class="text-body-2" style="opacity: 0.75">
                        Dùng khi bạn quên chấm công cả ngày (không có bản ghi
                        nào để xin điều chỉnh). Phải nhập đủ cả giờ vào lẫn
                        giờ ra.
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ngày <span class="text-error">*</span>
                        </div>
                        <InputDate
                            v-model="supplementForm.attendanceDate"
                            :max="todayIso()"
                            :error-messages="supplementErrors.attendance_date"
                        />
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Ca làm việc <span class="text-error">*</span>
                        </div>
                        <SearchSelect
                            v-model="supplementForm.workShiftId"
                            :items="shiftOptions"
                            placeholder="Chọn ca làm việc"
                            :error-messages="supplementErrors.work_shift_id"
                        />
                    </div>

                    <v-row dense>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Giờ vào <span class="text-error">*</span>
                            </div>
                            <v-text-field
                                v-model="supplementForm.checkInTime"
                                type="time"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                            />
                        </v-col>
                        <v-col cols="6">
                            <div class="text-body-2 font-weight-medium mb-1">
                                Giờ ra <span class="text-error">*</span>
                            </div>
                            <v-text-field
                                v-model="supplementForm.checkOutTime"
                                type="time"
                                variant="outlined"
                                density="comfortable"
                                rounded="lg"
                            />
                        </v-col>
                    </v-row>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Lý do <span class="text-error">*</span>
                        </div>
                        <v-textarea
                            v-model="supplementForm.reason"
                            rows="3"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="supplementErrors.reason"
                        />
                    </div>

                    <v-alert
                        v-if="supplementGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ supplementGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="supplementSubmitting"
                        @click="closeSupplementDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="supplementSubmitting"
                        @click="submitSupplementRequest"
                    >
                        Gửi yêu cầu
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Xin miễn trừ đi muộn (excuse — Ngày 42, KHÔNG sửa giờ, chỉ xin
        không tính vào thống kê đi muộn) -->
        <v-dialog v-model="excuseDialog" max-width="480" persistent>
            <v-card rounded="xl" elevation="12" class="glass-panel">
                <v-card-title class="text-h6 font-weight-bold pt-5 px-5">
                    Xin miễn trừ đi muộn
                </v-card-title>
                <v-card-text
                    class="px-5"
                    style="display: flex; flex-direction: column; gap: 0.75rem"
                >
                    <div class="text-body-2" style="opacity: 0.75">
                        Ngày công: <strong>{{ formatDate(excuseTarget?.attendance_date) }}</strong>,
                        trễ <strong>{{ excuseTarget?.late_minutes }} phút</strong>.
                        Dùng khi đi muộn có lý do chính đáng (kẹt xe, tai
                        nạn,...) — giờ vào vẫn giữ nguyên, chỉ không tính vào
                        thống kê đi muộn nếu được duyệt.
                    </div>

                    <div>
                        <div class="text-body-2 font-weight-medium mb-1">
                            Lý do <span class="text-error">*</span>
                        </div>
                        <v-textarea
                            v-model="excuseForm.reason"
                            rows="3"
                            variant="outlined"
                            density="comfortable"
                            rounded="lg"
                            :error-messages="excuseErrors.reason"
                        />
                    </div>

                    <v-alert
                        v-if="excuseGeneralError"
                        type="error"
                        variant="tonal"
                        density="compact"
                    >
                        {{ excuseGeneralError }}
                    </v-alert>
                </v-card-text>
                <v-card-actions class="px-5 pb-5">
                    <v-spacer />
                    <v-btn
                        variant="text"
                        :disabled="excuseSubmitting"
                        @click="closeExcuseDialog"
                    >
                        Hủy
                    </v-btn>
                    <v-btn
                        color="primary"
                        variant="flat"
                        :loading="excuseSubmitting"
                        @click="submitExcuseRequest"
                    >
                        Gửi yêu cầu
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
// Trang tự phục vụ — nhân viên chấm công vào/ra CHÍNH MÌNH, không nhận
// employee_id nào từ client (backend luôn tự resolve theo token đăng nhập,
// xem AttendanceController::checkIn()). 1 nhân viên có thể có nhiều ca cùng
// ngày (mục 14, vd Ca sáng + Ca chiều) — mỗi ca hiện 1 card riêng, chấm công
// độc lập, backend không còn tự đoán "ca gần nhất" mà nhận rõ work_shift_id
// từ nơi gọi. Không khớp được điểm chấm công (attendance_locations) nào vẫn
// cho chấm công thành công (không chặn cứng — quyết định nghiệp vụ đã chốt,
// xem CODE_MAP mục 12), chỉ đánh dấu status="needs_review" để HR xem lại sau.
import { onMounted, ref } from "vue";
import attendanceService from "../../services/attendanceService";
import employeeService from "../../services/employeeService";
import PageHeader from "../../components/common/PageHeader.vue";
import StatusChip from "../../components/common/StatusChip.vue";
import SearchSelect from "../../components/common/SearchSelect.vue";
import InputDate, { todayIso } from "../../components/common/InputDate.vue";
import { useToastStore } from "../../stores/useToastStore";

const toast = useToastStore();

const ATTENDANCE_STATUS_MAP = {
    pending: { label: "Đang trong ca", color: "info" },
    completed: { label: "Hoàn tất", color: "success" },
    needs_review: { label: "Cần xem lại", color: "warning" },
};

function formatDate(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

function formatTime(value) {
    if (!value) {
        return "--:--";
    }
    return new Date(value).toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" });
}

const todayShifts = ref([]);
const loadingToday = ref(true);
const loadError = ref("");

async function loadToday() {
    loadingToday.value = true;
    try {
        const response = await attendanceService.today();
        todayShifts.value = response.data.data;
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không thể tải trạng thái chấm công.";
    } finally {
        loadingToday.value = false;
    }
}

const history = ref([]);
const loadingHistory = ref(true);

async function loadHistory() {
    loadingHistory.value = true;
    try {
        const response = await attendanceService.myHistory();
        history.value = response.data;
    } catch (e) {
        loadError.value = e.response?.data?.message ?? "Không thể tải lịch sử chấm công.";
    } finally {
        loadingHistory.value = false;
    }
}

const method = ref("wifi");
const qrReference = ref("");
const submitting = ref(false);
const submittingShiftId = ref(null);
const submitError = ref("");

// Bọc navigator.geolocation (API kiểu callback) thành Promise để dùng được
// với async/await, cùng cách gọi như phần còn lại của submit().
function getGpsPosition() {
    return new Promise((resolve, reject) => {
        if (!navigator.geolocation) {
            reject(new Error("Trình duyệt không hỗ trợ định vị GPS."));
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (position) => resolve(position.coords),
            () => reject(new Error("Không lấy được vị trí GPS — kiểm tra lại quyền truy cập vị trí của trình duyệt.")),
            { enableHighAccuracy: true, timeout: 10000 },
        );
    });
}

async function submit(workShiftId, isCheckOut) {
    if (submitting.value) {
        return;
    }
    submitError.value = "";
    submitting.value = true;
    submittingShiftId.value = workShiftId;
    try {
        const payload = { method: method.value, work_shift_id: workShiftId };

        if (method.value === "gps") {
            try {
                const coords = await getGpsPosition();
                payload.latitude = coords.latitude;
                payload.longitude = coords.longitude;
                payload.accuracy_meters = coords.accuracy;
            } catch (e) {
                submitError.value = e.message;
                return;
            }
        }
        if (method.value === "qr") {
            payload.qr_reference = qrReference.value;
        }

        if (isCheckOut) {
            await attendanceService.checkOut(payload);
            toast.success("Đã chấm công ra.");
        } else {
            await attendanceService.checkIn(payload);
            toast.success("Đã chấm công vào.");
        }
        qrReference.value = "";
        await Promise.all([loadToday(), loadHistory()]);
    } catch (e) {
        submitError.value =
            e.response?.data?.errors?.work_shift_id?.[0] ??
            e.response?.data?.errors?.method?.[0] ??
            e.response?.data?.errors?.qr_reference?.[0] ??
            e.response?.data?.message ??
            "Không thể chấm công, vui lòng thử lại.";
    } finally {
        submitting.value = false;
        submittingShiftId.value = null;
    }
}

/* -------------------------- Xin điều chỉnh công -------------------------- */

const adjustDialog = ref(false);
const adjustTarget = ref(null);
const adjustForm = ref({ checkInTime: "", checkOutTime: "", reason: "" });
const adjustErrors = ref({});
const adjustGeneralError = ref("");
const adjustSubmitting = ref(false);

function openAdjustDialog(attendance) {
    adjustTarget.value = attendance;
    adjustForm.value = { checkInTime: "", checkOutTime: "", reason: "" };
    adjustErrors.value = {};
    adjustGeneralError.value = "";
    adjustDialog.value = true;
}

function closeAdjustDialog() {
    adjustDialog.value = false;
}

// Ghép ngày công với giờ người dùng nhập (input type="time", chỉ có HH:mm)
// thành chuỗi datetime đầy đủ mà backend cần — tránh phải dựng thêm 1
// component chọn ngày-giờ đầy đủ chỉ cho 2 chỗ dùng (điều chỉnh + bổ sung).
function combineDateAndTime(dateStr, timeStr) {
    if (!timeStr) {
        return null;
    }
    return `${dateStr} ${timeStr}:00`;
}

async function submitAdjustRequest() {
    adjustErrors.value = {};
    adjustGeneralError.value = "";

    if (!adjustForm.value.checkInTime && !adjustForm.value.checkOutTime) {
        adjustGeneralError.value = "Vui lòng đề xuất ít nhất giờ vào hoặc giờ ra.";
        return;
    }

    adjustSubmitting.value = true;
    try {
        const date = adjustTarget.value.attendance_date;
        await attendanceService.requestAdjustment({
            type: "correction",
            attendance_id: adjustTarget.value.id,
            proposed_check_in_at: combineDateAndTime(date, adjustForm.value.checkInTime),
            proposed_check_out_at: combineDateAndTime(date, adjustForm.value.checkOutTime),
            reason: adjustForm.value.reason,
        });
        toast.success("Đã gửi yêu cầu điều chỉnh công, chờ duyệt.");
        closeAdjustDialog();
    } catch (e) {
        const status = e.response?.status;
        const data = e.response?.data;
        if (status === 422 && data?.errors) {
            adjustErrors.value = { reason: data.errors.reason?.[0] };
            adjustGeneralError.value =
                data.errors.attendance_id?.[0] ??
                data.errors.proposed_check_in_at?.[0] ??
                data.errors.proposed_check_out_at?.[0] ??
                "";
        } else {
            adjustGeneralError.value = data?.message ?? "Không thể gửi yêu cầu, vui lòng thử lại.";
        }
    } finally {
        adjustSubmitting.value = false;
    }
}

/* ------------------------- Xin bổ sung chấm công ------------------------- */

const supplementDialog = ref(false);
const supplementForm = ref({ attendanceDate: "", workShiftId: null, checkInTime: "", checkOutTime: "", reason: "" });
const supplementErrors = ref({});
const supplementGeneralError = ref("");
const supplementSubmitting = ref(false);
const shiftOptions = ref([]);

async function loadShiftOptions() {
    try {
        const response = await employeeService.myShiftAssignments();
        const seen = new Set();
        shiftOptions.value = response.data
            .filter((assignment) => {
                if (seen.has(assignment.work_shift_id)) {
                    return false;
                }
                seen.add(assignment.work_shift_id);
                return true;
            })
            .map((assignment) => ({ title: assignment.work_shift.name, value: assignment.work_shift_id }));
    } catch {
        shiftOptions.value = [];
    }
}

function openSupplementDialog() {
    supplementForm.value = { attendanceDate: "", workShiftId: null, checkInTime: "", checkOutTime: "", reason: "" };
    supplementErrors.value = {};
    supplementGeneralError.value = "";
    supplementDialog.value = true;
    loadShiftOptions();
}

function closeSupplementDialog() {
    supplementDialog.value = false;
}

async function submitSupplementRequest() {
    supplementErrors.value = {};
    supplementGeneralError.value = "";

    if (
        !supplementForm.value.attendanceDate ||
        !supplementForm.value.workShiftId ||
        !supplementForm.value.checkInTime ||
        !supplementForm.value.checkOutTime
    ) {
        supplementGeneralError.value = "Vui lòng nhập đủ ngày, ca làm việc, giờ vào và giờ ra.";
        return;
    }

    supplementSubmitting.value = true;
    try {
        const date = supplementForm.value.attendanceDate;
        await attendanceService.requestAdjustment({
            type: "supplement",
            work_shift_id: supplementForm.value.workShiftId,
            attendance_date: date,
            proposed_check_in_at: combineDateAndTime(date, supplementForm.value.checkInTime),
            proposed_check_out_at: combineDateAndTime(date, supplementForm.value.checkOutTime),
            reason: supplementForm.value.reason,
        });
        toast.success("Đã gửi yêu cầu bổ sung chấm công, chờ duyệt.");
        closeSupplementDialog();
    } catch (e) {
        const status = e.response?.status;
        const data = e.response?.data;
        if (status === 422 && data?.errors) {
            supplementErrors.value = {
                attendance_date: data.errors.attendance_date?.[0],
                work_shift_id: data.errors.work_shift_id?.[0],
                reason: data.errors.reason?.[0],
            };
            supplementGeneralError.value =
                data.errors.proposed_check_in_at?.[0] ?? data.errors.proposed_check_out_at?.[0] ?? "";
        } else {
            supplementGeneralError.value = data?.message ?? "Không thể gửi yêu cầu, vui lòng thử lại.";
        }
    } finally {
        supplementSubmitting.value = false;
    }
}

/* --------------------- Xin miễn trừ đi muộn (excuse) --------------------- */

const excuseDialog = ref(false);
const excuseTarget = ref(null);
const excuseForm = ref({ reason: "" });
const excuseErrors = ref({});
const excuseGeneralError = ref("");
const excuseSubmitting = ref(false);

function openExcuseDialog(attendance) {
    excuseTarget.value = attendance;
    excuseForm.value = { reason: "" };
    excuseErrors.value = {};
    excuseGeneralError.value = "";
    excuseDialog.value = true;
}

function closeExcuseDialog() {
    excuseDialog.value = false;
}

async function submitExcuseRequest() {
    excuseErrors.value = {};
    excuseGeneralError.value = "";

    excuseSubmitting.value = true;
    try {
        await attendanceService.requestAdjustment({
            type: "excuse",
            attendance_id: excuseTarget.value.id,
            reason: excuseForm.value.reason,
        });
        toast.success("Đã gửi yêu cầu miễn trừ đi muộn, chờ duyệt.");
        closeExcuseDialog();
    } catch (e) {
        const status = e.response?.status;
        const data = e.response?.data;
        if (status === 422 && data?.errors) {
            excuseErrors.value = { reason: data.errors.reason?.[0] };
            excuseGeneralError.value = data.errors.attendance_id?.[0] ?? "";
        } else {
            excuseGeneralError.value = data?.message ?? "Không thể gửi yêu cầu, vui lòng thử lại.";
        }
    } finally {
        excuseSubmitting.value = false;
    }
}

onMounted(() => {
    loadToday();
    loadHistory();
});
</script>
