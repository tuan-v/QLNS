<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceLocation;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveRequest;
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
        $attendancesByKey = $this->attendanceRepository
            ->listForEmployeeInRange($employee, $dateFrom, $dateTo)
            ->keyBy(fn (Attendance $a) => $a->attendance_date->toDateString() . '|' . $a->work_shift_id);
        $approvedLeaveDates = $this->approvedLeaveDatesForEmployee($employee, $dateFrom, $dateTo);

        $rows = collect();

        foreach (CarbonPeriod::create($dateFrom, $dateTo) as $date) {
            $dateStr = $date->toDateString();

            foreach ($this->listActiveAssignmentsForDate($employee, $date) as $assignment) {
                if ($workShiftId && $assignment->work_shift_id !== $workShiftId) {
                    continue;
                }

                $attendance = $attendancesByKey->get($dateStr . '|' . $assignment->work_shift_id);
                // Đơn nghỉ phép đã DUYỆT (mục 19-20) phủ đúng ngày này thì
                // ưu tiên nhãn "on_leave" thay vì suy ra từ Attendance — ngày
                // 41 chỉ xử lý trường hợp đơn phủ CẢ NGÀY (full/am/pm), CHƯA
                // phân biệt nửa ngày che đúng ca sáng/chiều nào (để dành Ngày
                // 42 cùng các edge case khác), cũng bỏ qua đơn 'hourly' (nghỉ
                // vài tiếng không nên che mất cả ngày công).
                $rowStatus = $approvedLeaveDates->has($dateStr)
                    ? 'on_leave'
                    : $this->deriveHistoryStatus($attendance);

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
            'total_work_minutes' => (int) $approved->sum(fn (array $row) => $row['attendance']->actual_work_minutes ?? 0),
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

    // HR duyệt / từ chối 1 bản ghi chấm công (2026-09-21, theo yêu cầu người
    // dùng: mọi lượt chấm công phải được duyệt, chưa duyệt thì không tính
    // công/lương). $status: 'approved' | 'rejected'. Chỉ duyệt được bản ghi
    // ĐÃ chấm công ra (còn đang trong ca thì giờ ra/giờ công còn thay đổi,
    // duyệt sớm vô nghĩa) — quên chấm công ra thì nhân viên dùng "Xin điều
    // chỉnh công", HR duyệt yêu cầu đó là duyệt luôn bản ghi (xem
    // AttendanceAdjustmentService::decide()). Cho phép ĐỔI quyết định
    // (duyệt <-> từ chối) vì HR có thể bấm nhầm; chỉ chặn quyết định trùng
    // trạng thái hiện tại. LƯU Ý: bảng lương chỉ được tính MỘT LẦN lúc tạo
    // kỳ lương (PayrollService::generateForPeriod()), không tự tính lại khi
    // quyết định duyệt đổi sau đó — nên generateForPeriod() chặn tạo bảng
    // lương khi kỳ đó còn bản ghi đã chấm công ra mà chưa được duyệt/từ chối.
    public function decideApproval(Attendance $attendance, string $status, ?string $note, int $decidedBy): Attendance
    {
        if (! $attendance->first_check_in_at || ! $attendance->last_check_out_at) {
            throw ValidationException::withMessages([
                'status' => 'Bản ghi này chưa chấm công ra nên chưa thể duyệt.',
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

        return $attendance;
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
            $data['actual_work_minutes'] = max(0, $effectiveIn->diffInMinutes($effectiveOut));
            $data['overtime_minutes'] = $this->calculateOvertimeMinutes($workShift, $effectiveOut);
        }

        // Đã qua HR duyệt thì coi như hết nghi vấn, kể cả nếu trước đó
        // needs_review vì không khớp điểm chấm công lúc chấm thật. Chỉ
        // 'completed' khi đã có ĐỦ cả giờ vào lẫn giờ ra hiệu lực — nếu yêu
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

    // Quyết định đã chốt (CODE_MAP mục 12): không khớp được điểm chấm công
    // nào thì VẪN cho ghi nhận, không chặn cứng — đánh dấu status='needs_review'
    // để HR xử lý sau (Điều chỉnh công — mục 17). Ca chấm công NÀY do
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

        $matchedLocation = $this->matchLocation($data);
        $device = $this->captureDeviceContext($data);

        return DB::transaction(function () use ($employee, $workShift, $matchedLocation, $device, $data, $now, $today) {
            $attendance = $this->attendanceRepository->findOrCreateForShift($employee, $workShift, $today);

            $lateMinutes = $this->calculateLateMinutes($workShift, $now);

            $attendance->forceFill([
                'first_check_in_at' => $now,
                'late_minutes' => $lateMinutes,
                'status' => $matchedLocation ? 'pending' : 'needs_review',
            ])->save();

            return $this->attendanceLogRepository->create($this->logPayload(
                $employee,
                $attendance,
                $matchedLocation,
                'check_in',
                $now,
                $data,
                $device,
            ));
        });
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

        $matchedLocation = $this->matchLocation($data);
        $device = $this->captureDeviceContext($data);

        return DB::transaction(function () use ($employee, $attendance, $workShift, $matchedLocation, $device, $data, $now) {
            $earlyLeaveMinutes = $this->calculateEarlyLeaveMinutes($workShift, $now);
            $actualMinutes = max(0, $attendance->first_check_in_at->diffInMinutes($now));

            $attendance->forceFill([
                'last_check_out_at' => $now,
                'early_leave_minutes' => $earlyLeaveMinutes,
                'actual_work_minutes' => $actualMinutes,
                // OT tính từ lúc HẾT CA — chỉ phần thời gian chấm công RA
                // sau end_time mới tính là làm thêm, không phải toàn bộ
                // (tổng giờ làm - giờ chuẩn) như trước.
                'overtime_minutes' => $this->calculateOvertimeMinutes($workShift, $now),
                // Đã needs_review từ lúc check-in (không khớp điểm) thì giữ
                // nguyên, không bị check-out (có khớp điểm) ghi đè thành completed.
                'status' => ($attendance->status === 'needs_review' || ! $matchedLocation) ? 'needs_review' : 'completed',
            ])->save();

            return $this->attendanceLogRepository->create($this->logPayload(
                $employee,
                $attendance,
                $matchedLocation,
                'check_out',
                $now,
                $data,
                $device,
            ));
        });
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

    private function logPayload(Employee $employee, Attendance $attendance, ?AttendanceLocation $location, string $eventType, Carbon $now, array $data, array $device): array
    {
        return [
            'employee_id' => $employee->id,
            'attendance_id' => $attendance->id,
            'attendance_location_id' => $location?->id,
            'event_type' => $eventType,
            'occurred_at' => $now,
            // Cột method giờ nghĩa là "khớp điểm chấm công bằng cách nào"
            // (wifi/gps/qr theo điểm đã khớp), không còn là lựa chọn của
            // nhân viên. Không khớp điểm nào → 'device' (chỉ có dữ liệu thiết bị).
            'method' => $location?->method ?? 'device',
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'accuracy_meters' => $data['accuracy_meters'] ?? null,
            'address' => $device['address'],
            'ip_address' => request()->ip(),
            'device_name' => $device['device_name'],
            'user_agent' => $device['user_agent'],
            'qr_reference' => $data['qr_reference'] ?? null,
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
            ->sortBy(fn (EmployeeShiftAssignment $assignment) => $assignment->workShift->start_time)
            ->values();
    }

    public function resolveActiveAssignment(Employee $employee, WorkShift $workShift, Carbon $date): ?EmployeeShiftAssignment
    {
        return $this->listActiveAssignmentsForDate($employee, $date)
            ->first(fn (EmployeeShiftAssignment $assignment) => $assignment->work_shift_id === $workShift->id);
    }

    // Đối chiếu dữ liệu của thiết bị đang chấm công với các "Điểm chấm công" của
    // công ty (2026-09-21: đổi từ "nhân viên chọn phương thức" sang "hệ thống tự
    // thu IP + GPS + QR rồi tự khớp"). Mỗi điểm vẫn có method riêng quyết định
    // NÓ được khớp bằng gì: điểm 'wifi' so IP của request với allowed_ip_cidr,
    // điểm 'gps' so tọa độ với bán kính, điểm 'qr' so mã QR. Khớp được điểm nào
    // → status 'pending' + ghi tên điểm; không khớp → 'needs_review' cho HR
    // xem lại (quyết định đã chốt, không chặn cứng). Thứ tự ưu tiên khi khớp
    // nhiều điểm: QR (nhân viên chủ động quét) > Wifi (IP) > GPS. Chỉ xét
    // điểm đang is_active.
    private function matchLocation(array $data): ?AttendanceLocation
    {
        $locations = AttendanceLocation::where('is_active', true)->get();

        return $this->matchByQr($locations, $data['qr_reference'] ?? null)
            ?? $this->matchByIp($locations, request()->ip())
            ?? $this->matchByGps($locations, $data['latitude'] ?? null, $data['longitude'] ?? null);
    }

    private function matchByQr(Collection $locations, ?string $qrReference): ?AttendanceLocation
    {
        if ($qrReference === null || $qrReference === '') {
            return null;
        }

        return $locations->first(fn (AttendanceLocation $loc) => $loc->method === 'qr' && $loc->qr_secret && $loc->qr_secret === $qrReference);
    }

    private function matchByIp(Collection $locations, ?string $ip): ?AttendanceLocation
    {
        if ($ip === null) {
            return null;
        }

        return $locations->first(fn (AttendanceLocation $loc) => $loc->method === 'wifi' && $loc->allowed_ip_cidr && $this->ipInCidr($ip, $loc->allowed_ip_cidr));
    }

    // Không có tọa độ (nhân viên từ chối quyền vị trí / trình duyệt không hỗ
    // trợ) thì bỏ qua GPS chứ không lỗi — vẫn có thể khớp bằng IP hoặc QR.
    private function matchByGps(Collection $locations, mixed $latitude, mixed $longitude): ?AttendanceLocation
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        return $locations->first(function (AttendanceLocation $loc) use ($lat, $lng) {
            if ($loc->method !== 'gps' || ! $loc->latitude || ! $loc->longitude || ! $loc->radius_meters) {
                return false;
            }

            return $this->distanceMeters($lat, $lng, (float) $loc->latitude, (float) $loc->longitude) <= $loc->radius_meters;
        });
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (! str_contains($cidr, '/')) {
            return $ip === $cidr;
        }

        [$subnet, $maskBits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $maskBits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    // Công thức Haversine — khoảng cách giữa 2 tọa độ GPS theo mét.
    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusMeters = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusMeters * $c;
    }

    private function shiftTimeToday(string $time, Carbon $referenceDate): Carbon
    {
        return Carbon::parse($referenceDate->toDateString() . ' ' . $time);
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
