// Ngày 44: toàn bộ state/logic của trang Chấm công tự phục vụ được gom vào
// đây (trước đó nằm thẳng trong CheckIn.vue) — để 2 giao diện hiển thị khác
// nhau, CheckInDesktop.vue (bảng "Lịch sử gần đây") và CheckInMobile.vue
// (danh sách thẻ, phù hợp màn hình hẹp), CÙNG GỌI 1 hàm này thay vì mỗi bên
// tự viết lại toàn bộ gọi API/validate — tránh rủi ro lệch logic giữa 2 nơi
// khi sau này sửa 1 tính năng (đã bàn với người dùng trước khi làm).
import { onMounted, ref } from "vue";
import attendanceService from "../services/attendanceService";
import employeeService from "../services/employeeService";
import { todayIso } from "../components/common/InputDate.vue";
import { useToastStore } from "../stores/useToastStore";

export const ATTENDANCE_STATUS_MAP = {
    pending: { label: "Đang trong ca", color: "info" },
    completed: { label: "Hoàn tất", color: "success" },
    needs_review: { label: "Cần xem lại", color: "warning" },
};

export function formatDate(value) {
    if (!value) {
        return "—";
    }
    return new Date(value).toLocaleDateString("vi-VN");
}

export function formatTime(value) {
    if (!value) {
        return "--:--";
    }
    return new Date(value).toLocaleTimeString("vi-VN", { hour: "2-digit", minute: "2-digit" });
}

// Quy đổi phút sang giờ để dễ đối chiếu khi tính lương sau này (Phase 4) —
// CHỈ đổi cách HIỂN THỊ, không đổi gì ở cách lưu/tính phía backend (vẫn
// nguyên phút, cộng dồn chính xác rồi mới quy đổi 1 lần lúc tính lương,
// tránh sai số làm tròn nếu quy đổi từng ngày rồi cộng lại). Làm tròn 1 chữ
// số thập phân, cùng công thức đã dùng cho "Tổng giờ công" ở AttendanceHistoryPanel.vue.
export function formatMinutesAsHours(minutes) {
    if (!minutes) {
        return "—";
    }
    const hours = Math.round((minutes / 60) * 10) / 10;
    return `${hours}h (${minutes} phút)`;
}

export function useCheckIn() {
    const toast = useToastStore();

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

    return {
        todayIso,
        todayShifts,
        loadingToday,
        loadError,
        history,
        loadingHistory,
        method,
        qrReference,
        submitting,
        submittingShiftId,
        submitError,
        submit,
        adjustDialog,
        adjustTarget,
        adjustForm,
        adjustErrors,
        adjustGeneralError,
        adjustSubmitting,
        openAdjustDialog,
        closeAdjustDialog,
        submitAdjustRequest,
        supplementDialog,
        supplementForm,
        supplementErrors,
        supplementGeneralError,
        supplementSubmitting,
        shiftOptions,
        openSupplementDialog,
        closeSupplementDialog,
        submitSupplementRequest,
        excuseDialog,
        excuseTarget,
        excuseForm,
        excuseErrors,
        excuseGeneralError,
        excuseSubmitting,
        openExcuseDialog,
        closeExcuseDialog,
        submitExcuseRequest,
    };
}
