<?php

namespace App\Services;

use App\Events\AttendanceApprovalDecided;
use App\Events\AttendanceChecked;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkShift;
use App\Repositories\AttendanceLogRepository;
use App\Repositories\AttendanceRepository;
use App\Services\Attendance\DeviceInfoParser;
use App\Services\Attendance\ReverseGeocoder;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    // Khung giờ hợp lệ để chấm công VÀO 1 ca: sớm nhất bao nhiêu phút trước
    // start_time (xác nhận qua AskUserQuestion — Ngày 44, sau khi phát hiện
    // bug cho chấm công "Ca chiều" lúc 8h sáng vì trước đó chỉ kiểm tra ca có
    // được gán đúng NGÀY hay không, không so giờ hiện tại với giờ ca).
    private const CHECK_IN_EARLY_MINUTES = 30;

    // Ân hạn OT (2026-09-21, theo yêu cầu người dùng) — ra trễ vài phút do
    // dọn dẹp/di chuyển không nên tính thành OT ngay lập tức, cùng tinh
    // thần late_grace_minutes/early_leave_grace_minutes đã có trên
    // WorkShift, nhưng đây là hằng số CỐ ĐỊNH toàn hệ thống (không cấu
    // hình theo Ca) theo đúng yêu cầu — xem calculateOvertimeMinutes().
    private const OVERTIME_GRACE_MINUTES = 5;

    public function __construct(
        private readonly AttendanceRepository $attendanceRepository,
        private readonly AttendanceLogRepository $attendanceLogRepository,
        private readonly WorkTimeCalculationService $workTimeCalculationService,
        private readonly DeviceInfoParser $deviceInfoParser,
        private readonly ReverseGeocoder $reverseGeocoder,
        private readonly NotificationService $notificationService,
    ) {
    }

    // Trạng thái chấm công hôm nay theo TỪNG CA đang được gán (mục 14, có
    // thể nhiều ca cùng ngày) — mỗi phần tử là 1 ca kèm bản ghi attendances
    // của ca đó nếu đã chấm công (null nếu chưa). Dùng cho CheckIn.vue dựng
    // 1 card/ca thay vì 1 khối trạng thái chung cho cả ngày.
    public function listTodayStatusForEmployee(Employee $employee): Collection
    {
        $now = now();
        $today = $now->toDateString();

        return $this->listActiveAssignmentsForDate($employee, $now)->map(fn (EmployeeShiftAssignment $assignment) => [
            'work_shift' => $assignment->workShift,
            'attendance' => $this->attendanceRepository->findForShift($employee, $assignment->work_shift_id, $today),
        ])->values();
    }

    // Báo cáo "Lịch sử chấm công" theo khoảng ngày (mục 18) — đối chiếu TOÀN
    // BỘ ca đang được gán (employee_shift_assignments, không phải chỉ những
    // gì đã có trong attendances) với bản ghi chấm công thật: ca nào được
    // gán mà KHÔNG có bản ghi thì suy ra trạng thái "absent" (Vắng), khác
    // listTodayStatusForEmployee() (chỉ xét đúng hôm nay).
    public function history(Employee $employee, string $dateFrom, string $dateTo, ?int $workShiftId, ?string $status): array
    {
        // Không dựng dòng nào cho ngày TRONG TƯƠNG LAI (2026-09-24, theo
        // phản hồi người dùng: mở tab "Chấm công" hôm nay 23/9 nhưng bộ lọc
        // mặc định "Tháng hiện tại" (AttendanceHistoryPanel.vue) kéo dài tới
        // hết tháng 30/9 — các ngày 24-30 CHƯA XẢY RA vẫn bị coi là "Vắng"
        // (chưa từng chấm công) rồi xếp LÊN TRÊN ngày 23/9 vừa chấm công
        // thật vì sortBy ngày GIẢM DẦN ở dưới không phân biệt "chưa tới" với
        // "đã qua mà vắng thật". Ép `$dateTo` không vượt quá HÔM NAY — nếu
        // cả khoảng lọc đều ở tương lai thì trả về rỗng, không lỗi.
        $dateTo = min($dateTo, now()->toDateString());

        $attendancesByKey = $this->attendanceRepository
            ->listForEmployeeInRange($employee, $dateFrom, $dateTo)
            ->keyBy(fn (Attendance $a) => $a->attendance_date->toDateString() . '|' . $a->work_shift_id);
        $approvedLeaveDates = $this->approvedLeaveDatesForEmployee($employee, $dateFrom, $dateTo);

        $rows = collect();

        foreach (CarbonPeriod::create($dateFrom, $dateTo) as $date) {
            $dateStr = $date->toDateString();

            // listActiveAssignmentsForDate() đã tự lọc bỏ bản gán "mồ côi"
            // (Ca đã xóa mềm) — xem comment ngay trong hàm đó — nên vòng lặp
            // dưới đây không cần tự kiểm tra lại $assignment->workShift.
            foreach ($this->listActiveAssignmentsForDate($employee, $date) as $assignment) {
                if ($workShiftId && $assignment->work_shift_id !== $workShiftId) {
                    continue;
                }

                $attendance = $attendancesByKey->get($dateStr . '|' . $assignment->work_shift_id);
                // Đơn nghỉ phép đã DUYỆT (mục 19-20) phủ đúng ngày này — ngày
                // 41 chỉ xử lý đơn phủ CẢ NGÀY (full/am/pm), bỏ qua đơn
                // 'hourly' (nghỉ vài tiếng không nên che mất cả ngày công).
                $rowStatus = $this->displayStatusFor($attendance, $approvedLeaveDates->has($dateStr));

                if ($status && $rowStatus !== $status) {
                    continue;
                }

                $rows->push([
                    'date' => $dateStr,
                    'work_shift' => $assignment->workShift,
                    'attendance' => $attendance,
                    'status' => $rowStatus,
                ]);
            }
        }

        // Cùng 1 ca có thể bị lặp qua 2 lượt EmployeeShiftAssignment khác
        // nhau phủ lên cùng ngày (vd sửa gán lại) — dedupe theo ngày+ca,
        // tránh hiện 2 dòng cho đúng 1 ca cùng ngày.
        $rows = $rows
            ->unique(fn (array $row) => $row['date'] . '|' . $row['work_shift']->id)
            ->sortBy([
                ['date', 'desc'],
                ['work_shift.start_time', 'asc'],
            ])
            ->values();

        return [
            'summary' => $this->summarizeHistory($rows),
            'rows' => $rows,
        ];
    }
    // Tổng phút OT đã CHECK-OUT trong khoảng ngày — dùng riêng cho PayrollService,
    // tách khỏi summarizeHistory() (private, gắn với luồng hiển thị lịch sử chấm
    // công) để không phải đổi contract của history() chỉ vì Payroll cần thêm số.
    public function sumOvertimeMinutesForEmployee(Employee $employee, string $dateFrom, string $dateTo): int
    {
        return (int) $this->attendanceRepository
            ->listForEmployeeInRange($employee, $dateFrom, $dateTo)
            ->sum('overtime_minutes');
    }

    // Trạng thái hiển thị DÙNG CHUNG cho history() ("Bảng công gần nhất" ở
    // Dashboard + "Lịch sử chấm công" của nhân viên) VÀ dailyOverview() ("Tổng
    // hợp chấm công" của HR) — 2026-09-29, theo yêu cầu người dùng đồng bộ 2
    // màn (trước đó HR thấy "Hoàn tất/Đang trong ca/Cần xem lại" còn nhân viên
    // thấy "Đủ công/Đi muộn/Thiếu công" cho CÙNG 1 ca). Thứ tự:
    //   1. Không có lượt chấm vào -> 'on_leave' (có đơn phép đã duyệt) / 'absent'.
    //   2. Bị từ chối -> 'rejected'; chưa duyệt -> 'pending_approval' — công
    //      CHỈ được cộng sau khi duyệt (summarizeHistory()/PayrollService), nên
    //      nhân viên cũng phải thấy "Chờ duyệt" mới hiểu vì sao công chưa lên.
    //   3. Đã duyệt -> kết quả ngày công theo deriveHistoryStatus().
    // Đã chấm vào thì THẮNG đơn nghỉ phép (người đó thật sự đã đi làm — trước
    // đây history() làm ngược lại, lệch với dailyOverview()).
    private function displayStatusFor(?Attendance $attendance, bool $onApprovedLeave): string
    {
        if (! $attendance || ! $attendance->first_check_in_at) {
            return $onApprovedLeave ? 'on_leave' : 'absent';
        }
        if ($attendance->approval_status === Attendance::APPROVAL_REJECTED) {
            return 'rejected';
        }
        if ($attendance->approval_status !== Attendance::APPROVAL_APPROVED) {
            return 'pending_approval';
        }

        return $this->deriveHistoryStatus($attendance);
    }

    private function deriveHistoryStatus(?Attendance $attendance): string
    {
        if (! $attendance || ! $attendance->first_check_in_at) {
            return 'absent';
        }
        // late_excused=true (HR đã duyệt "Xin miễn trừ đi muộn", mục 17/18,
        // Ngày 42) — late_minutes vẫn giữ nguyên nhưng không còn coi là
        // "Đi muộn" nữa, chỉ xét tiếp điều kiện về giờ ra.
        if ($attendance->late_minutes > 0 && ! $attendance->late_excused) {
            return 'late';
        }
        // Đã chấm vào, CHƯA chấm ra, và là bản ghi HÔM NAY -> đang trong ca
        // (hoặc đang làm thêm giờ), CHƯA thể kết luận thiếu công (2026-09-29,
        // lỗi thật người dùng báo: Dashboard hiện "Thiếu công" lúc 13h57 cho
        // ca 08:00-17:30 vừa chấm vào). Qua ngày mà vẫn chưa chấm ra thì mới
        // rơi xuống 'insufficient' bên dưới như cũ. Dự án chưa hỗ trợ ca qua
        // đêm (xem EmployeeShiftAssignmentService) nên "hôm nay" là đủ.
        if (! $attendance->last_check_out_at && $attendance->attendance_date?->isToday()) {
            return 'in_progress';
        }
        if (! $attendance->last_check_out_at || $attendance->early_leave_minutes > 0) {
            return 'insufficient';
        }

        return 'full';
    }

    // Tập hợp các ngày (chuỗi "Y-m-d") nằm trong ít nhất 1 đơn nghỉ phép đã
    // DUYỆT (status=approved) của nhân viên, giao với [dateFrom, dateTo] —
    // dùng để gán nhãn "on_leave" ở history() (mục 19-20-41). Chỉ tính đơn
    // full/am/pm (che cả ngày), bỏ qua 'hourly' (chỉ vài tiếng, không nên
    // che mất cả ngày công — xem Ghi chú ở history()).
    private function approvedLeaveDatesForEmployee(Employee $employee, string $dateFrom, string $dateTo): Collection
    {
        $leaveRequests = LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->where('start_session', '!=', 'hourly')
            ->where('from_date', '<=', $dateTo)
            ->where('to_date', '>=', $dateFrom)
            ->get(['from_date', 'to_date']);

        $dates = collect();
        foreach ($leaveRequests as $leaveRequest) {
            foreach (CarbonPeriod::create($leaveRequest->from_date, $leaveRequest->to_date) as $date) {
                $dates->put($date->toDateString(), true);
            }
        }

        return $dates;
    }

    // Tổng ngày công tính qua WorkTimeCalculationService (dùng CHUNG với
    // PayrollService) — bậc thang theo % giờ làm so với ca + trần theo phút
    // đi muộn/về sớm, NHÂN với work_coefficient của ca đó (ca nửa ngày vẫn
    // chỉ đóng góp tối đa 0.5, dù làm đủ 100% giờ của ca đó — xem
    // WorkTimeCalculationService để hiểu rõ vì sao vẫn cần work_coefficient).
    // Ngày "on_leave" không tính vào Vắng lẫn Tổng ngày công/giờ làm — nghỉ
    // phép đã duyệt không phải đi làm cũng không phải vắng không lý do.
    // Tổng ngày công/giờ làm CHỈ cộng bản ghi HR đã duyệt (approval_status=
    // approved) — cùng luật với PayrollService để con số trên màn hình khớp
    // với con số tính lương; bản ghi chờ duyệt/bị từ chối đếm riêng ở
    // 'unapproved_count' để nhân viên thấy vì sao "công" chưa lên.
    private function summarizeHistory(Collection $rows): array
    {
        $withAttendance = $rows->filter(fn (array $row) => ! in_array($row['status'], ['absent', 'on_leave'], true));
        $approved = $withAttendance->filter(fn (array $row) => $row['attendance']->approval_status === Attendance::APPROVAL_APPROVED);

        return [
            'total_work_days' => round((float) $approved->sum(fn (array $row) => $this->workTimeCalculationService->dayEquivalentFor($row['attendance'], $row['work_shift'])), 2),
            // Khi đã miễn trừ đi muộn (late_excused=true) và đã checkout
            // (actual_work_minutes > 0), cộng late_minutes vào tổng giờ làm —
            // nhất quán với dayEquivalentFor() ép ratio=1.0 cho ca đó,
            // tránh hiển thị giờ làm thấp hơn thực tế (ví dụ: 7h43 thay vì 8h).
            'total_work_minutes' => (int) $approved->sum(function (array $row): int {
                $a = $row['attendance'];
                $minutes = (int) ($a->actual_work_minutes ?? 0);
                if ($a->late_excused && ($a->late_minutes ?? 0) > 0 && $minutes > 0) {
                    $minutes += (int) $a->late_minutes;
                }

                return $minutes;
            }),
            'unapproved_count' => $withAttendance->count() - $approved->count(),
            'late_count' => $rows->filter(fn (array $row) => $row['status'] !== 'on_leave' && ($row['attendance']->late_minutes ?? 0) > 0 && ! ($row['attendance']->late_excused ?? false))->count(),
            'early_leave_count' => $rows->filter(fn (array $row) => $row['status'] !== 'on_leave' && ($row['attendance']->early_leave_minutes ?? 0) > 0)->count(),
            'on_leave_count' => $rows->filter(fn (array $row) => $row['status'] === 'on_leave')->count(),
        ];
    }

    public function listForEmployee(Employee $employee): Collection
    {
        return $this->attendanceRepository->listForEmployee($employee);
    }

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->attendanceRepository->paginate(perPage: $perPage, filters: $filters);
    }

    // "Tổng hợp chấm công trong ngày" (2026-09-23, theo yêu cầu người dùng —
    // thay cho màn "Duyệt chấm công" cũ chỉ liệt kê bản ghi ĐÃ CÓ trong
    // attendances): liệt kê MỌI ca được gán cho MỌI nhân viên vào đúng 1
    // ngày, kể cả ca CHƯA AI chấm công (status suy ra 'absent') — quản lý
    // nhìn được bức tranh toàn cảnh thay vì chỉ 1 hàng chờ duyệt. Không phân
    // trang: quy mô "1 ngày x toàn công ty" chỉ vài chục-vài trăm dòng, khác
    // history()/list() vốn trải dài nhiều tháng.
    //
    // $status (lọc) và `status` mỗi dòng dùng CHUNG bộ giá trị với history()
    // — xem displayStatusFor().
    public function dailyOverview(string $date, ?int $departmentId, ?int $workShiftId, ?string $status, ?string $approvalStatus): array
    {
        $dayIso = Carbon::parse($date)->dayOfWeekIso;

        $assignments = $this->attendanceRepository
            ->listAssignmentsForDate($date, $departmentId, $workShiftId)
            ->filter(fn (EmployeeShiftAssignment $a) => in_array($dayIso, $a->work_days, true));

        $attendancesByKey = $this->attendanceRepository->listAttendancesForDate($date);
        $onLeaveEmployeeIds = $this->approvedLeaveEmployeeIdsForDate($date);

        $rows = $assignments
            // Bản gán "mồ côi" — employee/workShift đã bị xóa mềm nhưng bản
            // gán còn sót (lỗi dữ liệu, không nên xảy ra sau khi
            // WorkShiftService::delete()/EmployeeService::delete() đã gỡ
            // bản gán trước khi xóa — 2026-09-24, xem comment ở đó) — bỏ qua
            // thay vì để `$row['employee']->id`/`$row['work_shift']->id` bên
            // dưới sập nguyên trang với lỗi "Attempt to read property id on
            // null".
            ->filter(fn (EmployeeShiftAssignment $a) => $a->employee !== null && $a->workShift !== null)
            ->map(function (EmployeeShiftAssignment $assignment) use ($attendancesByKey, $onLeaveEmployeeIds) {
                $attendance = $attendancesByKey->get($assignment->employee_id.'|'.$assignment->work_shift_id);

                return [
                    'employee' => $assignment->employee,
                    'work_shift' => $assignment->workShift,
                    'attendance' => $attendance,
                    'status' => $this->displayStatusFor($attendance, $onLeaveEmployeeIds->contains($assignment->employee_id)),
                ];
            })
            // Cùng lý do dedupe ở history(): 1 ca có thể bị lặp qua 2 lượt gán
            // khác nhau phủ lên cùng ngày cho cùng 1 nhân viên.
            ->unique(fn (array $row) => $row['employee']->id.'|'.$row['work_shift']->id)
            ->values();

        $coveredKeys = $rows->map(fn (array $row) => $row['employee']->id.'|'.$row['work_shift']->id)->flip();

        // Lưới an toàn (2026-09-25, sửa lỗi thật): bản ghi chấm công đã tồn
        // tại nhưng KHÔNG khớp assignment nào đang được liệt kê ở trên — vd
        // nhân viên chấm công dưới 1 ca, RỒI CÙNG NGÀY được đổi sang ca khác
        // (assignment cũ bị xóa mềm bởi EmployeeShiftAssignmentService).
        // listAssignmentsForDate() chỉ thấy assignment CÒN HIỆU LỰC (xóa mềm
        // là global scope loại khỏi MỌI truy vấn, kể cả xem lại NGÀY TRONG
        // QUÁ KHỨ lúc nó còn hợp lệ) — không có bước này thì bản ghi chấm
        // công/chờ duyệt đó BIẾN MẤT khỏi màn "Tổng hợp chấm công" dù vẫn
        // còn nguyên trong DB, HR không bao giờ thấy để duyệt (đã xác nhận
        // qua tinker: attendance work_shift_id=2 nhưng assignment hiện hành
        // của nhân viên đó là work_shift_id=21, effective_from CÙNG ngày).
        $orphanedRows = $attendancesByKey
            ->reject(fn (Attendance $attendance, string $key) => $coveredKeys->has($key))
            ->filter(fn (Attendance $attendance) => $attendance->employee !== null && $attendance->workShift !== null)
            ->when($workShiftId, fn (Collection $c, int $id) => $c->filter(fn (Attendance $a) => $a->work_shift_id === $id))
            ->when($departmentId, fn (Collection $c, int $id) => $c->filter(fn (Attendance $a) => $a->employee->department_id === $id))
            ->map(fn (Attendance $attendance) => [
                'employee' => $attendance->employee,
                'work_shift' => $attendance->workShift,
                'attendance' => $attendance,
                'status' => $this->displayStatusFor($attendance, false),
            ])
            ->values();

        $rows = $rows->concat($orphanedRows)
            ->when($status, fn (Collection $rows) => $rows->filter(fn (array $row) => $row['status'] === $status))
            ->when($approvalStatus, fn (Collection $rows) => $rows->filter(fn (array $row) => $row['attendance']?->approval_status === $approvalStatus))
            ->sortBy([
                ['work_shift.start_time', 'asc'],
                ['employee.full_name', 'asc'],
            ])
            ->values();

        return [
            'summary' => $this->summarizeDailyOverview($rows),
            'rows' => $rows,
        ];
    }

    private function approvedLeaveEmployeeIdsForDate(string $date): Collection
    {
        return LeaveRequest::where('status', 'approved')
            ->where('start_session', '!=', 'hourly')
            ->where('from_date', '<=', $date)
            ->where('to_date', '>=', $date)
            ->pluck('employee_id');
    }

    // Đếm theo NHÂN VIÊN, KHÔNG theo ca (2026-09-23, theo phản hồi người
    // dùng): 1 người có 2 ca cùng ngày (mục 14) trước đó bị đếm 2 lần ở thẻ
    // tổng quan (vd "Vắng: 5 ca" dù chỉ 3 người thật sự vắng, mỗi người có
    // 2 ca không ai chấm). $rows vẫn giữ nguyên 1 dòng/ca cho bảng danh sách
    // (còn dùng để duyệt riêng từng ca) — chỉ phần TỔNG HỢP này gộp lại.
    //
    // 1 người có nhiều ca mà mỗi ca 1 trạng thái khác nhau thì rơi vào ĐÚNG 1
    // nhóm theo thứ tự ưu tiên dưới đây — việc HR CẦN LÀM đứng trước (từ
    // chối, chờ duyệt), rồi kết quả đáng lo (thiếu công, đi muộn), rồi "Đang
    // làm" THẮNG "Vắng" (2026-09-23, theo phản hồi người dùng: người đang trong
    // ca sáng không được tính "Vắng" chỉ vì ca chiều chưa tới giờ — đang làm
    // là sự thật NGAY LÚC NÀY), cuối cùng đủ công > nghỉ phép (chỉ khi TẤT CẢ
    // ca của người đó đều nghỉ phép).
    private const EMPLOYEE_STATUS_PRIORITY = [
        'rejected', 'pending_approval', 'insufficient', 'late', 'in_progress', 'absent', 'full', 'on_leave',
    ];

    private function summarizeDailyOverview(Collection $rows): array
    {
        $rowsByEmployee = $rows->groupBy(fn (array $row) => $row['employee']->id);

        $employeeStatus = $rowsByEmployee->map(function (Collection $employeeRows) {
            $statuses = $employeeRows->pluck('status');

            return collect(self::EMPLOYEE_STATUS_PRIORITY)->first(fn ($p) => $statuses->contains($p));
        });

        $countOf = fn (string $status) => $employeeStatus->filter(fn ($s) => $s === $status)->count();

        return [
            'total' => $employeeStatus->count(),
            'pending_approval' => $countOf('pending_approval'),
            'rejected' => $countOf('rejected'),
            'in_progress' => $countOf('in_progress'),
            'full' => $countOf('full'),
            'late' => $countOf('late'),
            'insufficient' => $countOf('insufficient'),
            'absent' => $countOf('absent'),
            'on_leave' => $countOf('on_leave'),
            // Số người ĐÃ có mặt (có ít nhất 1 lượt chấm vào, bất kể đã duyệt
            // hay chưa) — DashboardService dùng cho "Tỷ lệ đi làm".
            'present' => $rowsByEmployee->filter(fn (Collection $employeeRows) => $employeeRows->contains(
                fn (array $row) => $row['attendance']?->first_check_in_at !== null,
            ))->count(),
        ];
    }

    // HR duyệt / từ chối 1 bản ghi chấm công (2026-09-21, theo yêu cầu người
    // dùng: mọi lượt chấm công phải được duyệt, chưa duyệt thì không tính
    // công/lương). $status: 'approved' | 'rejected'.
    //
    // Duyệt được NGAY TỪ LÚC CHẤM CÔNG VÀO (2026-09-23, sửa lại theo đúng ý
    // người dùng — trước đó bắt phải chấm công RA mới cho duyệt, nhưng mục
    // đích thật của việc duyệt là XÁC NHẬN LƯỢT CHẤM CÔNG VÀO này có thật hay
    // không — HR nhìn giờ vào/thiết bị/vị trí ngay khi nhân viên vừa vào ca
    // để biết họ đi sớm hay trễ, có thật sự tới nơi làm việc hay không, KHÔNG
    // cần chờ tới lúc họ chấm công ra). Chỉ đòi hỏi ĐÃ chấm công VÀO — bản
    // ghi thật sự không tồn tại nếu chưa chấm công vào (xem
    // AttendanceRepository::findOrCreateForShift(), luôn set
    // first_check_in_at ngay khi tạo), kiểm tra ở đây chỉ để phòng vệ. Quên
    // chấm công RA thì nhân viên dùng "Xin điều chỉnh công", HR duyệt yêu
    // cầu đó là duyệt luôn bản ghi (xem AttendanceAdjustmentService::decide()).
    // Cho phép ĐỔI quyết định (duyệt <-> từ chối) vì HR có thể bấm nhầm; chỉ
    // chặn quyết định trùng trạng thái hiện tại. LƯU Ý: bảng lương chỉ được
    // tính MỘT LẦN lúc tạo kỳ lương (PayrollService::generateForPeriod()),
    // không tự tính lại khi quyết định duyệt đổi sau đó — nên
    // generateForPeriod() chặn tạo bảng lương khi kỳ đó còn bản ghi đã chấm
    // công ra mà chưa được duyệt/từ chối (duyệt SỚM lúc chấm công vào rồi thì
    // tới lúc chấm công ra không cần duyệt lại — quyết định cũ vẫn giữ nguyên).
    public function decideApproval(Attendance $attendance, string $status, ?string $note, int $decidedBy): Attendance
    {
        if (! $attendance->first_check_in_at) {
            throw ValidationException::withMessages([
                'status' => 'Bản ghi này chưa chấm công vào nên chưa thể duyệt.',
            ]);
        }

        if ($attendance->approval_status === $status) {
            throw ValidationException::withMessages([
                'status' => $status === Attendance::APPROVAL_APPROVED
                    ? 'Bản ghi này đã được duyệt rồi.'
                    : 'Bản ghi này đã bị từ chối rồi.',
            ]);
        }

        $attendance->forceFill([
            'approval_status' => $status,
            'approved_by' => $decidedBy,
            'approved_at' => now(),
            'approval_note' => $note,
        ])->save();

        AttendanceApprovalDecided::dispatch($attendance);

        return $attendance;
    }

    // Duyệt/Từ chối HÀNG LOẠT (2026-09-25, theo yêu cầu người dùng, kèm ảnh
    // tham khảo) — lặp lại decideApproval() cho TỪNG bản ghi thay vì viết
    // logic riêng, để không lệch quy tắc (đã chấm công vào chưa, đã duyệt
    // rồi chưa...). KHÔNG dừng cả loạt nếu 1 bản ghi lỗi — trả về đủ cả
    // "succeeded"/"failed" để Frontend báo rõ đúng bản ghi nào không duyệt
    // được và vì sao, thay vì rollback tất cả chỉ vì 1 dòng có vấn đề (vd HR
    // chọn nhầm 1 dòng đã được duyệt từ trước đó bởi người khác).
    public function bulkDecideApproval(array $attendanceIds, string $status, ?string $note, int $decidedBy): array
    {
        $succeeded = [];
        $failed = [];

        foreach ($attendanceIds as $attendanceId) {
            $attendance = Attendance::find($attendanceId);

            if ($attendance === null) {
                $failed[] = ['id' => $attendanceId, 'message' => 'Bản ghi không tồn tại.'];

                continue;
            }

            try {
                $this->decideApproval($attendance, $status, $note, $decidedBy);
                $succeeded[] = $attendanceId;
            } catch (ValidationException $e) {
                $failed[] = ['id' => $attendanceId, 'message' => collect($e->errors())->flatten()->first()];
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    // Áp dụng giờ vào/ra đã được DUYỆT từ 1 yêu cầu điều chỉnh công (mục 17)
    // — gọi từ AttendanceAdjustmentService::decide() khi status='approved'.
    // Tái dùng đúng công thức tính trễ/về sớm/giờ công/OT đã dùng cho
    // checkIn()/checkOut(), không viết lại. Chỉ ghi đè field nào THẬT SỰ được
    // đề xuất (proposed_check_in_at/proposed_check_out_at đều nullable — yêu
    // cầu có thể chỉ sửa 1 trong 2 mốc, giữ nguyên mốc còn lại).
    public function applyAdjustment(Attendance $attendance, ?Carbon $checkIn, ?Carbon $checkOut): Attendance
    {
        $workShift = $attendance->workShift;
        $data = [];

        if ($checkIn) {
            $data['first_check_in_at'] = $checkIn;
            $data['late_minutes'] = $this->calculateLateMinutes($workShift, $checkIn);
        }
        if ($checkOut) {
            $data['last_check_out_at'] = $checkOut;
            $data['early_leave_minutes'] = $this->calculateEarlyLeaveMinutes($workShift, $checkOut);
        }

        $effectiveIn = $checkIn ?? $attendance->first_check_in_at;
        $effectiveOut = $checkOut ?? $attendance->last_check_out_at;
        if ($effectiveIn && $effectiveOut) {
            $data['actual_work_minutes'] = $this->calculateActualWorkMinutes($effectiveIn, $effectiveOut, $workShift);
            $data['overtime_minutes'] = $this->calculateOvertimeMinutes($workShift, $effectiveOut);
        }

        // Chỉ 'completed' khi đã có ĐỦ cả giờ vào lẫn giờ ra hiệu lực — nếu yêu
        // cầu điều chỉnh chỉ sửa giờ vào (chưa chấm công ra), đánh dấu
        // 'completed' ngay sẽ sai vì nhân viên coi như còn đang làm việc.
        $data['status'] = ($effectiveIn && $effectiveOut) ? 'completed' : 'pending';

        $attendance->forceFill($data)->save();

        return $attendance;
    }

    // Dùng cho yêu cầu "Bổ sung chấm công" (mục 17, type=supplement) khi
    // được duyệt — tạo (hoặc tìm nếu đã lỡ có) bản ghi attendances cho đúng
    // ca+ngày đang xin bổ sung, để applyAdjustment() ghi đè giờ vào/ra lên
    // đó. AttendanceAdjustmentService không cần biết tới AttendanceRepository.
    public function findOrCreateAttendanceForShift(Employee $employee, WorkShift $workShift, string $date): Attendance
    {
        return $this->attendanceRepository->findOrCreateForShift($employee, $workShift, $date);
    }

    // Chấm công = ghi nhận IP/vị trí/thiết bị của CHÍNH người bấm (2026-09-30:
    // đã bỏ hẳn "Điểm chấm công" của công ty — không còn đối chiếu/khớp điểm).
    // Ca chấm công NÀY do
    // frontend gửi rõ work_shift_id (mỗi card ca có nút riêng, mục 16) —
    // không còn tự đoán "ca gần nhất" như bản trước.
    public function checkIn(Employee $employee, array $data): AttendanceLog
    {
        $now = now();
        $today = $now->toDateString();
        $workShift = WorkShift::findOrFail($data['work_shift_id']);

        if (! $this->resolveActiveAssignment($employee, $workShift, $now)) {
            throw ValidationException::withMessages([
                'work_shift_id' => 'Ca này không được gán cho bạn hôm nay.',
            ]);
        }

        $this->assertWithinCheckInWindow($workShift, $now);

        $existing = $this->attendanceRepository->findForShift($employee, $workShift->id, $today);
        if ($existing && $existing->first_check_in_at) {
            throw ValidationException::withMessages([
                'work_shift_id' => 'Bạn đã chấm công vào ca này hôm nay lúc ' . $existing->first_check_in_at->format('H:i') . '.',
            ]);
        }

        $device = $this->captureDeviceContext($data);

        $log = DB::transaction(function () use ($employee, $workShift, $device, $data, $now, $today) {
            $attendance = $this->attendanceRepository->findOrCreateForShift($employee, $workShift, $today);

            $lateMinutes = $this->calculateLateMinutes($workShift, $now);

            $attendance->forceFill([
                'first_check_in_at' => $now,
                'late_minutes' => $lateMinutes,
                'status' => 'pending',
            ])->save();

            return $this->attendanceLogRepository->create($this->logPayload(
                $employee,
                $attendance,
                'check_in',
                $now,
                $data,
                $device,
            ));
        });

        // Bắn SAU KHI transaction đã commit (giống quy ước mail/thông báo ở
        // LeaveApprovalService) — live-feed cho HR xem trực tiếp, KHÔNG lưu DB.
        AttendanceChecked::dispatch($employee, 'in', $now);

        // Thông báo THẬT (lưu bảng notifications, hiện ở chuông) cho người có
        // quyền duyệt (2026-09-25, theo yêu cầu người dùng) — KHÁC hẳn
        // AttendanceChecked ở trên (chỉ broadcast thuần, không lưu DB, phục
        // vụ xem lướt qua). Chỉ báo lúc CHẤM CÔNG VÀO, không báo lúc chấm
        // công RA — khớp đúng thiết kế "duyệt được ngay lúc chấm công vào,
        // không cần chờ ra" (xem decideApproval()), chấm công RA không phát
        // sinh thêm việc cần duyệt.
        $this->notifyApprovers($employee, $log->attendance);

        return $log;
    }

    private function notifyApprovers(Employee $employee, Attendance $attendance): void
    {
        $title = 'Chấm công mới cần duyệt';
        $message = "{$employee->full_name} vừa chấm công vào lúc {$attendance->first_check_in_at->format('H:i')}.";
        $data = ['attendance_id' => $attendance->id];

        foreach (User::withPermission('attendance.approve')->get() as $approver) {
            $this->notificationService->send($approver, 'attendance.pending_approval', $title, $message, $data);
        }
    }

    public function checkOut(Employee $employee, array $data): AttendanceLog
    {
        $now = now();
        $today = $now->toDateString();
        $workShift = WorkShift::findOrFail($data['work_shift_id']);

        $attendance = $this->attendanceRepository->findForShift($employee, $workShift->id, $today);
        if (! $attendance || ! $attendance->first_check_in_at) {
            throw ValidationException::withMessages([
                'work_shift_id' => 'Bạn chưa chấm công vào ca này hôm nay, không thể chấm công ra.',
            ]);
        }
        if ($attendance->last_check_out_at) {
            throw ValidationException::withMessages([
                'work_shift_id' => 'Bạn đã chấm công ra ca này hôm nay lúc ' . $attendance->last_check_out_at->format('H:i') . '.',
            ]);
        }

        $device = $this->captureDeviceContext($data);

        $log = DB::transaction(function () use ($employee, $attendance, $workShift, $device, $data, $now) {
            $earlyLeaveMinutes = $this->calculateEarlyLeaveMinutes($workShift, $now);
            $actualMinutes = $this->calculateActualWorkMinutes($attendance->first_check_in_at, $now, $workShift);

            $attendance->forceFill([
                'last_check_out_at' => $now,
                'early_leave_minutes' => $earlyLeaveMinutes,
                'actual_work_minutes' => $actualMinutes,
                // OT tính từ lúc HẾT CA — chỉ phần thời gian chấm công RA
                // sau end_time mới tính là làm thêm, không phải toàn bộ
                // (tổng giờ làm - giờ chuẩn) như trước.
                'overtime_minutes' => $this->calculateOvertimeMinutes($workShift, $now),
                'status' => 'completed',
            ])->save();

            return $this->attendanceLogRepository->create($this->logPayload(
                $employee,
                $attendance,
                'check_out',
                $now,
                $data,
                $device,
            ));
        });

        AttendanceChecked::dispatch($employee, 'out', $now);

        return $log;
    }

    // Thông tin của CHÍNH THIẾT BỊ đang bấm chấm công (2026-09-21, theo yêu cầu
    // người dùng: dùng điện thoại thì ghi IP điện thoại, địa chỉ nơi bấm, tên
    // thiết bị) — ghi tự động ở MỌI lượt chấm công, không phụ thuộc có khớp
    // điểm chấm công của công ty hay không. Gọi TRƯỚC DB::transaction() vì
    // tra địa chỉ là gọi mạng ra ngoài (tối đa vài giây), không nên giữ
    // transaction/khóa dòng trong lúc chờ. Tra địa chỉ lỗi → address = null,
    // không làm hỏng lượt chấm công (xem ReverseGeocoder).
    private function captureDeviceContext(array $data): array
    {
        $userAgent = request()->userAgent();
        $hasCoordinates = isset($data['latitude'], $data['longitude']);

        return [
            'address' => $hasCoordinates
                ? $this->reverseGeocoder->addressFor((float) $data['latitude'], (float) $data['longitude'])
                : null,
            'device_name' => $this->deviceInfoParser->describe($userAgent),
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
        ];
    }

    private function logPayload(Employee $employee, Attendance $attendance, string $eventType, Carbon $now, array $data, array $device): array
    {
        return [
            'employee_id' => $employee->id,
            'attendance_id' => $attendance->id,
            'event_type' => $eventType,
            'occurred_at' => $now,
            // Cột method luôn là 'device' (dữ liệu của chính thiết bị đang bấm) —
            // giữ lại cột để không phải đổi schema/lịch sử.
            'method' => 'device',
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'accuracy_meters' => $data['accuracy_meters'] ?? null,
            'address' => $device['address'],
            'ip_address' => request()->ip(),
            'device_name' => $device['device_name'],
            'user_agent' => $device['user_agent'],
        ];
    }

    // Toàn bộ ca đang được gán cho nhân viên vào ĐÚNG ngày này (mục 14, có
    // thể nhiều ca cùng ngày, vd Ca sáng + Ca chiều) — sắp theo start_time.
    // Dùng cho listTodayStatusForEmployee() (dựng 1 card/ca) và
    // resolveActiveAssignment() (validate check-in/check-out/bổ sung chấm
    // công theo đúng 1 ca cụ thể).
    public function listActiveAssignmentsForDate(Employee $employee, Carbon $date): Collection
    {
        $dayIso = $date->dayOfWeekIso;
        $dateStr = $date->toDateString();

        return $employee->shiftAssignments()
            ->where('status', 'active')
            ->where('effective_from', '<=', $dateStr)
            ->where(function ($query) use ($dateStr) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $dateStr);
            })
            ->with('workShift')
            ->get()
            ->filter(fn (EmployeeShiftAssignment $assignment) => in_array($dayIso, $assignment->work_days, true))
            // Bản gán "mồ côi" (Ca đã bị xóa mềm nhưng bản gán còn sót —
            // 2026-09-24, xem comment ở dailyOverview()) — bỏ qua trước khi
            // sortBy() đụng vào workShift->start_time, tránh sập TOÀN BỘ các
            // hàm dùng chung phương thức này (checkIn/checkOut/history/...).
            ->filter(fn (EmployeeShiftAssignment $assignment) => $assignment->workShift !== null)
            ->sortBy(fn (EmployeeShiftAssignment $assignment) => $assignment->workShift->start_time)
            ->values();
    }

    public function resolveActiveAssignment(Employee $employee, WorkShift $workShift, Carbon $date): ?EmployeeShiftAssignment
    {
        return $this->listActiveAssignmentsForDate($employee, $date)
            ->first(fn (EmployeeShiftAssignment $assignment) => $assignment->work_shift_id === $workShift->id);
    }

    private function shiftTimeToday(string $time, Carbon $referenceDate): Carbon
    {
        return Carbon::parse($referenceDate->toDateString() . ' ' . $time);
    }

    // Giờ công THỰC TẾ = khoảng chấm công VÀO-RA, TRỪ phần trùng với giờ
    // nghỉ trưa của Ca (2026-09-23, theo yêu cầu người dùng — trước đó
    // break_minutes chỉ là số phút hiển thị, không trừ vào đâu cả).
    // $workShift chưa khai báo break_start_time/break_end_time (ca cũ, hoặc
    // ca ngắn không nghỉ trưa) → không trừ gì, giữ nguyên hành vi cũ. Chỉ
    // trừ đúng PHẦN GIAO giữa [checkIn, checkOut] và [nghỉ trưa] — checkIn
    // sau giờ nghỉ trưa (vd vào ca muộn buổi chiều) hoặc checkOut trước giờ
    // nghỉ trưa (vd ra sớm buổi sáng) thì không giao nhau, không trừ gì.
    private function calculateActualWorkMinutes(Carbon $checkIn, Carbon $checkOut, WorkShift $workShift): int
    {
        $grossMinutes = max(0, $checkIn->diffInMinutes($checkOut));

        if (! $workShift->break_start_time || ! $workShift->break_end_time) {
            return $grossMinutes;
        }

        $breakStart = $this->shiftTimeToday($workShift->break_start_time, $checkIn);
        $breakEnd = $this->shiftTimeToday($workShift->break_end_time, $checkIn);

        $overlapStart = $checkIn->greaterThan($breakStart) ? $checkIn : $breakStart;
        $overlapEnd = $checkOut->lessThan($breakEnd) ? $checkOut : $breakEnd;
        $overlapMinutes = $overlapEnd->greaterThan($overlapStart) ? $overlapStart->diffInMinutes($overlapEnd) : 0;

        return max(0, $grossMinutes - $overlapMinutes);
    }

    // Chặn chấm công VÀO quá xa giờ ca thật (Ngày 44 — xem CHECK_IN_EARLY_MINUTES
    // ở đầu class): sớm hơn start_time - 30 phút thì chưa tới giờ, muộn hơn
    // end_time thì coi như đã lỡ hẳn ca — phải dùng "Xin bổ sung chấm công"
    // (mục 17, type=supplement) để HR duyệt lại giờ thật, không cho tự chấm
    // công "khống" vào 1 ca đã trôi qua từ lâu.
    private function assertWithinCheckInWindow(WorkShift $workShift, Carbon $now): void
    {
        $earliestCheckIn = $this->shiftTimeToday($workShift->start_time, $now)
            ->subMinutes(self::CHECK_IN_EARLY_MINUTES);
        $latestCheckIn = $this->shiftTimeToday($workShift->end_time, $now);

        if ($now->lt($earliestCheckIn)) {
            throw ValidationException::withMessages([
                'work_shift_id' => 'Chưa tới giờ ca — chỉ có thể chấm công vào sớm nhất lúc ' . $earliestCheckIn->format('H:i') . '.',
            ]);
        }

        if ($now->gt($latestCheckIn)) {
            throw ValidationException::withMessages([
                'work_shift_id' => 'Ca này đã kết thúc, không thể chấm công vào nữa. Vui lòng dùng chức năng "Xin bổ sung chấm công".',
            ]);
        }
    }

    private function calculateLateMinutes(WorkShift $workShift, Carbon $checkInAt): int
    {
        $graceDeadline = $this->shiftTimeToday($workShift->start_time, $checkInAt)
            ->addMinutes($workShift->late_grace_minutes);

        return $checkInAt->gt($graceDeadline) ? (int) $graceDeadline->diffInMinutes($checkInAt) : 0;
    }

    private function calculateEarlyLeaveMinutes(WorkShift $workShift, Carbon $checkOutAt): int
    {
        $graceThreshold = $this->shiftTimeToday($workShift->end_time, $checkOutAt)
            ->subMinutes($workShift->early_leave_grace_minutes);

        return $checkOutAt->lt($graceThreshold) ? (int) $checkOutAt->diffInMinutes($graceThreshold) : 0;
    }

    // OT tính từ lúc HẾT CA (end_time) — chỉ phần chấm công RA sau giờ tan
    // ca mới coi là làm thêm giờ, khác actual_work_minutes (tổng thời gian
    // có mặt, không liên quan mốc tan ca). Số phút trả về VẪN LÀ SỐ THỰC (đã
    // trừ ân hạn) dù có đạt ngưỡng tối thiểu để được TRẢ LƯƠNG hay không —
    // ngưỡng đó chỉ áp dụng ở PayrollService::calculateWorkedMetrics(),
    // không zero ở đây để HR vẫn thấy đúng dữ liệu chấm công thật.
    private function calculateOvertimeMinutes(WorkShift $workShift, Carbon $checkOutAt): int
    {
        $shiftEnd = $this->shiftTimeToday($workShift->end_time, $checkOutAt);

        if ($checkOutAt->lte($shiftEnd)) {
            return 0;
        }

        $rawMinutes = (int) $shiftEnd->diffInMinutes($checkOutAt);

        return max(0, $rawMinutes - self::OVERTIME_GRACE_MINUTES);
    }
}
