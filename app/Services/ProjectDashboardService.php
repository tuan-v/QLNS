<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

// Số liệu module Dự án cho trang Tổng quan (DashboardService, 2026-10-15):
//   - myWork(): "Công việc hôm nay" của chính người xem + việc đang chờ HỌ duyệt;
//   - attention(): dự án cần chú ý (cho người quản lý dự án);
//   - reviewQueue(): việc chờ người xem duyệt — dùng chung cho khối "Cần bạn xử lý".
class ProjectDashboardService
{
    public function __construct(private readonly ProjectService $projectService)
    {
    }

    /** null khi tài khoản không gắn hồ sơ nhân viên. */
    public function myWork(User $user): ?array
    {
        $employee = $user->employee;
        if (! $employee) {
            return null;
        }

        $today = now()->toDateString();
        $open = ProjectTask::query()
            ->where('assignee_employee_id', $employee->id)
            ->whereIn('status', ProjectTask::OPEN_STATUSES)
            ->whereHas('project', fn ($q) => $q->whereNotIn('status', Project::CLOSED_STATUSES))
            ->with('project:id,code,name,status')
            ->get();

        $working = $open->where('status', '!=', ProjectTask::STATUS_IN_REVIEW);
        $isOverdue = fn (ProjectTask $t) => $t->due_date->toDateString() < $today;
        $isDueToday = fn (ProjectTask $t) => $t->due_date->toDateString() === $today;
        $startsToday = fn (ProjectTask $t) => $t->status === ProjectTask::STATUS_TODO && $t->start_date?->toDateString() === $today;

        // Danh sách "cần để ý hôm nay": quá hạn -> hạn hôm nay -> bắt đầu hôm nay -> sắp hạn (2 ngày).
        $focus = $working
            ->filter(fn ($t) => $isOverdue($t) || $isDueToday($t) || $startsToday($t) || $t->due_date->toDateString() <= now()->addDays(2)->toDateString())
            ->sortBy(fn ($t) => [$isOverdue($t) ? 0 : ($isDueToday($t) ? 1 : ($startsToday($t) ? 2 : 3)), $t->due_date->toDateString()])
            ->take(6)
            ->map(fn (ProjectTask $t) => [
                'id' => $t->id,
                'title' => $t->title,
                'status' => $t->status,
                'priority' => $t->priority,
                'due_date' => $t->due_date->toDateString(),
                'project' => ['id' => $t->project->id, 'code' => $t->project->code, 'name' => $t->project->name],
                'reason' => $isOverdue($t) ? 'overdue' : ($isDueToday($t) ? 'due_today' : ($startsToday($t) ? 'start_today' : 'due_soon')),
                'returned' => $t->status === ProjectTask::STATUS_IN_PROGRESS && $t->return_count > 0,
            ])
            ->values();

        $review = $this->reviewQueue($user);

        return [
            'counts' => [
                'overdue' => $working->filter($isOverdue)->count(),
                'due_today' => $working->filter($isDueToday)->count(),
                'start_today' => $working->filter($startsToday)->count(),
                'in_progress' => $open->where('status', ProjectTask::STATUS_IN_PROGRESS)->count(),
                'waiting_review' => $open->where('status', ProjectTask::STATUS_IN_REVIEW)->count(),
                'open' => $open->count(),
            ],
            'focus' => $focus,
            'to_review' => [
                'count' => $review->count(),
                'items' => $review->take(4)->map(fn (ProjectTask $t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'assignee' => $t->assignee?->full_name,
                    'submitted_at' => $t->submitted_at?->toIso8601String(),
                    'project' => ['id' => $t->project->id, 'code' => $t->project->code, 'name' => $t->project->name],
                ])->values(),
            ],
        ];
    }

    /**
     * Việc đang chờ $user duyệt: việc trong dự án họ làm Lead (trừ việc của chính họ) và —
     * nếu là người quản lý dự án — việc của chính các Lead (Lead không tự duyệt).
     */
    public function reviewQueue(User $user)
    {
        $employeeId = $user->employee?->id ?? 0;
        $isManager = $this->projectService->isManager($user);

        return ProjectTask::query()
            ->where('status', ProjectTask::STATUS_IN_REVIEW)
            ->whereHas('project', fn ($q) => $q->whereNotIn('status', Project::CLOSED_STATUSES))
            ->where(function (Builder $w) use ($employeeId, $isManager) {
                $w->where(fn ($own) => $own
                    ->whereHas('project', fn ($p) => $p->where('lead_employee_id', $employeeId))
                    ->where('assignee_employee_id', '!=', $employeeId));
                if ($isManager) {
                    $w->orWhereHas('project', fn ($p) => $p->whereColumn('projects.lead_employee_id', 'project_tasks.assignee_employee_id'));
                }
            })
            ->with(['project:id,code,name', 'assignee:id,full_name'])
            ->orderBy('submitted_at')
            ->get();
    }

    // Dự án cần chú ý (người quản lý dự án): có việc quá hạn, sắp/đã quá ngày kết thúc,
    // hoặc tới ngày bắt đầu mà vẫn "Lên kế hoạch". Xếp theo mức nghiêm trọng.
    public function attention(User $user): array
    {
        if (! $this->projectService->isManager($user)) {
            return [];
        }

        $today = now()->toDateString();
        $soon = now()->addDays(ProjectService::ENDING_SOON_DAYS)->toDateString();

        return Project::query()
            ->whereNotIn('status', Project::CLOSED_STATUSES)
            ->with('lead:id,full_name')
            ->withCount([
                'tasks as overdue_tasks' => fn ($q) => $q->whereIn('status', ProjectTask::OPEN_STATUSES)->whereDate('due_date', '<', $today),
                'tasks as open_tasks' => fn ($q) => $q->whereIn('status', ProjectTask::OPEN_STATUSES),
                'tasks as review_tasks' => fn ($q) => $q->where('status', ProjectTask::STATUS_IN_REVIEW),
            ])
            ->get()
            ->map(function (Project $p) use ($today, $soon) {
                $end = $p->end_date?->toDateString();
                $reasons = array_values(array_filter([
                    $end && $end < $today ? ['key' => 'past_end', 'label' => 'Quá ngày kết thúc', 'color' => 'error'] : null,
                    $p->overdue_tasks > 0 ? ['key' => 'overdue_tasks', 'label' => "{$p->overdue_tasks} việc quá hạn", 'color' => 'error'] : null,
                    $end && $end >= $today && $end <= $soon && $p->open_tasks > 0 ? ['key' => 'ending_soon', 'label' => 'Sắp kết thúc, còn '.$p->open_tasks.' việc', 'color' => 'warning'] : null,
                    $p->status === Project::STATUS_PLANNING && $p->start_date->toDateString() <= $today ? ['key' => 'not_started', 'label' => 'Tới ngày bắt đầu, chưa chạy', 'color' => 'warning'] : null,
                ]));

                return $reasons === [] ? null : [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                    'status' => $p->status,
                    'lead' => $p->lead?->full_name,
                    'end_date' => $end,
                    'review_tasks' => $p->review_tasks,
                    'reasons' => $reasons,
                    // 0 = quá ngày kết thúc, 1 = có việc quá hạn, 2 = cảnh báo.
                    'severity' => min(array_map(fn ($r) => ['past_end' => 0, 'overdue_tasks' => 1][$r['key']] ?? 2, $reasons)),
                ];
            })
            ->filter()
            ->sortBy(['severity', 'end_date'])
            ->take(6)
            ->values()
            ->all();
    }
}
