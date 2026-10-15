<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Công việc trong dự án (2026-10-14, giai đoạn A). Luồng trạng thái: xem ProjectTask.
// Điểm mấu chốt cho KPI (giai đoạn B): nhân viên KHÔNG tự đánh dấu hoàn thành — nộp xong
// chờ Lead duyệt + chấm chất lượng 1-5; việc của CHÍNH Lead do người quản lý dự án
// (project.manage) duyệt, Lead không tự duyệt việc của mình.
class ProjectTaskService
{
    // Việc chờ duyệt quá số ngày này thì nhắc người duyệt.
    public const REVIEW_REMIND_DAYS = 2;

    public function __construct(private readonly ProjectService $projectService)
    {
    }

    public function list(User $user, Project $project, array $filters = []): Collection
    {
        $this->projectService->ensureCanView($user, $project);

        return $project->tasks()
            ->with(['assignee:id,full_name,code,avatar', 'reviewer:id,user_name'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['assignee_id'] ?? null, fn ($q, $id) => $id === 'none'
                ? $q->whereNull('assignee_employee_id')
                : $q->where('assignee_employee_id', $id))
            ->orderBy('due_date')
            ->latest('id')
            ->get();
    }

    public function show(User $user, ProjectTask $task): ProjectTask
    {
        $this->projectService->ensureCanView($user, $task->project);

        return $task->load(['project:id,name,code,status,lead_employee_id', 'assignee:id,full_name,code,avatar', 'reviewer:id,user_name', 'creator:id,user_name']);
    }

    // "Công việc của tôi" — mọi dự án. Mặc định: việc còn mở; 'done' / 'all' để xem lại.
    public function mine(Employee $employee, array $filters = []): Collection
    {
        $scope = $filters['scope'] ?? 'open';

        return ProjectTask::query()
            ->where('assignee_employee_id', $employee->id)
            ->whereHas('project')
            ->with(['project:id,name,code,status', 'reviewer:id,user_name'])
            ->when($scope === 'open', fn ($q) => $q->whereIn('status', ProjectTask::OPEN_STATUSES))
            ->when($scope === 'done', fn ($q) => $q->where('status', ProjectTask::STATUS_DONE))
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
            ->orderByRaw("case status when 'in_progress' then 0 when 'todo' then 1 when 'in_review' then 2 else 3 end")
            ->orderBy('due_date')
            ->get();
    }

    public function create(User $actor, Project $project, array $data): ProjectTask
    {
        $this->projectService->ensureCanManageTeam($actor, $project);
        $this->projectService->ensureOpen($project);
        $this->ensureMember($project, $data['assignee_employee_id'] ?? null);

        $task = $project->tasks()->create([
            ...$data,
            'status' => ProjectTask::STATUS_TODO,
            'created_by' => $actor->id,
        ]);

        $this->notifyAssigned($actor, $task);

        return $task;
    }

    public function update(User $actor, ProjectTask $task, array $data): ProjectTask
    {
        $project = $task->project;
        $this->projectService->ensureCanManageTeam($actor, $project);
        $this->projectService->ensureOpen($project);
        if (! $task->isOpen()) {
            throw ValidationException::withMessages(['task' => 'Công việc đã hoàn thành/hủy — không sửa được nữa.']);
        }
        $this->ensureMember($project, $data['assignee_employee_id'] ?? null);

        $oldAssignee = $task->assignee;
        $oldDue = $task->due_date->toDateString();
        $oldStart = $task->start_date?->toDateString();
        $task->update($data);

        // Đổi ngày -> xóa cờ "đã nhắc" để nhắc lại theo ngày mới.
        $dueChanged = $task->due_date->toDateString() !== $oldDue;
        if ($dueChanged) {
            $task->update(['due_soon_notified_at' => null, 'due_today_notified_at' => null, 'overdue_notified_at' => null]);
        }
        if ($task->start_date?->toDateString() !== $oldStart) {
            $task->update(['start_notified_at' => null]);
        }

        $data = ['project_id' => $task->project_id, 'task_id' => $task->id];
        if ($task->assignee_employee_id !== $oldAssignee?->id) {
            // Người cũ biết mình không còn phụ trách; người mới nhận thông báo giao việc.
            if ($oldAssignee) {
                $this->projectService->notify($oldAssignee, $actor, 'task.unassigned', 'Bạn không còn phụ trách công việc', "\"{$task->title}\" (dự án {$project->name}) đã được giao cho người khác.", $data);
            }
            if ($task->assignee_employee_id) {
                $this->notifyAssigned($actor, $task->unsetRelation('assignee'));
            }
        } elseif ($dueChanged && $task->assignee) {
            $due = $task->due_date->format('d/m/Y');
            $this->projectService->notify($task->assignee, $actor, 'task.due_changed', 'Công việc đổi hạn hoàn thành', "\"{$task->title}\" đổi hạn từ ".Carbon::parse($oldDue)->format('d/m/Y')." sang {$due}.", $data);
        }

        return $task->fresh();
    }

    public function cancel(User $actor, ProjectTask $task): ProjectTask
    {
        $this->projectService->ensureCanManageTeam($actor, $task->project);
        if (! $task->isOpen()) {
            throw ValidationException::withMessages(['task' => 'Chỉ hủy được công việc chưa hoàn thành.']);
        }

        $task->update(['status' => ProjectTask::STATUS_CANCELLED]);

        if ($task->assignee) {
            $this->projectService->notify($task->assignee, $actor, 'task.cancelled', 'Công việc đã bị hủy', "\"{$task->title}\" (dự án {$task->project->name}) đã bị hủy — bạn không cần làm tiếp.", [
                'project_id' => $task->project_id,
                'task_id' => $task->id,
            ]);
        }

        return $task;
    }

    // Xóa hẳn chỉ dành cho việc tạo nhầm (chưa ai bắt tay làm) — còn lại dùng "Hủy" để giữ lịch sử.
    public function delete(User $actor, ProjectTask $task): void
    {
        $this->projectService->ensureCanManageTeam($actor, $task->project);
        if ($task->status !== ProjectTask::STATUS_TODO) {
            throw ValidationException::withMessages(['task' => 'Chỉ xóa được công việc chưa bắt đầu — việc đã làm thì dùng "Hủy" để giữ lịch sử.']);
        }

        $assignee = $task->assignee;
        $task->delete();

        if ($assignee) {
            $this->projectService->notify($assignee, $actor, 'task.deleted', 'Công việc đã bị xóa', "\"{$task->title}\" (dự án {$task->project->name}) đã bị xóa vì tạo nhầm.", [
                'project_id' => $task->project_id,
            ]);
        }
    }

    public function start(User $actor, ProjectTask $task): ProjectTask
    {
        $this->ensureAssignee($actor, $task);
        if ($task->status !== ProjectTask::STATUS_TODO) {
            throw ValidationException::withMessages(['task' => 'Công việc không ở trạng thái "Cần làm".']);
        }

        $task->update(['status' => ProjectTask::STATUS_IN_PROGRESS, 'started_at' => now()]);

        return $task;
    }

    // Nộp kết quả -> chờ duyệt. Ghi submitted_at (KPI xét đúng hạn theo ngày nộp).
    public function submit(User $actor, ProjectTask $task, ?string $note): ProjectTask
    {
        $this->ensureAssignee($actor, $task);
        $this->projectService->ensureOpen($task->project);
        if (! in_array($task->status, [ProjectTask::STATUS_TODO, ProjectTask::STATUS_IN_PROGRESS], true)) {
            throw ValidationException::withMessages(['task' => 'Công việc đã nộp hoặc đã kết thúc.']);
        }

        $task->update([
            'status' => ProjectTask::STATUS_IN_REVIEW,
            'submitted_at' => now(),
            'submission_note' => $note,
            'review_reminded_at' => null,
        ]);

        $assigneeName = $task->assignee->full_name;
        foreach ($this->reviewers($task) as $reviewer) {
            $this->projectService->notify($reviewer, $actor, 'task.submitted', 'Công việc chờ duyệt', "{$assigneeName} đã nộp \"{$task->title}\" — dự án {$task->project->name}.", [
                'project_id' => $task->project_id,
                'task_id' => $task->id,
            ]);
        }

        return $task;
    }

    /**
     * Duyệt (kèm chất lượng 1-5) hoặc trả lại (kèm lý do).
     *
     * @param  'approve'|'return'  $decision
     */
    public function review(User $actor, ProjectTask $task, string $decision, ?int $quality, ?string $note): ProjectTask
    {
        $this->ensureCanReview($actor, $task);
        if ($task->status !== ProjectTask::STATUS_IN_REVIEW) {
            throw ValidationException::withMessages(['task' => 'Công việc không ở trạng thái chờ duyệt.']);
        }

        DB::transaction(function () use ($actor, $task, $decision, $quality, $note) {
            if ($decision === 'approve') {
                $task->update([
                    'status' => ProjectTask::STATUS_DONE,
                    'completed_at' => now(),
                    'quality_score' => $quality,
                    'review_note' => $note,
                    'reviewed_by' => $actor->id,
                ]);
            } else {
                $task->update([
                    'status' => ProjectTask::STATUS_IN_PROGRESS,
                    'review_note' => $note,
                    'return_count' => $task->return_count + 1,
                    'reviewed_by' => $actor->id,
                ]);
            }
        });

        if ($task->assignee) {
            [$type, $title, $message] = $decision === 'approve'
                ? ['task.approved', 'Công việc đã được duyệt', "\"{$task->title}\" đã được duyệt — chất lượng {$quality}/5."]
                : ['task.returned', 'Công việc bị trả lại', "\"{$task->title}\" cần làm lại: {$note}"];
            $this->projectService->notify($task->assignee, $actor, $type, $title, $message, [
                'project_id' => $task->project_id,
                'task_id' => $task->id,
            ]);
        }

        return $task;
    }

    // Người duyệt: Lead dự án — trừ khi việc giao cho chính Lead, khi đó là người quản lý dự án.
    public function canReview(User $user, ProjectTask $task): bool
    {
        $project = $task->project;
        $isOwnTask = $user->employee && $user->employee->id === $task->assignee_employee_id;
        if ($isOwnTask) {
            return false;
        }

        return $this->projectService->isManager($user)
            || $this->projectService->roleOf($user, $project) === ProjectMember::ROLE_LEAD;
    }

    /**
     * Nhắc công việc (job projects:remind-tasks, mỗi sáng). Mỗi mốc chỉ nhắc 1 lần (cột *_notified_at):
     *  - tới ngày bắt đầu mà còn "Cần làm" -> người làm;
     *  - còn 1 ngày tới hạn / hạn hôm nay -> người làm;
     *  - quá hạn -> người làm + Lead;
     *  - chờ duyệt quá REVIEW_REMIND_DAYS ngày -> người duyệt.
     * Dự án tạm dừng/đã đóng không nhắc.
     *
     * @return array{start_today: int, due_soon: int, due_today: int, overdue: int, review_pending: int}
     */
    public function sendReminders(): array
    {
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();
        $base = fn () => ProjectTask::query()
            ->whereIn('status', ProjectTask::OPEN_STATUSES)
            ->whereNotNull('assignee_employee_id')
            ->whereHas('project', fn ($q) => $q->whereNotIn('status', [...Project::CLOSED_STATUSES, Project::STATUS_ON_HOLD]))
            ->with(['assignee.user', 'project.lead.user']);

        $startToday = $base()->where('status', ProjectTask::STATUS_TODO)->whereDate('start_date', '<=', $today)
            ->whereNull('start_notified_at')->get();
        foreach ($startToday as $task) {
            $this->projectService->notify($task->assignee, null, 'task.start_today', 'Tới ngày bắt đầu công việc', "\"{$task->title}\" (dự án {$task->project->name}) bắt đầu từ hôm nay — bấm \"Bắt đầu làm\" khi bắt tay vào việc.", [
                'project_id' => $task->project_id,
                'task_id' => $task->id,
            ]);
            $task->update(['start_notified_at' => now()]);
        }

        $dueSoon = $base()->whereDate('due_date', $tomorrow)->whereNull('due_soon_notified_at')->get();
        foreach ($dueSoon as $task) {
            $this->projectService->notify($task->assignee, null, 'task.due_soon', 'Công việc sắp tới hạn', "\"{$task->title}\" (dự án {$task->project->name}) tới hạn vào ngày mai.", [
                'project_id' => $task->project_id,
                'task_id' => $task->id,
            ]);
            $task->update(['due_soon_notified_at' => now()]);
        }

        $dueToday = $base()->where('status', '!=', ProjectTask::STATUS_IN_REVIEW)->whereDate('due_date', $today)
            ->whereNull('due_today_notified_at')->get();
        foreach ($dueToday as $task) {
            $this->projectService->notify($task->assignee, null, 'task.due_today', 'Hôm nay là hạn hoàn thành', "\"{$task->title}\" (dự án {$task->project->name}) tới hạn hôm nay — nhớ nộp kết quả.", [
                'project_id' => $task->project_id,
                'task_id' => $task->id,
            ]);
            $task->update(['due_today_notified_at' => now()]);
        }

        $overdue = $base()->where('status', '!=', ProjectTask::STATUS_IN_REVIEW)->whereDate('due_date', '<', $today)
            ->whereNull('overdue_notified_at')->get();
        foreach ($overdue as $task) {
            $data = ['project_id' => $task->project_id, 'task_id' => $task->id];
            $due = $task->due_date->format('d/m/Y');
            $this->projectService->notify($task->assignee, null, 'task.overdue', 'Công việc đã quá hạn', "\"{$task->title}\" đã quá hạn ({$due}).", $data);
            if ($task->project->lead && $task->project->lead->id !== $task->assignee_employee_id) {
                $this->projectService->notify($task->project->lead, null, 'task.overdue', 'Công việc trong dự án quá hạn', "{$task->assignee->full_name}: \"{$task->title}\" đã quá hạn ({$due}).", $data);
            }
            $task->update(['overdue_notified_at' => now()]);
        }

        // Chờ duyệt quá lâu -> nhắc người duyệt (nhân viên không phải chờ vô thời hạn).
        $reviewPending = $base()->where('status', ProjectTask::STATUS_IN_REVIEW)
            ->where('submitted_at', '<=', now()->subDays(self::REVIEW_REMIND_DAYS))
            ->whereNull('review_reminded_at')->get();
        foreach ($reviewPending as $task) {
            $days = (int) $task->submitted_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
            foreach ($this->reviewers($task) as $reviewer) {
                $this->projectService->notify($reviewer, null, 'task.review_pending', 'Công việc chờ duyệt đã lâu', "{$task->assignee->full_name} nộp \"{$task->title}\" {$days} ngày trước, vẫn chờ bạn duyệt.", [
                    'project_id' => $task->project_id,
                    'task_id' => $task->id,
                ]);
            }
            $task->update(['review_reminded_at' => now()]);
        }

        return [
            'start_today' => $startToday->count(),
            'due_soon' => $dueSoon->count(),
            'due_today' => $dueToday->count(),
            'overdue' => $overdue->count(),
            'review_pending' => $reviewPending->count(),
        ];
    }

    /** @return Collection<int, Employee> */
    private function reviewers(ProjectTask $task): Collection
    {
        $lead = $task->project->lead()->with('user')->first();
        if ($lead && $lead->id !== $task->assignee_employee_id) {
            return collect([$lead]);
        }

        // Việc của chính Lead -> người quản lý dự án duyệt.
        return User::withPermission('project.manage')
            ->where('status', 'active')
            ->with('employee.user')
            ->get()
            ->pluck('employee')
            ->filter();
    }

    private function ensureMember(Project $project, ?int $employeeId): void
    {
        if ($employeeId && ! $project->members()->where('employee_id', $employeeId)->exists()) {
            throw ValidationException::withMessages(['assignee_employee_id' => 'Người được giao phải là thành viên của dự án.']);
        }
    }

    private function ensureAssignee(User $actor, ProjectTask $task): void
    {
        if (! $actor->employee || $actor->employee->id !== $task->assignee_employee_id) {
            throw new AuthorizationException('Chỉ người được giao mới thao tác được công việc này.');
        }
    }

    private function ensureCanReview(User $actor, ProjectTask $task): void
    {
        if (! $this->canReview($actor, $task)) {
            throw new AuthorizationException($actor->employee?->id === $task->assignee_employee_id
                ? 'Không tự duyệt công việc của chính mình.'
                : 'Chỉ Lead dự án (hoặc người quản lý dự án) mới duyệt được công việc.');
        }
    }

    private function notifyAssigned(User $actor, ProjectTask $task): void
    {
        $task->loadMissing(['assignee.user', 'project']);
        if ($task->assignee) {
            $due = $task->due_date->format('d/m/Y');
            $this->projectService->notify($task->assignee, $actor, 'task.assigned', 'Bạn được giao việc mới', "\"{$task->title}\" — dự án {$task->project->name}, hạn {$due}.", [
                'project_id' => $task->project_id,
                'task_id' => $task->id,
            ]);
        }
    }
}
