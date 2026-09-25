<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceAdjustment;
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
        private readonly PayrollService $payrollService,
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
    // $date: theo yêu cầu người dùng (2026-09-25) — CHỈ 3 widget "Tỷ lệ đi
    // làm"/"Xu hướng chấm công"/"Phân bổ phòng ban" lọc theo ngày này (mặc
    // định hôm nay nếu không truyền); `employee_stats`, `leave_pending`,
    // `action_items` CỐ TÌNH giữ nguyên luôn tính theo HIỆN TẠI bất kể $date
    // — đặc biệt `action_items` ("Cần bạn xử lý") đúng bản chất là việc còn
    // TỒN ĐỌNG tính tới bây giờ, giới hạn theo 1 ngày sẽ làm sai lệch ý
    // nghĩa "cần xử lý" (dễ hiểu lầm "không còn gì" nếu quên đổi bộ lọc).
    public function forUser(User $user, ?string $date = null): array
    {
        $date ??= now()->toDateString();

        if ($user->hasPermission('employee.view')) {
            return ['scope' => 'company'] + $this->companyWide($user, $date);
        }

        return ['scope' => 'personal'] + $this->personal($user);
    }

    private function companyWide(User $user, string $date): array
    {
        $employeeCounts = Employee::query()
            ->selectRaw("count(*) as total, sum(case when employment_status = 'active' then 1 else 0 end) as active")
            ->first();

        $newThisMonth = Employee::whereMonth('hire_date', now()->month)
            ->whereYear('hire_date', now()->year)
            ->count();

        $dateSummary = $this->attendanceService->dailyOverview($date, null, null, null, null)['summary'];
        $present = $dateSummary['completed'] + $dateSummary['pending'] + $dateSummary['needs_review'];
        $totalForDate = max($dateSummary['total'], 1);

        return [
            'date' => $date,
            'employee_stats' => [
                'total' => (int) $employeeCounts->total,
                'active' => (int) $employeeCounts->active,
                'new_this_month' => $newThisMonth,
            ],
            'attendance_today' => [
                'present' => $present,
                'total' => $dateSummary['total'],
                'rate' => round($present / $totalForDate * 100, 1),
            ],
            'leave_pending' => $this->leavePendingCounts(),
            'attendance_trend' => $this->attendanceTrend($date),
            'department_distribution' => $this->departmentDistribution($date),
            'action_items' => $this->actionItems($user),
        ];
    }

    // "Cần bạn xử lý" (2026-09-25, theo yêu cầu người dùng — demo cho ý
    // tưởng gộp mọi việc chờ duyệt vào 1 khối trên Dashboard, khớp Ke-hoach
    // Ngày 59 "Tích hợp thông báo nhanh trên Dashboard... đơn phép chờ
    // duyệt", mở rộng thêm 2 loại "chờ duyệt" khác đã có sẵn trong dự án).
    // MỖI mục lọc theo ĐÚNG quyền của $user — HR/Admin thấy cả 3, Manager
    // (không có attendance.approve/attendance.adjust) chỉ thấy mục nghỉ
    // phép — tránh hiện số đếm cho việc họ không có quyền vào duyệt.
    // Mục nào count=0 thì ẩn hẳn (không hiện dòng "0 việc cần làm" vô nghĩa).
    private function actionItems(User $user): array
    {
        $items = [];

        if ($user->hasPermission('attendance.approve')) {
            $pendingQuery = Attendance::where('approval_status', Attendance::APPROVAL_PENDING)
                ->whereNotNull('first_check_in_at');
            $count = (clone $pendingQuery)->count();

            if ($count > 0) {
                // "Tổng hợp chấm công" (AttendanceOverview.vue) chỉ xem được
                // ĐÚNG 1 NGÀY tại 1 thời điểm (mặc định hôm nay) — khác đếm ở
                // đây (không giới hạn ngày). Không kèm ngày sớm nhất còn tồn
                // đọng thì bấm vào mục này chỉ đưa tới trang đó ở ngày HÔM
                // NAY, có thể KHÔNG thấy được bản ghi cần xử lý (bug thật đã
                // gặp: chờ duyệt từ 2 ngày trước, trang mặc định hôm nay lại
                // hiện "Vắng" vì hôm nay không có bản ghi nào).
                // value() vẫn đi qua cast 'date:Y-m-d' của Attendance (trả về
                // Carbon, không phải chuỗi thô) — PHẢI tự ép ->toDateString(),
                // nếu không JSON serialize mặc định của Carbon sẽ đổi sang
                // giờ UTC rồi lệch qua NGÀY HÔM TRƯỚC (giờ VN +7, y hệt bẫy
                // timezone đã gặp ở LeaveRequest/nơi khác trong dự án).
                $earliestDate = (clone $pendingQuery)->orderBy('attendance_date')->value('attendance_date')?->toDateString();

                $items[] = [
                    'type' => 'attendance_approval',
                    'label' => 'Chấm công chờ duyệt',
                    'count' => $count,
                    'route' => 'attendance-overview',
                    'route_query' => ['date' => $earliestDate],
                ];
            }
        }

        if ($user->hasPermission('leave.approve_manager') || $user->hasPermission('leave.approve_hr')) {
            $leavePending = $this->leavePendingCounts();
            $count = ($user->hasPermission('leave.approve_manager') ? $leavePending['pending_manager'] : 0)
                + ($user->hasPermission('leave.approve_hr') ? $leavePending['pending_hr'] : 0);

            if ($count > 0) {
                $items[] = [
                    'type' => 'leave_approval',
                    'label' => 'Đơn nghỉ phép chờ duyệt',
                    'count' => $count,
                    'route' => 'leave-management',
                ];
            }
        }

        if ($user->hasPermission('attendance.adjust')) {
            $count = AttendanceAdjustment::where('status', 'pending')->count();

            if ($count > 0) {
                $items[] = [
                    'type' => 'attendance_adjustment',
                    'label' => 'Điều chỉnh công chờ duyệt',
                    'count' => $count,
                    'route' => 'attendance-adjustments',
                ];
            }
        }

        return $items;
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

    // Tỷ lệ đi làm theo 5 NGÀY LÀM VIỆC gần nhất TÍNH TỚI $endDate (bỏ T7/CN)
    // — $endDate mặc định hôm nay, đổi được qua bộ lọc ngày trên Dashboard
    // (2026-09-25, theo yêu cầu người dùng). Mẫu số vẫn dùng CỐ ĐỊNH tổng
    // nhân viên active/probation HIỆN TẠI (không truy vấn lại theo từng ngày
    // trong quá khứ — quá nặng cho 1 widget tổng quan, chấp nhận xấp xỉ vì
    // đây chỉ là biểu đồ xu hướng tham khảo, giống lý do đã chấp nhận trước
    // khi có bộ lọc ngày).
    private function attendanceTrend(string $endDate): array
    {
        $totalActive = Employee::whereIn('employment_status', ['active', 'probation'])->count();
        $totalActive = max($totalActive, 1);

        $days = [];
        $cursor = Carbon::parse($endDate);
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

        return collect($days)->map(function (string $date) use ($presentCounts, $totalActive, $labels, $endDate) {
            $present = (int) ($presentCounts[$date] ?? 0);

            return [
                'date' => $date,
                'label' => $labels[Carbon::parse($date)->dayOfWeekIso - 1],
                'is_today' => $date === $endDate,
                'rate' => round($present / $totalActive * 100, 1),
            ];
        })->values()->all();
    }

    // "Phân bổ theo phòng ban" TÍNH TỚI $date: chỉ đếm nhân viên ĐÃ vào làm
    // (hire_date <= $date) và CHƯA nghỉ việc tại thời điểm đó (termination_date
    // null hoặc >= $date) — xấp xỉ hợp lý cho "as of ngày đó", KHÔNG dựng lại
    // lịch sử điều chuyển phòng ban thật (EmployeeTransfer) vì quá phức tạp
    // cho 1 widget tổng quan — vẫn dùng department_id HIỆN TẠI của nhân viên,
    // nên nhân viên từng điều chuyển qua nhiều phòng ban sẽ hiện ở phòng ban
    // MỚI NHẤT dù đang xem ngày trước khi họ điều chuyển.
    private function departmentDistribution(string $date): array
    {
        $counts = Employee::query()
            ->whereNotNull('department_id')
            ->where('hire_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $date))
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

    // 2026-09-25, theo yêu cầu người dùng (kèm mockup cụ thể) — mở rộng
    // Dashboard cá nhân từ 3 thẻ số liệu + thông báo (bản trước đó) thành đủ
    // các mảng: lịch làm việc tuần này, công tháng này, phép còn lại, lương
    // tháng gần nhất, đơn của tôi. KHÔNG kèm "hôm nay đã chấm công chưa" dạng
    // nút bấm chấm công ngay tại Dashboard — Frontend tái dùng THẲNG
    // `useCheckIn()` (đã có sẵn ở trang Chấm công tự phục vụ) cho việc đó
    // thay vì lặp lại logic GPS/IP/duyệt ở đây, nên API này KHÔNG cần trả
    // thêm "today_shifts" — chỉ còn giữ `checked_in_today`/`checked_in_at`/
    // `checked_out_at` cho thẻ trạng thái tóm tắt (không phải nút bấm).
    private function personal(User $user): array
    {
        $employee = $user->employee;

        if ($employee === null) {
            return [
                'employee_name' => $user->user_name,
                'leave_balance' => null,
                'checked_in_today' => false,
                'checked_in_at' => null,
                'checked_out_at' => null,
                'my_pending_leave_count' => 0,
                'week_schedule' => [],
                'monthly_work' => null,
                'recent_attendance' => [],
                'recent_requests' => [],
                'latest_payslip' => null,
            ];
        }

        $balances = $this->leaveRequestService->listBalancesForEmployee($employee);
        $annualBalance = $balances->first(fn ($b) => $b['leave_type']->annual_entitlement_days > 0);

        $now = now();

        $todayAttendance = Attendance::where('employee_id', $employee->id)
            ->where('attendance_date', $now->toDateString())
            ->first();

        $myPending = LeaveRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'manager_approved'])
            ->count();

        return [
            'employee_name' => $employee->full_name,
            'leave_balance' => $annualBalance ? [
                'allocated_days' => $annualBalance['allocated_days'],
                'remaining_days' => $annualBalance['remaining_days'],
            ] : null,
            'checked_in_today' => $todayAttendance?->first_check_in_at !== null,
            'checked_in_at' => $todayAttendance?->first_check_in_at,
            'checked_out_at' => $todayAttendance?->last_check_out_at,
            'my_pending_leave_count' => $myPending,
            'week_schedule' => $this->weekSchedule($employee, $now),
            ...$this->monthlyWorkAndRecentAttendance($employee, $now),
            'recent_requests' => $this->recentRequests($employee),
            'latest_payslip' => $this->latestPayslip($employee),
        ];
    }

    // "Lịch làm việc tuần này" (T2-CN) — tái dùng thẳng
    // AttendanceService::listActiveAssignmentsForDate() (chính là hàm
    // listTodayStatusForEmployee()/history() đang dùng để suy ra "ca nào áp
    // dụng đúng ngày này" — không viết lại công thức riêng).
    private function weekSchedule(Employee $employee, Carbon $now): array
    {
        $weekStart = $now->copy()->startOfWeek(Carbon::MONDAY);
        $labels = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);

            $days[] = [
                'date' => $date->toDateString(),
                'label' => $labels[$i],
                'is_today' => $date->isSameDay($now),
                'shifts' => $this->attendanceService->listActiveAssignmentsForDate($employee, $date)
                    ->map(fn ($assignment) => [
                        'name' => $assignment->workShift->name,
                        'start_time' => $assignment->workShift->start_time,
                        'end_time' => $assignment->workShift->end_time,
                    ])
                    ->values()
                    ->all(),
            ];
        }

        return $days;
    }

    // "Công tháng này" (X/Y ngày) + "Bảng công gần nhất" — DÙNG CHUNG đúng 1
    // lần gọi AttendanceService::history() (mục 18, đã tính ngày công qua
    // WorkTimeCalculationService — CÙNG công thức PayrollService dùng để trả
    // lương, không tính lại riêng 1 công thức khác dễ lệch số). `rows` đã
    // được history() sắp xếp NGÀY GIẢM DẦN sẵn, nên lấy 5 dòng ĐẦU là 5 ngày
    // gần nhất.
    private function monthlyWorkAndRecentAttendance(Employee $employee, Carbon $now): array
    {
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $history = $this->attendanceService->history(
            $employee,
            $monthStart->toDateString(),
            $now->toDateString(),
            null,
            null,
        );

        return [
            'monthly_work' => [
                'actual_days' => $history['summary']['total_work_days'],
                'standard_days' => $this->payrollService->standardWorkDaysFor($monthStart, $monthEnd),
            ],
            'recent_attendance' => collect($history['rows'])->take(5)->map(fn (array $row) => [
                'date' => $row['date'],
                'work_shift_name' => $row['work_shift']->name,
                'status' => $row['status'],
                'check_in_at' => $row['attendance']?->first_check_in_at,
                'check_out_at' => $row['attendance']?->last_check_out_at,
            ])->values()->all(),
        ];
    }

    // "Đơn của tôi" — gộp Nghỉ phép + Điều chỉnh công/OT (2 nguồn KHÁC bảng,
    // không có sẵn 1 API chung — gộp ở đây, lấy 5 gần nhất mỗi loại trước khi
    // trộn để không bỏ sót loại nào chỉ vì loại kia có nhiều bản ghi hơn).
    private function recentRequests(Employee $employee): array
    {
        $leaveRequests = LeaveRequest::where('employee_id', $employee->id)
            ->with('leaveType')
            ->latest('id')
            ->take(5)
            ->get()
            ->map(fn (LeaveRequest $lr) => [
                'kind' => 'leave',
                'label' => $lr->leaveType->name,
                'date' => $lr->from_date->toDateString(),
                'status' => $lr->status,
            ]);

        $adjustments = AttendanceAdjustment::where('employee_id', $employee->id)
            ->latest('id')
            ->take(5)
            ->get()
            ->map(fn (AttendanceAdjustment $adjustment) => [
                'kind' => 'adjustment',
                'adjustment_type' => $adjustment->type,
                'date' => $adjustment->attendance_date->toDateString(),
                'status' => $adjustment->status,
            ]);

        return $leaveRequests->concat($adjustments)
            ->sortByDesc('date')
            ->take(5)
            ->values()
            ->all();
    }

    // "Lương tháng này" — thật ra là kỳ lương ĐÃ CHỐT gần nhất (kỳ tháng hiện
    // tại thường CHƯA tồn tại, chỉ tự sinh khi HR chốt lương — xem
    // PayrollService::generateForPeriod(), thường chạy đầu tháng SAU). Tái
    // dùng thẳng listPayslipsForEmployee() (đã lọc closed/paid, sắp mới nhất
    // trước) — không tạo truy vấn riêng.
    private function latestPayslip(Employee $employee): ?array
    {
        $latest = $this->payrollService->listPayslipsForEmployee($employee)->first();

        if ($latest === null) {
            return null;
        }

        return [
            'id' => $latest->id,
            'period_month' => $latest->payroll->period_month,
            'period_year' => $latest->payroll->period_year,
            'net_salary' => $latest->net_salary,
        ];
    }
}
