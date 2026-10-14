<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Dự án (2026-10-14, giai đoạn A — ngoài tài liệu gốc, người dùng duyệt).
// Ai được làm gì:
//   - Quản lý dự án (quyền project.manage — Admin/HR): tạo/sửa/xóa mọi dự án, chọn/đổi Lead,
//     xem mọi dự án, làm được mọi việc của Lead.
//   - Lead (project_members.role = lead, THEO TỪNG DỰ ÁN): thêm/bớt thành viên, giao/sửa/hủy
//     việc, duyệt việc; đổi trạng thái/mô tả/ngày kết thúc dự án.
//   - Thành viên: xem dự án mình tham gia, làm việc được giao.
// Kiểm tra quyền nằm ở Service (không chỉ ẩn nút ở Frontend).
class ProjectService
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function isManager(User $user): bool
    {
        return in_array('project.manage', $user->cachedPermissionCodes(), true);
    }

    // Vai trò của người dùng trong dự án: 'lead' | 'member' | null.
    public function roleOf(User $user, Project $project): ?string
    {
        $employeeId = $user->employee?->id;
        if (! $employeeId) {
            return null;
        }

        return $project->relationLoaded('members')
            ? $project->members->firstWhere('employee_id', $employeeId)?->role
            : $project->members()->where('employee_id', $employeeId)->value('role');
    }

    public function canView(User $user, Project $project): bool
    {
        return $this->isManager($user) || $this->roleOf($user, $project) !== null;
    }

    public function canManageTeam(User $user, Project $project): bool
    {
        return $this->isManager($user) || $this->roleOf($user, $project) === ProjectMember::ROLE_LEAD;
    }

    public function ensureCanView(User $user, Project $project): void
    {
        if (! $this->canView($user, $project)) {
            throw new AuthorizationException('Bạn không tham gia dự án này.');
        }
    }

    public function ensureCanManageTeam(User $user, Project $project): void
    {
        if (! $this->canManageTeam($user, $project)) {
            throw new AuthorizationException('Chỉ Lead dự án hoặc người quản lý dự án mới làm được thao tác này.');
        }
    }

    public function ensureOpen(Project $project): void
    {
        if ($project->isClosed()) {
            throw ValidationException::withMessages(['project' => 'Dự án đã kết thúc/hủy — không thay đổi công việc hay thành viên được nữa.']);
        }
    }

    public function list(User $user, array $filters = []): Collection
    {
        $employeeId = $user->employee?->id;

        return $this->withStats(Project::query())
            ->with([
                'lead:id,full_name,code,avatar',
                'department:id,name',
                // Dãy avatar thành viên trên thẻ dự án.
                'members' => fn ($q) => $q->orderByRaw("case role when 'lead' then 0 else 1 end")->oldest('id'),
                'members.employee:id,full_name,code,avatar',
            ])
            ->when(! $this->isManager($user) || ($filters['mine'] ?? false), fn (Builder $q) => $q->whereHas(
                'members',
                fn ($m) => $m->where('employee_id', $employeeId ?? 0),
            ))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"),
            ))
            ->orderByRaw("case status when 'active' then 0 when 'planning' then 1 when 'on_hold' then 2 else 3 end")
            ->latest('id')
            ->get()
            ->each(fn (Project $project) => $project->setAttribute('my_role', $employeeId
                ? $project->members->firstWhere('employee_id', $employeeId)?->role
                : null));
    }

    public function show(User $user, Project $project): Project
    {
        $this->ensureCanView($user, $project);

        $project = $this->withStats(Project::query())->findOrFail($project->id);
        $project->load([
            'lead:id,full_name,code,avatar,position_id',
            'department:id,name',
            'creator:id,user_name',
            'members' => fn ($q) => $q->orderByRaw("case role when 'lead' then 0 else 1 end")->oldest('id'),
            'members.employee:id,full_name,code,avatar,position_id,department_id',
            'members.employee.position:id,name',
        ]);

        // Số việc đang mở / quá hạn của từng thành viên — Lead nhìn là biết ai đang quá tải.
        $load = ProjectTask::query()
            ->where('project_id', $project->id)
            ->whereIn('status', ProjectTask::OPEN_STATUSES)
            ->selectRaw('assignee_employee_id, count(*) as open_count, sum(case when due_date < ? then 1 else 0 end) as overdue_count', [now()->toDateString()])
            ->groupBy('assignee_employee_id')
            ->get()
            ->keyBy('assignee_employee_id');
        $project->members->each(function (ProjectMember $member) use ($load) {
            $member->setAttribute('open_tasks', (int) ($load[$member->employee_id]->open_count ?? 0));
            $member->setAttribute('overdue_tasks', (int) ($load[$member->employee_id]->overdue_count ?? 0));
        });

        $project->setAttribute('my_role', $this->roleOf($user, $project));
        $project->setAttribute('can_manage_team', $this->canManageTeam($user, $project));
        $project->setAttribute('can_edit', $this->isManager($user));

        return $project;
    }

    public function create(User $actor, array $data): Project
    {
        $project = DB::transaction(function () use ($actor, $data) {
            $project = Project::create([
                ...$data,
                'code' => $this->generateCode(),
                'status' => $data['status'] ?? Project::STATUS_ACTIVE,
                'created_by' => $actor->id,
            ]);
            ProjectMember::create([
                'project_id' => $project->id,
                'employee_id' => $project->lead_employee_id,
                'role' => ProjectMember::ROLE_LEAD,
                'added_by' => $actor->id,
            ]);

            return $project;
        });

        $this->notifyLead($actor, $project);

        return $project;
    }

    // Quản lý sửa được mọi trường (kể cả đổi Lead); Lead chỉ đổi trạng thái/mô tả/ngày kết thúc.
    public function update(User $actor, Project $project, array $data): Project
    {
        $this->ensureCanView($actor, $project);
        if (! $this->isManager($actor)) {
            $this->ensureCanManageTeam($actor, $project);
            $data = array_intersect_key($data, array_flip(['status', 'description', 'end_date']));
        }

        $start = $data['start_date'] ?? $project->start_date->toDateString();
        if (! empty($data['end_date']) && $data['end_date'] < $start) {
            throw ValidationException::withMessages(['end_date' => 'Ngày kết thúc phải từ ngày bắt đầu trở đi']);
        }

        $oldLeadId = $project->lead_employee_id;

        DB::transaction(function () use ($actor, $project, $data, $oldLeadId) {
            $project->update($data);

            if ($project->lead_employee_id !== $oldLeadId) {
                // Lead cũ vẫn ở lại dự án với vai trò thành viên (còn việc đang làm dở).
                ProjectMember::where('project_id', $project->id)->where('employee_id', $oldLeadId)
                    ->first()?->update(['role' => ProjectMember::ROLE_MEMBER]);
                ProjectMember::updateOrCreate(
                    ['project_id' => $project->id, 'employee_id' => $project->lead_employee_id],
                    ['role' => ProjectMember::ROLE_LEAD, 'added_by' => $actor->id],
                );
            }
        });

        if ($project->lead_employee_id !== $oldLeadId) {
            $this->notifyLead($actor, $project);
        }

        return $project->fresh();
    }

    public function delete(User $actor, Project $project): void
    {
        if (! $this->isManager($actor)) {
            throw new AuthorizationException('Chỉ người quản lý dự án mới xóa được dự án.');
        }
        // Việc đã duyệt là dữ liệu KPI — dự án có việc hoàn thành thì đóng (hủy) thay vì xóa.
        if ($project->tasks()->where('status', ProjectTask::STATUS_DONE)->exists()) {
            throw ValidationException::withMessages(['project' => 'Dự án đã có công việc hoàn thành (dữ liệu KPI) — hãy chuyển trạng thái "Đã hủy" thay vì xóa.']);
        }

        $project->delete();
    }

    /** @param  array<int, int>  $employeeIds */
    public function addMembers(User $actor, Project $project, array $employeeIds): Collection
    {
        $this->ensureCanManageTeam($actor, $project);
        $this->ensureOpen($project);

        $existing = $project->members()->pluck('employee_id')->all();
        $employees = Employee::query()
            ->whereIn('id', array_diff($employeeIds, $existing))
            ->whereIn('employment_status', Employee::WORKING_STATUSES)
            ->with('user')
            ->get();

        $added = DB::transaction(fn () => $employees->map(fn (Employee $employee) => ProjectMember::create([
            'project_id' => $project->id,
            'employee_id' => $employee->id,
            'role' => ProjectMember::ROLE_MEMBER,
            'added_by' => $actor->id,
        ])));

        foreach ($employees as $employee) {
            $this->notify($employee, $actor, 'project.member_added', 'Bạn được thêm vào dự án', "Bạn vừa được thêm vào dự án \"{$project->name}\".", [
                'project_id' => $project->id,
            ]);
        }

        return $added;
    }

    public function removeMember(User $actor, Project $project, Employee $employee): void
    {
        $this->ensureCanManageTeam($actor, $project);

        $member = $project->members()->where('employee_id', $employee->id)->firstOrFail();
        if ($member->role === ProjectMember::ROLE_LEAD) {
            throw ValidationException::withMessages(['employee' => 'Không xóa được Lead khỏi dự án — đổi Lead trước.']);
        }
        $open = $project->tasks()->where('assignee_employee_id', $employee->id)->whereIn('status', ProjectTask::OPEN_STATUSES)->count();
        if ($open > 0) {
            throw ValidationException::withMessages(['employee' => "Thành viên còn {$open} công việc chưa xong — giao lại cho người khác trước khi xóa khỏi dự án."]);
        }

        $member->delete();
    }

    // Nhân viên đang làm việc, chưa ở trong dự án — ô chọn "Thêm thành viên" của Lead
    // (Lead thường không có quyền employee.view nên cần route riêng, chỉ trả vài trường).
    public function memberCandidates(User $actor, Project $project, ?string $search): Collection
    {
        $this->ensureCanManageTeam($actor, $project);

        return $this->employeeOptions($search)
            ->whereNotIn('id', $project->members()->select('employee_id'))
            ->get();
    }

    public function leadCandidates(?string $search): Collection
    {
        return $this->employeeOptions($search)->get();
    }

    private function employeeOptions(?string $search): Builder
    {
        return Employee::query()
            ->whereIn('employment_status', Employee::WORKING_STATUSES)
            ->with(['position:id,name', 'department:id,name'])
            ->select(['id', 'full_name', 'code', 'avatar', 'position_id', 'department_id'])
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('full_name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))
            ->orderBy('full_name')
            ->limit(30);
    }

    // Đếm việc theo trạng thái + tiến độ theo ĐIỂM (việc 8 điểm nặng hơn việc 1 điểm).
    private function withStats(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query
            ->withCount([
                'members',
                'tasks as tasks_total' => fn ($q) => $q->where('status', '!=', ProjectTask::STATUS_CANCELLED),
                'tasks as tasks_done' => fn ($q) => $q->where('status', ProjectTask::STATUS_DONE),
                'tasks as tasks_in_review' => fn ($q) => $q->where('status', ProjectTask::STATUS_IN_REVIEW),
                'tasks as tasks_overdue' => fn ($q) => $q->whereIn('status', ProjectTask::OPEN_STATUSES)->where('due_date', '<', $today),
            ])
            ->withSum(['tasks as points_total' => fn ($q) => $q->where('status', '!=', ProjectTask::STATUS_CANCELLED)], 'points')
            ->withSum(['tasks as points_done' => fn ($q) => $q->where('status', ProjectTask::STATUS_DONE)], 'points');
    }

    private function notifyLead(User $actor, Project $project): void
    {
        $lead = $project->lead()->with('user')->first();
        if ($lead) {
            $this->notify($lead, $actor, 'project.lead_assigned', 'Bạn là Lead dự án mới', "Bạn được chọn làm Lead dự án \"{$project->name}\" — hãy thêm thành viên và giao việc.", [
                'project_id' => $project->id,
            ]);
        }
    }

    // Gửi thông báo cho 1 nhân viên (nếu có tài khoản) — bỏ qua khi chính họ là người thao tác.
    public function notify(Employee $employee, ?User $actor, string $type, string $title, string $message, array $data): void
    {
        $user = $employee->user;
        if (! $user || $user->status !== 'active' || $user->id === $actor?->id) {
            return;
        }

        $this->notificationService->send($user, $type, $title, $message, $data);
    }

    private function generateCode(): string
    {
        $last = Project::withTrashed()
            ->where('code', 'like', 'DA-%')
            ->pluck('code')
            ->map(fn (string $code) => (int) substr($code, 3))
            ->max();

        return 'DA-'.str_pad((string) (($last ?? 0) + 1), 3, '0', STR_PAD_LEFT);
    }
}
