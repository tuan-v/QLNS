<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PayrollDetail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

// Module Báo cáo (Kế hoạch Ngày 51-58; tài liệu yêu cầu "API tổng hợp số liệu nhân sự, biến
// động nhân sự, tỷ lệ đi làm/nghỉ phép, chi phí lương"). Quyết định người dùng 2026-10-16:
//  - Chi phí lương = tổng thu nhập (gross) + bảo hiểm công ty đóng (21,5% lương đóng BH).
//  - HR/Admin xem cả công ty; Manager chỉ phòng mình quản lý (+ phòng con), KHÔNG xem lương
//    (chi phí lương cần quyền payroll.view_all).
// Kết quả cache Redis 10 phút (Ngày 53) — tham số "fresh" bỏ qua cache.
class ReportService
{
    // Phần bảo hiểm doanh nghiệp đóng: BHXH 17,5% + BHYT 3% + BHTN 1%.
    public const EMPLOYER_INSURANCE_RATE = 0.215;

    // Phần người lao động đóng (khớp PayrollService::INSURANCE_RATE) — để suy ra lương đóng BH.
    private const EMPLOYEE_INSURANCE_RATE = 0.105;

    private const CACHE_SECONDS = 600;

    private const NO_DEPARTMENT = 'Chưa có phòng ban';

    public function __construct(
        private readonly AttendanceSheetService $attendanceSheetService,
        private readonly DepartmentScopeService $departmentScope,
    ) {
    }

    // ------------------------------------------------------------------ phạm vi

    public function canViewCompany(User $user): bool
    {
        return $this->departmentScope->canViewCompany($user);
    }

    /**
     * Phòng ban người dùng được xem: null = cả công ty; mảng id = phòng mình quản lý + phòng con.
     *
     * @return array<int, int>|null
     */
    public function allowedDepartmentIds(User $user): ?array
    {
        return $this->departmentScope->allowedDepartmentIds($user);
    }

