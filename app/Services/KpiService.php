<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\KpiMonthLock;
use App\Models\KpiMonthlyResult;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// KPI tháng từ công việc dự án — giai đoạn B của lộ trình Dự án → KPI → Đánh giá → Lương.
// Quyết định của người dùng (2026-10-19):
//  - KPI = 40% Hoàn thành + 30% Đúng hạn + 30% Chất lượng, mọi chỉ số nhân theo điểm độ khó của việc.
//  - Việc tính vào tháng có HẠN CHÓT (kể cả việc chưa xong — bị tính là chưa hoàn thành); bỏ việc đã hủy
//    và việc của dự án đã hủy.
//  - Phạm vi xem: kpi.view_all (HR/Admin) xem tất cả; Trưởng phòng xem nhân viên phòng mình (+ phòng con);
//    Lead xem kết quả thành viên TRONG dự án mình làm Lead; ai cũng xem được KPI của chính mình.
//  - Trong tháng tính trực tiếp; hết tháng + LOCK_AFTER_DAYS ngày thì tự chốt (kpi:lock-months).
class KpiService
{
    /** Trọng số (%) của 3 chỉ số, cộng lại = 100. */
    public const WEIGHTS = ['completion' => 40, 'on_time' => 30, 'quality' => 30];

    /** Xếp loại theo điểm KPI (0-100), xét từ trên xuống. */
    public const GRADES = [
        ['key' => 'A', 'min' => 90, 'label' => 'Xuất sắc'],
        ['key' => 'B', 'min' => 75, 'label' => 'Tốt'],
        ['key' => 'C', 'min' => 60, 'label' => 'Đạt'],
        ['key' => 'D', 'min' => 0, 'label' => 'Cần cải thiện'],
    ];

    /** Sau khi hết tháng bao nhiêu ngày thì chốt (để Lead kịp duyệt nốt việc nộp cuối tháng). */
    public const LOCK_AFTER_DAYS = 5;

    // ------------------------------------------------------------------ phạm vi xem

    /**
     * @return array{all: bool, self_id: ?int, department_ids: array<int, int>, department_employee_ids: array<int, int>, lead_project_ids: array<int, int>}
     */
    public function scope(User $user): array
    {
        $selfId = $user->employee?->id;
        $all = $user->hasPermission('kpi.view_all');
        $departmentIds = [];
        $leadProjectIds = [];

        if (! $all && $selfId) {
            $departmentIds = Department::withDescendantIds(
                Department::where('manager_id', $selfId)->pluck('id')->all()
            );
            $leadProjectIds = ProjectMember::where('employee_id', $selfId)
                ->where('role', ProjectMember::ROLE_LEAD)
                ->pluck('project_id')
                ->all();
        }

        return [
            'all' => $all,
            'self_id' => $selfId,
            'department_ids' => $departmentIds,
            'department_employee_ids' => $departmentIds
                ? Employee::withTrashed()->whereIn('department_id', $departmentIds)->pluck('id')->all()
                : [],
            'lead_project_ids' => $leadProjectIds,
        ];
    }

    private function visible(array $row, array $scope): bool
    {
        return $scope['all']
            || $row['employee_id'] === $scope['self_id']
            || in_array($row['employee_id'], $scope['department_employee_ids'], true)
            || in_array($row['project_id'], $scope['lead_project_ids'], true);
    }

    /** Người xem chỉ thấy nhân viên này qua vai trò Lead → số liệu chỉ gồm việc trong dự án của họ. */
    private function isPartial(int $employeeId, array $scope): bool
    {
        return ! $scope['all']
            && $employeeId !== $scope['self_id']
            && ! in_array($employeeId, $scope['department_employee_ids'], true);
    }

