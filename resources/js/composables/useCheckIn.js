// Ngày 44: toàn bộ state/logic của trang Chấm công tự phục vụ được gom vào
// đây (trước đó nằm thẳng trong CheckIn.vue) — để 2 giao diện hiển thị khác
// nhau, CheckInDesktop.vue (bảng "Lịch sử gần đây") và CheckInMobile.vue
// (danh sách thẻ, phù hợp màn hình hẹp), CÙNG GỌI 1 hàm này thay vì mỗi bên
// tự viết lại toàn bộ gọi API/validate — tránh rủi ro lệch logic giữa 2 nơi
// khi sau này sửa 1 tính năng (đã bàn với người dùng trước khi làm).
import { computed, onMounted, ref } from "vue";
import attendanceService from "../services/attendanceService";
import employeeService from "../services/employeeService";
import workShiftService from "../services/workShiftService";
import { todayIso } from "../components/common/InputDate.vue";
import { useToastStore } from "../stores/useToastStore";

export const ATTENDANCE_STATUS_MAP = {
    pending: { label: "Đang trong ca", color: "info" },
    completed: { label: "Hoàn tất", color: "success" },
    needs_review: { label: "Cần xem lại", color: "warning" },
};

// Bước HR duyệt chấm công (2026-09-21) — TÁCH khỏi ATTENDANCE_STATUS_MAP ở
// trên (trạng thái ca làm việc). Chưa duyệt / bị từ chối thì không được tính
// công/lương; dùng chung cho màn của nhân viên, lịch sử và màn "Duyệt chấm công".
export const APPROVAL_STATUS_MAP = {
    pending: { label: "Chờ duyệt", color: "warning" },
    approved: { label: "Đã duyệt", color: "success" },
    rejected: { label: "Bị từ chối", color: "error" },
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

    // Không còn 3 tab Wifi/GPS/QR (2026-09-21, theo yêu cầu người dùng): mỗi
    // lượt bấm Chấm công tự ghi IP + vị trí + tên thiết bị của CHÍNH thiết bị
    // đang dùng. IP và tên thiết bị do backend tự đọc từ request; frontend chỉ
    // phải xin tọa độ từ trình duyệt. Mã QR là ô nhập TÙY CHỌN.
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
                () => reject(new Error("Không lấy được vị trí — kiểm tra lại quyền truy cập vị trí của trình duyệt.")),
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
            const payload = { work_shift_id: workShiftId };

            // Lấy vị trí là "cố gắng hết sức", KHÔNG chặn chấm công: nhân viên
            // từ chối quyền / trình duyệt không hỗ trợ / trang chạy http
            // (Geolocation chỉ chạy trên https hoặc localhost) thì vẫn chấm
            // công được, chỉ là log không có địa chỉ và nếu IP cũng không
            // khớp Wifi công ty thì backend đánh dấu "Cần xem lại".
            try {
                const coords = await getGpsPosition();
                payload.latitude = coords.latitude;
                payload.longitude = coords.longitude;
                payload.accuracy_meters = coords.accuracy;
            } catch (e) {
                toast.warning(`${e.message} Lượt chấm công vẫn được ghi nhận nhưng không có địa chỉ.`);
            }

            if (qrReference.value.trim()) {
                payload.qr_reference = qrReference.value.trim();
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
                e.response?.data?.errors?.latitude?.[0] ??
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

    /* ------------------------ Xin duyệt OT (2026-09-21) ----------------------- */

    const otApprovalDialog = ref(false);
    const otApprovalTarget = ref(null);
    const otApprovalForm = ref({ reason: "" });
    const otApprovalErrors = ref({});
    const otApprovalGeneralError = ref("");
    const otApprovalSubmitting = ref(false);

    function openOtApprovalDialog(attendance) {
        otApprovalTarget.value = attendance;
        otApprovalForm.value = { reason: "" };
        otApprovalErrors.value = {};
        otApprovalGeneralError.value = "";
        otApprovalDialog.value = true;
    }

    function closeOtApprovalDialog() {
        otApprovalDialog.value = false;
    }

    async function submitOtApprovalRequest() {
        otApprovalErrors.value = {};
        otApprovalGeneralError.value = "";

        otApprovalSubmitting.value = true;
        try {
            await attendanceService.requestAdjustment({
                type: "overtime",
                attendance_id: otApprovalTarget.value.id,
                reason: otApprovalForm.value.reason,
            });
            toast.success("Đã gửi yêu cầu duyệt OT, chờ duyệt.");
            closeOtApprovalDialog();
        } catch (e) {
            const status = e.response?.status;
            const data = e.response?.data;
            if (status === 422 && data?.errors) {
                otApprovalErrors.value = { reason: data.errors.reason?.[0] };
                otApprovalGeneralError.value = data.errors.attendance_id?.[0] ?? "";
            } else {
                otApprovalGeneralError.value = data?.message ?? "Không thể gửi yêu cầu, vui lòng thử lại.";
            }
        } finally {
            otApprovalSubmitting.value = false;
        }
    }

    /* --------------------- Xin làm ngoài lịch / Xin OT (2026-09-23) ------------ */
    // 1 dialog, 2 tab (theo yêu cầu người dùng):
    // - Tab "extra_shift" — ĐĂNG KÝ TRƯỚC cho 1 ngày/ca KHÔNG có trong lịch
    //   gán (gộp "làm thêm ngày"/"làm bù T7-CN"), chọn 1 Ca công ty ĐÃ có
    //   HOẶC "Tự chọn giờ" (Backend tự tạo 1 Ca tạm đúng khung giờ đó — xem
    //   AttendanceAdjustmentService::requestForEmployee()). Duyệt xong tự
    //   chấm công vào/ra bình thường khi tới ngày đó, không có giờ vào/ra đề
    //   xuất ở bước này (khác "Bổ sung chấm công").
    // - Tab "overtime" — ĐĂNG KÝ TRƯỚC cho OT của 1 ca đang làm HÔM NAY (đã
    //   chấm công vào, có thể CHƯA chấm công ra) — tái dùng type 'overtime'
    //   đã có (trước đó chỉ xin ĐƯỢC SAU khi chấm công ra và có
    //   overtime_minutes>0; giờ nới thêm cho phép xin TRƯỚC — xem
    //   AttendanceAdjustmentService, nhánh 'overtime'). Duyệt chỉ set
    //   overtime_approved=true NGAY (không đụng giờ), số phút OT thật sự vẫn
    //   tính khi nhân viên chấm công ra như bình thường — PayrollService chỉ
    //   cần cờ này = true lúc đó là đủ điều kiện trả lương (không cần xin lại
    //   lần 2 sau khi đã chấm công ra).
    const extraShiftDialog = ref(false);
    const extraShiftTab = ref("extra_shift");

    // -- Tab "Làm ngoài lịch" --
    const extraShiftForm = ref({
        attendanceDate: "",
        mode: "existing", // "existing" = chọn Ca có sẵn | "custom" = tự chọn giờ
        workShiftId: null,
        customStartTime: "",
        customEndTime: "",
        reason: "",
    });
    const extraShiftErrors = ref({});
    const extraShiftGeneralError = ref("");
    const extraShiftSubmitting = ref(false);
    const allShiftOptions = ref([]);
    const myExtraShiftRequests = ref([]);
    const loadingMyExtraShiftRequests = ref(false);

    async function loadAllShiftOptions() {
        try {
            const response = await workShiftService.list({ per_page: 1000 });
            allShiftOptions.value = response.data.data
                .filter((shift) => shift.is_active)
                .map((shift) => ({ title: shift.name, value: shift.id }));
        } catch {
            allShiftOptions.value = [];
        }
    }

    async function loadMyExtraShiftRequests() {
        loadingMyExtraShiftRequests.value = true;
        try {
            const response = await attendanceService.myAdjustments();
            // Gộp cả 2 tab vào CHUNG 1 danh sách "của tôi" — 'overtime' loại
            // này khác 'overtime' xin từ dòng lịch sử cụ thể (đã chấm công
            // ra) ở chỗ target là ca ĐANG làm hôm nay, nhưng cùng type nên
            // không cần lọc phân biệt, HR/nhân viên đều xem chung 1 nơi.
            myExtraShiftRequests.value = response.data.filter(
                (item) => item.type === "extra_shift" || item.type === "overtime",
            );
        } catch {
            myExtraShiftRequests.value = [];
        } finally {
            loadingMyExtraShiftRequests.value = false;
        }
    }

    // -- Tab "Xin OT" -- ca hôm nay ĐÃ chấm công vào, CHƯA được duyệt OT, và
    // (chưa chấm công ra HOẶC đã có overtime_minutes) — loại bỏ ca chưa vào
    // ca (không có gì để xin) và ca đã ra mà 0 phút OT (không có gì để duyệt).
    const otTodayOptions = computed(() =>
        todayShifts.value
            .filter(
                (entry) =>
                    entry.attendance?.first_check_in_at &&
                    !entry.attendance?.overtime_approved &&
                    (!entry.attendance?.last_check_out_at || entry.attendance?.overtime_minutes > 0),
            )
            .map((entry) => ({
                title: entry.attendance.last_check_out_at
                    ? `${entry.work_shift.name} (kết thúc ${entry.work_shift.end_time?.slice(0, 5)}, đã có ${entry.attendance.overtime_minutes} phút OT)`
                    : `${entry.work_shift.name} (kết thúc lúc ${entry.work_shift.end_time?.slice(0, 5)})`,
                value: entry.attendance.id,
            })),
    );
    const otRequestForm = ref({ attendanceId: null, reason: "" });
    const otRequestErrors = ref({});
    const otRequestGeneralError = ref("");
    const otRequestSubmitting = ref(false);

    function openExtraShiftDialog(tab = "extra_shift") {
        extraShiftTab.value = tab;
        extraShiftForm.value = {
            attendanceDate: "",
            mode: "existing",
            workShiftId: null,
            customStartTime: "",
            customEndTime: "",
            reason: "",
        };
        extraShiftErrors.value = {};
        extraShiftGeneralError.value = "";
        otRequestForm.value = { attendanceId: null, reason: "" };
        otRequestErrors.value = {};
        otRequestGeneralError.value = "";
        extraShiftDialog.value = true;
        loadAllShiftOptions();
    }

    function closeExtraShiftDialog() {
        extraShiftDialog.value = false;
    }

    async function submitExtraShiftRequest() {
        extraShiftErrors.value = {};
        extraShiftGeneralError.value = "";

        const usingCustomTime = extraShiftForm.value.mode === "custom";
        if (
            !extraShiftForm.value.attendanceDate ||
            !extraShiftForm.value.reason ||
            (usingCustomTime
                ? !extraShiftForm.value.customStartTime || !extraShiftForm.value.customEndTime
                : !extraShiftForm.value.workShiftId)
        ) {
            extraShiftGeneralError.value = "Vui lòng chọn ngày, ca làm việc (hoặc giờ tự chọn) và nhập lý do.";
            return;
        }

        extraShiftSubmitting.value = true;
        try {
            await attendanceService.requestAdjustment({
                type: "extra_shift",
                attendance_date: extraShiftForm.value.attendanceDate,
                reason: extraShiftForm.value.reason,
                ...(usingCustomTime
                    ? {
                          custom_start_time: extraShiftForm.value.customStartTime,
                          custom_end_time: extraShiftForm.value.customEndTime,
                      }
                    : { work_shift_id: extraShiftForm.value.workShiftId }),
            });
            toast.success("Đã gửi yêu cầu làm ngoài lịch, chờ duyệt.");
            closeExtraShiftDialog();
            await loadMyExtraShiftRequests();
        } catch (e) {
            const status = e.response?.status;
            const data = e.response?.data;
            if (status === 422 && data?.errors) {
                extraShiftErrors.value = {
                    attendance_date: data.errors.attendance_date?.[0],
                    work_shift_id: data.errors.work_shift_id?.[0],
                    custom_start_time: data.errors.custom_start_time?.[0],
                    custom_end_time: data.errors.custom_end_time?.[0],
                    reason: data.errors.reason?.[0],
                };
                extraShiftGeneralError.value =
                    extraShiftErrors.value.work_shift_id ??
                    extraShiftErrors.value.custom_start_time ??
                    extraShiftErrors.value.custom_end_time ??
                    "";
            } else {
                extraShiftGeneralError.value = data?.message ?? "Không thể gửi yêu cầu, vui lòng thử lại.";
            }
        } finally {
            extraShiftSubmitting.value = false;
        }
    }

    async function submitOtRequestFromToday() {
        otRequestErrors.value = {};
        otRequestGeneralError.value = "";

        if (!otRequestForm.value.attendanceId || !otRequestForm.value.reason) {
            otRequestGeneralError.value = "Vui lòng chọn ca đang làm và nhập lý do.";
            return;
        }

        otRequestSubmitting.value = true;
        try {
            await attendanceService.requestAdjustment({
                type: "overtime",
                attendance_id: otRequestForm.value.attendanceId,
                reason: otRequestForm.value.reason,
            });
            toast.success("Đã gửi yêu cầu xin OT, chờ duyệt.");
            closeExtraShiftDialog();
            await loadMyExtraShiftRequests();
        } catch (e) {
            const status = e.response?.status;
            const data = e.response?.data;
            if (status === 422 && data?.errors) {
                otRequestErrors.value = { reason: data.errors.reason?.[0] };
                otRequestGeneralError.value = data.errors.attendance_id?.[0] ?? "";
            } else {
                otRequestGeneralError.value = data?.message ?? "Không thể gửi yêu cầu, vui lòng thử lại.";
            }
        } finally {
            otRequestSubmitting.value = false;
        }
    }

    onMounted(() => {
        loadToday();
        loadHistory();
        loadMyExtraShiftRequests();
    });

    return {
        todayIso,
        todayShifts,
        loadingToday,
        loadError,
        history,
        loadingHistory,
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
        otApprovalDialog,
        otApprovalTarget,
        otApprovalForm,
        otApprovalErrors,
        otApprovalGeneralError,
        otApprovalSubmitting,
        openOtApprovalDialog,
        closeOtApprovalDialog,
        submitOtApprovalRequest,
        extraShiftDialog,
        extraShiftTab,
        extraShiftForm,
        extraShiftErrors,
        extraShiftGeneralError,
        extraShiftSubmitting,
        allShiftOptions,
        myExtraShiftRequests,
        loadingMyExtraShiftRequests,
        openExtraShiftDialog,
        closeExtraShiftDialog,
        submitExtraShiftRequest,
        otTodayOptions,
        otRequestForm,
        otRequestErrors,
        otRequestGeneralError,
        otRequestSubmitting,
        submitOtRequestFromToday,
    };
}
