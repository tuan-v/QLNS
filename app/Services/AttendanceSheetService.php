<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

// Bảng chấm công THÁNG cho toàn công ty (xuất Excel ở AttendanceSheetExport).
// Số liệu theo ĐÚNG luật tính lương (PayrollService) để bảng công khớp phiếu lương:
//   - Công mỗi ngày = tổng dayEquivalentFor() các ca ĐÃ DUYỆT (chờ duyệt/từ chối = 0).
//   - Công chuẩn = standardWorkDaysFor() (T2–T6, trừ ngày lễ, cộng ngày đi làm bù).
//   - Phép có/không lương = tổng total_days đơn đã duyệt chồng lấp tháng (như leaveDaysFor()).
//   - Công hưởng lương = min(công chuẩn, công thực tế + phép có lương).
// Ô không có công: L (lễ) > P/KL (phép có/không lương) > CD (đã chấm vào nhưng chưa
// tính công: chờ duyệt hoặc chưa chấm ra) > TC (bị
// từ chối) > V (vắng — chỉ ngày làm việc theo LỊCH, đã qua, sau ngày vào làm).
class AttendanceSheetService
{
    public const MARK_HOLIDAY = 'L';

    public const MARK_PAID_LEAVE = 'P';

    public const MARK_UNPAID_LEAVE = 'KL';

    public const MARK_PENDING = 'CD';

    public const MARK_REJECTED = 'TC';

    public const MARK_ABSENT = 'V';

    public const MARK_OVERTIME = 'OT';

    // Ngày lễ thực tập sinh KHÔNG được hưởng lương (intern_paid=false).
    public const MARK_UNPAID_HOLIDAY = 'LK';

    // Cùng ngưỡng trả lương OT của PayrollService (phút OT đã lưu).
    private const OVERTIME_MINIMUM_PAYABLE_MINUTES = 25;