    /** Danh sách phòng ban cho bộ lọc (theo phạm vi). */
    public function filters(User $user): array
    {
        $allowed = $this->allowedDepartmentIds($user);

        return [
            'scope' => $allowed === null ? 'company' : 'departments',
            'departments' => Department::query()
                ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id'])
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'parent_id' => $d->parent_id])
                ->values()
                ->all(),
            'can_view_payroll' => $user->hasPermission('payroll.view_all'),
        ];
    }

    /**
     * Phòng ban áp dụng cho 1 lần xem báo cáo: giao giữa phạm vi được phép và phòng đang lọc
     * (kèm phòng con). null = không giới hạn.
     *
     * @return array<int, int>|null
     */
    public function resolveDepartments(User $user, ?int $departmentId): ?array
    {
        $allowed = $this->allowedDepartmentIds($user);
        $filter = $departmentId ? $this->withDescendants([$departmentId]) : null;

        if ($allowed === null) {
            return $filter;
        }

        return $filter === null ? $allowed : array_values(array_intersect($allowed, $filter));
    }

    // ------------------------------------------------------------------ báo cáo

    /** Biến động nhân sự (Ngày 51): số nhân viên cuối kỳ, tuyển mới, nghỉ việc, tỷ lệ nghỉ việc. */
    public function workforce(array $params, ?array $departmentIds): array
    {
        return $this->cached('workforce', $params, $departmentIds, function () use ($params, $departmentIds) {
            $employees = $this->employeeQuery($departmentIds)->get(['id', 'department_id', 'hire_date', 'termination_date', 'employment_status']);

            $months = $this->months($params)->map(function (array $m) use ($employees) {
                $start = $m['start']->toDateString();
                $end = $m['end']->toDateString();

                return $m + [
                    'headcount_start' => $employees->filter(fn ($e) => $this->activeOn($e, $m['start']->copy()->subDay()->toDateString()))->count(),
                    'headcount' => $employees->filter(fn ($e) => $this->activeOn($e, $end))->count(),
                    'hires' => $employees->filter(fn ($e) => $e->hire_date && $e->hire_date->toDateString() >= $start && $e->hire_date->toDateString() <= $end)->count(),
                    'leavers' => $employees->filter(fn ($e) => $e->termination_date && $e->termination_date->toDateString() >= $start && $e->termination_date->toDateString() <= $end)->count(),
                ];
            });

            $periods = $this->group($months, $params['group'] ?? 'month')->map(function (Collection $items, string $key) {
                $first = $items->first();
                $last = $items->last();
                $leavers = $items->sum('leavers');
                $average = ($first['headcount_start'] + $last['headcount']) / 2;

                return [
                    'key' => $key,
                    'label' => $this->periodLabel($key),
                    'headcount' => $last['headcount'],
                    'hires' => $items->sum('hires'),
                    'leavers' => $leavers,
                    'turnover_rate' => $average > 0 ? round($leavers / $average * 100, 1) : 0.0,
                ];
            })->values();

            $today = now()->toDateString();
            $current = $employees->filter(fn ($e) => $this->activeOn($e, $today));
            $names = Department::pluck('name', 'id');
            $totalLeavers = $periods->sum('leavers');
            $firstStart = $months->first()['headcount_start'] ?? 0;
            $lastEnd = $months->last()['headcount'] ?? 0;
            $average = ($firstStart + $lastEnd) / 2;

            return [
                'periods' => $periods->all(),
                'totals' => [
                    'headcount' => $current->count(),
                    'hires' => $periods->sum('hires'),
                    'leavers' => $totalLeavers,
                    'turnover_rate' => $average > 0 ? round($totalLeavers / $average * 100, 1) : 0.0,
                ],
                'by_department' => $current->groupBy(fn ($employee) => $this->departmentKey($employee->department_id, $names))
                    ->map(fn ($group, $id) => ['department' => $names[$id] ?? self::NO_DEPARTMENT, 'headcount' => $group->count()])
                    ->sortByDesc('headcount')->values()->all(),
                'by_status' => $current->groupBy('employment_status')
                    ->map(fn ($group, $status) => ['status' => $status, 'count' => $group->count()])
                    ->sortByDesc('count')->values()->all(),
            ];
        }, $params['fresh'] ?? false);
    }

    /**
     * Chấm công & nghỉ phép (Ngày 52): dùng ĐÚNG công thức bảng chấm công tháng
     * (AttendanceSheetService) — mẫu số là số ngày "lẽ ra phải đi làm" = có công + nghỉ phép
     * (có/không lương) + vắng; bỏ ngày lễ, ngày trước khi vào làm, ngày chưa tới.
     */
    public function attendance(array $params, ?array $departmentIds): array
    {
        return $this->cached('attendance', $params, $departmentIds, function () use ($params, $departmentIds) {
            $employees = $this->employeeQuery($departmentIds)->with('department')->get();
            $byDepartment = [];

            $months = $this->months($params)->map(function (array $m) use ($employees, &$byDepartment) {
                $active = $employees->filter(fn ($e) => $this->activeDuring($e, $m['start'], $m['end']))->values();
                $rows = $active->isEmpty() ? collect() : $this->attendanceSheetService->rowsForEmployees($active, $m['start']->year, $m['start']->month);

                $checkins = Attendance::whereIn('employee_id', $active->pluck('id'))
                    ->whereBetween('attendance_date', [$m['start']->toDateString(), $m['end']->toDateString()])
                    ->whereNotNull('first_check_in_at')
                    ->selectRaw('employee_id, count(*) as total, sum(case when late_minutes > 0 then 1 else 0 end) as late')
                    ->groupBy('employee_id')
                    ->get()
                    ->keyBy('employee_id');

                foreach ($rows as $row) {
                    $dept = $row['department_id'] ?? 0;
                    $byDepartment[$dept] ??= ['work' => 0, 'paid' => 0, 'unpaid' => 0, 'absent' => 0, 'checkins' => 0, 'late' => 0];
                    $byDepartment[$dept]['work'] += $row['actual_work_days'];
                    $byDepartment[$dept]['paid'] += $row['paid_leave_days'];
                    $byDepartment[$dept]['unpaid'] += $row['unpaid_leave_days'];
                    $byDepartment[$dept]['absent'] += $row['absent_days'];
                    $byDepartment[$dept]['checkins'] += (int) ($checkins[$row['employee_id']]->total ?? 0);
                    $byDepartment[$dept]['late'] += (int) ($checkins[$row['employee_id']]->late ?? 0);
                }

                return $m + [
                    'work' => $rows->sum('actual_work_days'),
                    'paid' => $rows->sum('paid_leave_days'),
                    'unpaid' => $rows->sum('unpaid_leave_days'),
                    'absent' => $rows->sum('absent_days'),
                    'checkins' => (int) $checkins->sum('total'),
                    'late' => (int) $checkins->sum('late'),
                ];
            });

            $periods = $this->group($months, $params['group'] ?? 'month')
                ->map(fn (Collection $items, string $key) => ['key' => $key, 'label' => $this->periodLabel($key)] + $this->attendanceRates([
                    'work' => $items->sum('work'),
                    'paid' => $items->sum('paid'),
                    'unpaid' => $items->sum('unpaid'),
                    'absent' => $items->sum('absent'),
                    'checkins' => $items->sum('checkins'),
                    'late' => $items->sum('late'),
                ]))->values();

            $names = Department::pluck('name', 'id');

            return [
                'periods' => $periods->all(),
                'totals' => $this->attendanceRates([
                    'work' => $months->sum('work'),
                    'paid' => $months->sum('paid'),
                    'unpaid' => $months->sum('unpaid'),
                    'absent' => $months->sum('absent'),
                    'checkins' => $months->sum('checkins'),
                    'late' => $months->sum('late'),
                ]),
                'by_department' => collect($byDepartment)
                    ->groupBy(fn ($sums, $id) => $this->departmentKey($id, $names), true)
                    ->map(fn (Collection $group, $id) => ['department' => $names[$id] ?? self::NO_DEPARTMENT] + $this->attendanceRates(
                        collect(['work', 'paid', 'unpaid', 'absent', 'checkins', 'late'])->mapWithKeys(fn ($field) => [$field => $group->sum($field)])->all()
                    ))
                    ->sortBy('department')->values()->all(),
            ];
        }, $params['fresh'] ?? false);
    }

    /** Chi phí lương theo phòng ban (Ngày 52, 58): gross + BH công ty đóng. */
    public function payrollCost(array $params, ?array $departmentIds): array
    {
        return $this->cached('payroll', $params, $departmentIds, function () use ($params, $departmentIds) {
            $months = $this->months($params);
            $first = $months->first()['start'];
            $last = $months->last()['start'];

            $details = PayrollDetail::query()
                ->join('payrolls', 'payrolls.id', '=', 'payroll_details.payroll_id')
                ->join('employees', 'employees.id', '=', 'payroll_details.employee_id')
                ->whereRaw('(payrolls.period_year * 100 + payrolls.period_month) between ? and ?', [$first->year * 100 + $first->month, $last->year * 100 + $last->month])
                ->when($departmentIds !== null, fn ($q) => $q->whereIn('employees.department_id', $departmentIds))
                ->get([
                    'payrolls.period_year',
                    'payrolls.period_month',
                    'payrolls.status',
                    'employees.department_id',
                    'payroll_details.gross_salary',
                    'payroll_details.insurance_amount',
                    'payroll_details.net_salary',
                ])
                ->map(fn ($d) => [
                    'month' => sprintf('%04d-%02d', $d->period_year, $d->period_month),
                    'status' => $d->status,
                    'department_id' => $d->department_id,
                    'gross' => (float) $d->gross_salary,
                    'net' => (float) $d->net_salary,
                    // Lương đóng BH suy ra từ phần NV đóng (chỉ khi tháng đó thuộc diện đóng BH).
                    'employer_insurance' => round((float) $d->insurance_amount / self::EMPLOYEE_INSURANCE_RATE * self::EMPLOYER_INSURANCE_RATE, 2),
                ]);

            $sum = fn (Collection $rows) => [
                'employees' => $rows->count(),
                'gross' => round($rows->sum('gross'), 2),
                'employer_insurance' => round($rows->sum('employer_insurance'), 2),
                'total_cost' => round($rows->sum('gross') + $rows->sum('employer_insurance'), 2),
                'net' => round($rows->sum('net'), 2),
            ];

            $byMonth = $details->groupBy('month');
            $monthRows = $months->map(fn (array $m) => $m + ['rows' => $byMonth->get($m['key'], collect())]);
            $periods = $this->group($monthRows, $params['group'] ?? 'month')
                ->map(function (Collection $items, string $key) use ($sum) {
                    $rows = $items->flatMap(fn ($m) => $m['rows']);
                    $draft = $rows->contains(fn ($r) => $r['status'] === 'processing');

                    return ['key' => $key, 'label' => $this->periodLabel($key), 'has_draft' => $draft] + $sum($rows);
                })->values();

            $names = Department::pluck('name', 'id');

            return [
                'periods' => $periods->all(),
                'totals' => $sum($details),
                'by_department' => $details->groupBy(fn ($row) => $this->departmentKey($row['department_id'], $names))
                    ->map(fn ($rows, $id) => ['department' => $names[$id] ?? self::NO_DEPARTMENT] + $sum($rows))
                    ->sortByDesc('total_cost')->values()->all(),
                'employer_insurance_rate' => self::EMPLOYER_INSURANCE_RATE,
            ];
        }, $params['fresh'] ?? false);
    }

    // ------------------------------------------------------------------ tiện ích

    private function attendanceRates(array $s): array
    {
        $expected = $s['work'] + $s['paid'] + $s['unpaid'] + $s['absent'];
        $pct = fn ($value, $base) => $base > 0 ? round($value / $base * 100, 1) : 0.0;

        return [
            'work_days' => round($s['work'], 2),
            'paid_leave_days' => round($s['paid'], 2),
            'unpaid_leave_days' => round($s['unpaid'], 2),
            'absent_days' => round($s['absent'], 2),
            'attendance_rate' => $pct($s['work'], $expected),
            'leave_rate' => $pct($s['paid'] + $s['unpaid'], $expected),
            'absence_rate' => $pct($s['absent'], $expected),
            'checkins' => (int) $s['checkins'],
            'late_count' => (int) $s['late'],
            'late_rate' => $pct($s['late'], $s['checkins']),
        ];
    }

    /**
     * Khóa gom nhóm theo phòng ban: phòng đã xóa hoặc không có phòng -> 0 ("Chưa có phòng ban"). Trước đây gom theo
     * mã phòng nên mỗi phòng đã xóa thành 1 miếng riêng cùng tên "Chưa có phòng ban" trên biểu đồ (2026-10-22).
     */
    private function departmentKey($departmentId, Collection $names): int
    {
        return $departmentId !== null && $names->has($departmentId) ? (int) $departmentId : 0;
    }

    private function employeeQuery(?array $departmentIds)
    {
        return Employee::query()->when($departmentIds !== null, fn ($q) => $q->whereIn('department_id', $departmentIds));
    }

    // Đang làm việc tại ngày $date: đã vào làm và chưa nghỉ việc (ngày nghỉ việc = ngày làm cuối).
    private function activeOn(Employee $e, string $date): bool
    {
        return $e->hire_date && $e->hire_date->toDateString() <= $date
            && (! $e->termination_date || $e->termination_date->toDateString() >= $date);
    }

    private function activeDuring(Employee $e, Carbon $start, Carbon $end): bool
    {
        return $e->hire_date && $e->hire_date->toDateString() <= $end->toDateString()
            && (! $e->termination_date || $e->termination_date->toDateString() >= $start->toDateString());
    }

    /** @return Collection<int, array{key: string, start: Carbon, end: Carbon}> */
    private function months(array $params): Collection
    {
        $from = Carbon::createFromFormat('Y-m', $params['from'])->startOfMonth();
        $to = Carbon::createFromFormat('Y-m', $params['to'])->startOfMonth();

        $months = collect();
        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addMonth()) {
            $months->push(['key' => $cursor->format('Y-m'), 'start' => $cursor->copy(), 'end' => $cursor->copy()->endOfMonth()]);
        }

        return $months;
    }

    // Gom tháng theo quý khi cần ("2026-Q4").
    private function group(Collection $months, string $group): Collection
    {
        return $months->groupBy(fn (array $m) => $group === 'quarter'
            ? $m['start']->year . '-Q' . $m['start']->quarter
            : $m['key']);
    }

    private function periodLabel(string $key): string
    {
        if (str_contains($key, 'Q')) {
            [$year, $quarter] = explode('-Q', $key);

            return "Quý {$quarter}/{$year}";
        }
        [$year, $month] = explode('-', $key);

        return 'T' . (int) $month . '/' . $year;
    }

    /** @param array<int, int> $roots */
    private function withDescendants(array $roots): array
    {
        return Department::withDescendantIds($roots);
    }

    private function cached(string $type, array $params, ?array $departmentIds, callable $build, bool $fresh): array
    {
        $key = 'reports:' . $type . ':' . md5(json_encode([
            // Chỉ các khóa ảnh hưởng kết quả (tham số thừa không làm lệch khóa cache).
            Arr::only($params, ['from', 'to', 'group']),
            $departmentIds === null ? 'all' : collect($departmentIds)->sort()->values()->all(),
        ]));

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_SECONDS, $build);
    }
}