    public function filters(User $user): array
    {
        $scope = $this->scope($user);
        $limited = $scope['department_ids'] !== [] || $scope['lead_project_ids'] !== [];

        $projects = Project::query()
            ->when(! $scope['all'], fn ($query) => $query->where(function ($query) use ($scope) {
                $query->whereIn('id', $scope['lead_project_ids'])
                    ->orWhereHas('members', fn ($q) => $q->whereIn('employee_id', $scope['department_employee_ids']));
            }))
            ->orderByDesc('id')
            ->get(['id', 'code', 'name']);

        return [
            'scope' => $scope['all'] ? 'company' : ($limited ? 'limited' : 'self'),
            'self_employee_id' => $scope['self_id'],
            'departments' => Department::query()
                ->when(! $scope['all'], fn ($query) => $query->whereIn('id', $scope['department_ids']))
                ->orderBy('name')
                ->get(['id', 'name']),
            'projects' => $projects,
            'weights' => self::WEIGHTS,
            'grades' => self::GRADES,
            'lock_after_days' => self::LOCK_AFTER_DAYS,
        ];
    }

    // ------------------------------------------------------------------ đọc KPI

    /** Bảng KPI tháng: mỗi nhân viên 1 dòng (theo phạm vi người xem). */
    public function monthly(User $user, int $year, int $month, array $filters = []): array
    {
        $scope = $this->scope($user);
        $rows = $this->rows($year, $month)
            ->filter(fn (array $row) => $this->visible($row, $scope))
            ->when(! empty($filters['project_id']), fn ($rows) => $rows->where('project_id', (int) $filters['project_id']));

        $employees = Employee::withTrashed()
            ->with(['department:id,name', 'position:id,name'])
            ->whereIn('id', $rows->pluck('employee_id')->unique())
            ->get()
            ->keyBy('id');

        if (! empty($filters['department_id'])) {
            $departmentIds = Department::withDescendantIds([(int) $filters['department_id']]);
            $employees = $employees->filter(fn (Employee $e) => in_array($e->department_id, $departmentIds, true));
        }
        if (! empty($filters['search'])) {
            $needle = $this->normalize($filters['search']);
            $employees = $employees->filter(
                fn (Employee $e) => str_contains($this->normalize($e->full_name.' '.$e->code), $needle)
            );
        }

        $items = $rows->groupBy('employee_id')
            ->filter(fn ($group, $employeeId) => $employees->has($employeeId))
            ->map(fn (Collection $group, $employeeId) => [
                'employee' => $this->employeeSummary($employees[$employeeId]),
                'partial' => $this->isPartial((int) $employeeId, $scope),
                'project_count' => $group->count(),
            ] + $this->metrics($group))
            ->sortByDesc('score')
            ->values();

        return $this->period($year, $month) + [
            'weights' => self::WEIGHTS,
            'grades' => self::GRADES,
            'summary' => [
                'employees' => $items->count(),
                'average_score' => $items->isEmpty() ? null : round($items->avg('score'), 1),
                'tasks' => $items->sum('task_count'),
                'grades' => collect(self::GRADES)
                    ->mapWithKeys(fn ($grade) => [$grade['key'] => $items->where('grade.key', $grade['key'])->count()]),
            ],
            'items' => $items,
        ];
    }

    /** KPI 1 nhân viên: chỉ số tổng, theo từng dự án, danh sách việc được tính. */
    public function detail(User $user, Employee $employee, int $year, int $month): array
    {
        $scope = $this->scope($user);
        $rows = $this->rows($year, $month)
            ->where('employee_id', $employee->id)
            ->filter(fn (array $row) => $this->visible($row, $scope));

        $canSeeEmployee = $scope['all']
            || $employee->id === $scope['self_id']
            || in_array($employee->id, $scope['department_employee_ids'], true);
        abort_if(! $canSeeEmployee && $rows->isEmpty(), 403, 'Bạn không có quyền xem KPI của nhân viên này.');

        $projects = Project::withTrashed()
            ->whereIn('id', $rows->pluck('project_id'))
            ->get(['id', 'code', 'name'])
            ->keyBy('id');
        $projectInfo = fn (int $id) => ['id' => $id, 'code' => $projects[$id]->code ?? '', 'name' => $projects[$id]->name ?? 'Dự án đã xóa'];

        $employee->loadMissing(['department:id,name', 'position:id,name']);

        return $this->period($year, $month) + [
            'weights' => self::WEIGHTS,
            'grades' => self::GRADES,
            'employee' => $this->employeeSummary($employee),
            'partial' => $this->isPartial($employee->id, $scope),
            'metrics' => $rows->isEmpty() ? null : $this->metrics($rows),
            'projects' => $rows->map(fn (array $row) => ['project' => $projectInfo($row['project_id'])] + $this->metrics(collect([$row])))
                ->sortByDesc('points_due')
                ->values(),
            'tasks' => $rows->flatMap(fn (array $row) => collect($row['tasks'])
                ->map(fn (array $task) => $task + ['project' => $projectInfo($row['project_id'])]))
                ->sortBy('due_date')
                ->values(),
        ];
    }