    private const WEEKDAY_LABELS = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];

    public function __construct(
        private readonly PayrollService $payrollService,
        private readonly WorkTimeCalculationService $workTimeCalculationService,
    ) {
    }

    public function build(int $year, int $month, ?int $departmentId = null): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();
        $today = now()->toDateString();

        $days = $this->calendarDays($start, $end);
        $standardWorkDays = $this->payrollService->standardWorkDaysFor($start, $end);
        $internStandardWorkDays = $this->payrollService->standardWorkDaysFor($start, $end, forIntern: true);

        // Cùng tập nhân viên như khi tính lương (đang làm / thử việc).
        $employees = Employee::whereIn('employment_status', Employee::WORKING_STATUSES)
            ->when($departmentId, fn ($query, $id) => $query->where('department_id', $id))
            ->with('department')
            ->orderBy('code')
            ->get();
        $employeeIds = $employees->pluck('id');

        $attendancesByEmployee = Attendance::whereIn('employee_id', $employeeIds)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->with('workShift')
            ->get()
            ->groupBy('employee_id');

        $leavesByEmployee = LeaveRequest::whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->where('from_date', '<=', $end->toDateString())
            ->where('to_date', '>=', $start->toDateString())
            ->with('leaveType')
            ->get()
            ->groupBy('employee_id');

        $rows = $employees->map(fn (Employee $employee) => $this->buildRow(
            $employee,
            $days,
            $attendancesByEmployee->get($employee->id, collect()),
            $leavesByEmployee->get($employee->id, collect()),
            $employee->employment_status === 'intern' ? $internStandardWorkDays : $standardWorkDays,
            $today,
        ))->values();

        return [
            'year' => $year,
            'month' => $month,
            'standard_work_days' => $standardWorkDays,
            'days' => $days,
            'rows' => $rows,
        ];
    }

    // Mỗi ngày trong tháng: thứ, loại ngày theo lịch (workday/weekend/holiday/makeup).
    private function calendarDays(Carbon $start, Carbon $end): Collection
    {
        $holidays = Holiday::whereDate('holiday_date', '>=', $start->toDateString())
            ->whereDate('holiday_date', '<=', $end->toDateString())
            ->get()
            ->keyBy(fn (Holiday $holiday) => $holiday->holiday_date->toDateString());
        $makeupDates = Holiday::whereDate('makeup_date', '>=', $start->toDateString())
            ->whereDate('makeup_date', '<=', $end->toDateString())
            ->get()
            ->map(fn (Holiday $holiday) => $holiday->makeup_date->toDateString())
            ->flip();

        return collect(CarbonPeriod::create($start, $end))->map(function (Carbon $date) use ($holidays, $makeupDates) {
            $key = $date->toDateString();
            $type = match (true) {
                $holidays->has($key) => 'holiday',
                $makeupDates->has($key) => 'makeup',
                $date->isWeekend() => 'weekend',
                default => 'workday',
            };

            return [
                'date' => $key,
                'day' => $date->day,
                'weekday' => self::WEEKDAY_LABELS[$date->dayOfWeekIso],
                'type' => $type,
                'holiday_name' => $holidays->get($key)?->name,
                'intern_paid' => (bool) ($holidays->get($key)?->intern_paid ?? true),
            ];
        })->values();
    }

    private function buildRow(Employee $employee, Collection $days, Collection $attendances, Collection $leaves, int $standardWorkDays, string $today): array
    {
        $attendancesByDate = $attendances->groupBy(fn (Attendance $a) => $a->attendance_date->toDateString());
        $leaveByDate = $this->leaveMarksByDate($leaves);
        $hireDate = $employee->hire_date?->toDateString();

        $cells = [];
        $actualWorkDays = 0.0;
        $counts = ['holiday' => 0, 'absent' => 0, 'pending' => 0];

        foreach ($days as $day) {
            $date = $day['date'];
            $dayAttendances = $attendancesByDate->get($date, collect());
            $credit = $this->creditFor($dayAttendances);

            if ($credit > 0) {
                $cells[$date] = $credit;
                $actualWorkDays += $credit;

                continue;
            }

            $mark = $this->markFor($day, $dayAttendances, $leaveByDate->get($date), $hireDate, $today, $employee->employment_status === 'intern');
            $cells[$date] = $mark;

            match ($mark) {
                self::MARK_HOLIDAY, self::MARK_UNPAID_HOLIDAY => $counts['holiday']++,
                self::MARK_ABSENT => $counts['absent']++,
                self::MARK_PENDING => $counts['pending']++,
                default => null,
            };
        }

        $paidLeaveDays = (float) $leaves->filter(fn (LeaveRequest $l) => (bool) $l->leaveType?->is_paid)->sum('total_days');
        $unpaidLeaveDays = (float) $leaves->reject(fn (LeaveRequest $l) => (bool) $l->leaveType?->is_paid)->sum('total_days');
        $actualWorkDays = round($actualWorkDays, 2);

        return [
            'employee_code' => $employee->code,
            'full_name' => $employee->full_name,
            'department' => $employee->department?->name,
            'standard_work_days' => $standardWorkDays,
            'cells' => $cells,
            'actual_work_days' => $actualWorkDays,
            'paid_leave_days' => $paidLeaveDays,
            'unpaid_leave_days' => $unpaidLeaveDays,
            'holiday_days' => $counts['holiday'],
            'absent_days' => $counts['absent'],
            'pending_days' => $counts['pending'],
            // Giờ OT được trả lương (đã duyệt bản ghi + duyệt OT + đạt ngưỡng), mọi loại OT.
            'overtime_hours' => round($attendances
                ->filter(fn (Attendance $a) => $a->approval_status === Attendance::APPROVAL_APPROVED
                    && $a->overtime_approved
                    && $a->overtime_minutes >= self::OVERTIME_MINIMUM_PAYABLE_MINUTES)
                ->sum('overtime_minutes') / 60, 2),
            'paid_days' => round(min($standardWorkDays, $actualWorkDays + $paidLeaveDays), 2),
        ];
    }

    // Công của 1 ngày = tổng các ca đã duyệt, ca bị xóa/thiếu phút chuẩn bỏ qua
    // (y hệt PayrollService::calculateWorkedMetrics()).
    private function creditFor(Collection $dayAttendances): float
    {
        return round((float) $dayAttendances
            ->filter(fn (Attendance $a) => $a->approval_status === Attendance::APPROVAL_APPROVED && $a->workShift?->standard_work_minutes)
            ->sum(fn (Attendance $a) => $this->workTimeCalculationService->dayEquivalentFor($a, $a->workShift)), 2);
    }

    private function markFor(array $day, Collection $dayAttendances, ?string $leaveMark, ?string $hireDate, string $today, bool $isIntern = false): string
    {
        if ($hireDate !== null && $day['date'] < $hireDate) {
            return '';
        }
        // Ca OT ngày khác (T7/CN/lễ): không có công ngày, chỉ có giờ OT.
        if ($dayAttendances->contains(fn (Attendance $a) => $a->workShift?->is_overtime && $a->first_check_in_at && $a->approval_status !== Attendance::APPROVAL_REJECTED)) {
            return self::MARK_OVERTIME;
        }
        if ($day['type'] === 'holiday') {
            return $isIntern && ! $day['intern_paid'] ? self::MARK_UNPAID_HOLIDAY : self::MARK_HOLIDAY;
        }
        if ($leaveMark !== null && $day['type'] !== 'weekend') {
            return $leaveMark;
        }
        // Có chấm vào mà chưa ra công: chờ duyệt, hoặc đã duyệt giờ vào nhưng chưa chấm ra.
        if ($dayAttendances->contains(fn (Attendance $a) => $a->first_check_in_at && $a->approval_status !== Attendance::APPROVAL_REJECTED)) {
            return self::MARK_PENDING;
        }
        if ($dayAttendances->contains(fn (Attendance $a) => $a->approval_status === Attendance::APPROVAL_REJECTED)) {
            return self::MARK_REJECTED;
        }
        if (in_array($day['type'], ['workday', 'makeup'], true) && $day['date'] <= $today) {
            return self::MARK_ABSENT;
        }

        return '';
    }

    // ["Y-m-d" => P|KL] — đơn nghỉ theo buổi/cả ngày (bỏ đơn 'hourly', cùng quy
    // ước với AttendanceService::approvedLeaveDatesForEmployee()).
    private function leaveMarksByDate(Collection $leaves): Collection
    {
        $marks = collect();

        foreach ($leaves->where('start_session', '!=', 'hourly') as $leave) {
            $mark = $leave->leaveType?->is_paid ? self::MARK_PAID_LEAVE : self::MARK_UNPAID_LEAVE;

            foreach (CarbonPeriod::create($leave->from_date, $leave->to_date) as $date) {
                $marks->put($date->toDateString(), $mark);
            }
        }

        return $marks;
    }
}
