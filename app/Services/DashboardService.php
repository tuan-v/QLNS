<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly LeaveRequestService $leaveRequestService,
    ) {
    }

    /**
     * Nội dung Dashboard theo ĐÚNG quyền của $user — không phải 1 dashboard
     * chung cho mọi người: có "employee.view" (HR/Manager/Admin) thấy số
     * liệu TOÀN CÔNG TY, không có (Employee thường) chỉ thấy đúng thông tin
     * CỦA CHÍNH MÌNH — tránh lộ số liệu công ty (tổng nhân viên, ai đang
     * chấm công...) cho người không có quyền xem, cùng tinh thần các chỗ ẩn
     * dữ liệu nhạy cảm khác trong dự án (EmployeeResource, ...).
     */
    public function forUser(User $user): array
    {
        if ($user->hasPermission('employee.view')) {
            return ['scope' => 'company'] + $this->companyWide();
        }

        return ['scope' => 'personal'] + $this->personal($user);
    }

    private function companyWide(): array
    {
        $today = now()->toDateString();

        $employeeCounts = Employee::query()
            ->selectRaw("count(*) as total, sum(case when employment_status = 'active' then 1 else 0 end) as active")
            ->first();

        $newThisMonth = Employee::whereMonth('hire_date', now()->month)
            ->whereYear('hire_date', now()->year)
            ->count();

        $todaySummary = $this->attendanceService->dailyOverview($today, null, null, null, null)['summary'];
        $present = $todaySummary['completed'] + $todaySummary['pending'] + $todaySummary['needs_review'];
        $totalToday = max($todaySummary['total'], 1);

        return [
            'employee_stats' => [
                'total' => (int) $employeeCounts->total,
                'active' => (int) $employeeCounts->active,
                'new_this_month' => $newThisMonth,
            ],
            'attendance_today' => [
                'present' => $present,
                'total' => $todaySummary['total'],
                'rate' => round($present / $totalToday * 100, 1),
            ],
            'leave_pending' => $this->leavePendingCounts(),
            'attendance_trend' => $this->attendanceTrend(),
            'department_distribution' => $this->departmentDistribution(),
        ];
    }

    // "pending" thô gồm CẢ 2 trường hợp (xem LeaveApprovalService::decide()):
    // nhân viên có quản lý (chờ cấp 1) và không có quản lý (bỏ qua thẳng
    // xuống cấp 2/HR) — tách đúng y hệt logic đó để số đếm khớp thật, không
    // suy đoán riêng.
    private function leavePendingCounts(): array
    {
        $pendingManager = LeaveRequest::where('status', 'pending')
            ->whereHas('employee', fn ($q) => $q->whereNotNull('manager_id'))
            ->count();

        $pendingHr = LeaveRequest::where('status', 'manager_approved')->count()
            + LeaveRequest::where('status', 'pending')
                ->whereHas('employee', fn ($q) => $q->whereNull('manager_id'))
                ->count();

        return [
            'pending_manager' => $pendingManager,
            'pending_hr' => $pendingHr,
            'total' => $pendingManager + $pendingHr,
        ];
    }

    // Tỷ lệ đi làm theo NGÀY LÀM VIỆC gần nhất (bỏ T7/CN, tối đa 5 điểm) —
    // dùng mẫu số CỐ ĐỊNH là tổng nhân viên active/probation HIỆN TẠI (không
    // gọi dailyOverview() cho từng ngày — quá nặng cho 1 widget tổng quan,
    // chấp nhận xấp xỉ vì đây chỉ là biểu đồ xu hướng tham khảo).
    private function attendanceTrend(): array
    {
        $totalActive = Employee::whereIn('employment_status', ['active', 'probation'])->count();
        $totalActive = max($totalActive, 1);

        $days = [];
        $cursor = now()->copy();
        while (count($days) < 5) {
            if (! $cursor->isWeekend()) {
                $days[] = $cursor->toDateString();
            }
            $cursor->subDay();
        }
        $days = array_reverse($days);

        $presentCounts = Attendance::whereIn('attendance_date', $days)
            ->whereIn('status', ['completed', 'pending', 'needs_review'])
            ->select('attendance_date', DB::raw('count(distinct employee_id) as cnt'))
            ->groupBy('attendance_date')
            ->pluck('cnt', 'attendance_date');

        $labels = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

        return collect($days)->map(function (string $date) use ($presentCounts, $totalActive, $labels) {
            $present = (int) ($presentCounts[$date] ?? 0);

            return [
                'date' => $date,
                'label' => $labels[Carbon::parse($date)->dayOfWeekIso - 1],
                'is_today' => $date === now()->toDateString(),
                'rate' => round($present / $totalActive * 100, 1),
            ];
        })->values()->all();
    }

    private function departmentDistribution(): array
    {
        $counts = Employee::query()
            ->whereNotNull('department_id')
            ->select('department_id', DB::raw('count(*) as cnt'))
            ->groupBy('department_id')
            ->orderByDesc('cnt')
            ->get();

        $names = Department::whereIn('id', $counts->pluck('department_id'))->pluck('name', 'id');

        return $counts->map(fn ($row) => [
            'name' => $names[$row->department_id] ?? 'Khác',
            'count' => (int) $row->cnt,
        ])->values()->all();
    }

    private function personal(User $user): array
    {
        $employee = $user->employee;

        if ($employee === null) {
            return [
                'leave_balance' => null,
                'checked_in_today' => false,
                'my_pending_leave_count' => 0,
            ];
        }

        $balances = $this->leaveRequestService->listBalancesForEmployee($employee);
        $annualBalance = $balances->first(fn ($b) => $b['leave_type']->annual_entitlement_days > 0);

        $todayAttendance = Attendance::where('employee_id', $employee->id)
            ->where('attendance_date', now()->toDateString())
            ->first();

        $myPending = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'manager_approved'])
            ->count();

        return [
            'leave_balance' => $annualBalance ? [
                'allocated_days' => $annualBalance['allocated_days'],
                'remaining_days' => $annualBalance['remaining_days'],
            ] : null,
            'checked_in_today' => $todayAttendance?->first_check_in_at !== null,
            'checked_in_at' => $todayAttendance?->first_check_in_at,
            'checked_out_at' => $todayAttendance?->last_check_out_at,
            'my_pending_leave_count' => $myPending,
        ];
    }
}