    /**
     * Số liệu thô của tháng — mỗi (nhân viên, dự án) 1 dòng. Tháng đã chốt đọc bản chụp, chưa chốt tính trực tiếp.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int $year, int $month): Collection
    {
        if ($this->isLocked($year, $month)) {
            return KpiMonthlyResult::where('year', $year)->where('month', $month)->get()
                ->map(fn (KpiMonthlyResult $result) => $result->only([
                    'employee_id', 'project_id', 'task_count', 'done_count', 'points_due', 'points_done',
                    'points_on_time', 'quality_points', 'return_count', 'tasks',
                ]));
        }

        return $this->computeRows($year, $month);
    }

    private function computeRows(int $year, int $month): Collection
    {
        $start = Carbon::create($year, $month, 1);

        return ProjectTask::query()
            ->whereNotNull('assignee_employee_id')
            ->where('status', '!=', ProjectTask::STATUS_CANCELLED)
            ->whereBetween('due_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->whereHas('project', fn ($query) => $query->where('status', '!=', Project::STATUS_CANCELLED))
            ->orderBy('due_date')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ProjectTask $task) => $task->assignee_employee_id.'-'.$task->project_id)
            ->map(function (Collection $tasks) {
                $done = $tasks->where('status', ProjectTask::STATUS_DONE);

                return [
                    'employee_id' => (int) $tasks->first()->assignee_employee_id,
                    'project_id' => (int) $tasks->first()->project_id,
                    'task_count' => $tasks->count(),
                    'done_count' => $done->count(),
                    'points_due' => (int) $tasks->sum('points'),
                    'points_done' => (int) $done->sum('points'),
                    'points_on_time' => (int) $done->filter(fn (ProjectTask $t) => $t->finishedOnTime() === true)->sum('points'),
                    'quality_points' => (int) $done->sum(fn (ProjectTask $t) => $t->points * (int) $t->quality_score),
                    'return_count' => (int) $tasks->sum('return_count'),
                    'tasks' => $tasks->map(fn (ProjectTask $t) => [
                        'id' => $t->id,
                        'title' => $t->title,
                        'points' => $t->points,
                        'status' => $t->status,
                        'due_date' => $t->due_date->toDateString(),
                        'submitted_at' => $t->submitted_at?->toDateString(),
                        'on_time' => $t->finishedOnTime(),
                        'quality_score' => $t->quality_score,
                        'return_count' => (int) $t->return_count,
                    ])->values()->all(),
                ];
            })
            ->values();
    }

    /**
     * Gộp nhiều dòng (nhiều dự án) thành chỉ số KPI. Cộng ĐIỂM rồi mới chia nên việc khó nặng ký hơn việc dễ.
     *  - Hoàn thành = điểm đã xong / điểm có hạn trong tháng
     *  - Đúng hạn  = điểm nộp đúng hạn / điểm đã xong
     *  - Chất lượng = mức chất lượng TB (theo điểm) / 5
     */
    private function metrics(Collection $rows): array
    {
        $due = (int) $rows->sum('points_due');
        $done = (int) $rows->sum('points_done');
        $onTime = (int) $rows->sum('points_on_time');
        $qualityAvg = $done > 0 ? $rows->sum('quality_points') / $done : null;

        $completion = $due > 0 ? $done / $due * 100 : 0;
        $onTimeRate = $done > 0 ? $onTime / $done * 100 : 0;
        $qualityRate = $qualityAvg !== null ? $qualityAvg / 5 * 100 : 0;
        $score = round((self::WEIGHTS['completion'] * $completion
            + self::WEIGHTS['on_time'] * $onTimeRate
            + self::WEIGHTS['quality'] * $qualityRate) / 100, 1);

        return [
            'task_count' => (int) $rows->sum('task_count'),
            'done_count' => (int) $rows->sum('done_count'),
            'points_due' => $due,
            'points_done' => $done,
            'points_on_time' => $onTime,
            'return_count' => (int) $rows->sum('return_count'),
            'completion_rate' => round($completion, 1),
            'on_time_rate' => round($onTimeRate, 1),
            'quality_avg' => $qualityAvg === null ? null : round($qualityAvg, 2),
            'quality_rate' => round($qualityRate, 1),
            'score' => $score,
            'grade' => $this->grade($score),
        ];
    }

    /** @return array{key: string, label: string} */
    public function grade(float $score): array
    {
        $grade = collect(self::GRADES)->first(fn ($g) => $score >= $g['min']);

        return ['key' => $grade['key'], 'label' => $grade['label']];
    }

    // ------------------------------------------------------------------ chốt tháng

    public function isLocked(int $year, int $month): bool
    {
        return KpiMonthLock::where('year', $year)->where('month', $month)->exists();
    }

    /** Ngày tháng này được chốt: ngày (LOCK_AFTER_DAYS + 1) của tháng sau. */
    public function lockDate(int $year, int $month): Carbon
    {
        return Carbon::create($year, $month, 1)->addMonthNoOverflow()->addDays(self::LOCK_AFTER_DAYS)->startOfDay();
    }

    /** Chụp lại kết quả tháng; tháng đã chốt thì bỏ qua. Trả số dòng đã lưu. */
    public function lock(int $year, int $month): int
    {
        return DB::transaction(function () use ($year, $month) {
            if ($this->isLocked($year, $month)) {
                return 0;
            }
            $rows = $this->computeRows($year, $month);
            foreach ($rows as $row) {
                KpiMonthlyResult::create(['year' => $year, 'month' => $month] + $row);
            }
            KpiMonthLock::create(['year' => $year, 'month' => $month, 'locked_at' => now()]);

            return $rows->count();
        });
    }

    /**
     * Chốt mọi tháng đã tới ngày chốt mà chưa chốt (tự bắt bù nếu máy chủ tắt vài ngày).
     *
     * @return array<int, string> các tháng vừa chốt, dạng Y-m
     */
    public function lockDueMonths(?Carbon $now = null): array
    {
        $now ??= now();
        $firstDue = ProjectTask::min('due_date');
        if (! $firstDue) {
            return [];
        }

        $locked = [];
        $cursor = Carbon::parse($firstDue)->startOfMonth();
        while ($this->lockDate($cursor->year, $cursor->month)->lte($now)) {
            if (! $this->isLocked($cursor->year, $cursor->month)) {
                $this->lock($cursor->year, $cursor->month);
                $locked[] = $cursor->format('Y-m');
            }
            $cursor->addMonthNoOverflow();
        }

        return $locked;
    }

    // ------------------------------------------------------------------ tiện ích

    private function period(int $year, int $month): array
    {
        $lock = KpiMonthLock::where('year', $year)->where('month', $month)->first();

        return [
            'year' => $year,
            'month' => $month,
            'status' => $lock ? 'locked' : 'provisional',
            'locked_at' => $lock?->locked_at?->toIso8601String(),
            'lock_date' => $this->lockDate($year, $month)->toDateString(),
        ];
    }

    private function employeeSummary(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'code' => $employee->code,
            'full_name' => $employee->full_name,
            'avatar_url' => $employee->avatar ? Storage::disk('public')->url($employee->avatar) : null,
            'department' => $employee->department?->name,
            'position' => $employee->position?->name,
        ];
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
