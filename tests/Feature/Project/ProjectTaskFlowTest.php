<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\User;
use App\Services\ProjectTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Luồng công việc: giao -> bắt đầu -> nộp -> Lead duyệt (chấm 1-5) / trả lại; nhắc hạn.
class ProjectTaskFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    private Project $project;

    private Employee $lead;

    private User $leadUser;

    private Employee $member;

    private User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        [$this->lead, $this->leadUser] = $this->person();
        [$this->member, $this->memberUser] = $this->person();
        $this->project = Project::create([
            'code' => 'DA-001', 'name' => 'Du an test', 'lead_employee_id' => $this->lead->id,
            'start_date' => now()->subMonth(), 'status' => 'active',
        ]);
        ProjectMember::create(['project_id' => $this->project->id, 'employee_id' => $this->lead->id, 'role' => 'lead']);
        ProjectMember::create(['project_id' => $this->project->id, 'employee_id' => $this->member->id, 'role' => 'member']);
    }

    private function person(string $role = 'Employee'): array
    {
        $user = User::create([
            'email' => 'task-'.uniqid().'@qlns.local', 'user_name' => 'Task User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $role)->first()->users()->attach($user->id);
        $employee = Employee::create([
            'full_name' => 'Nhan vien '.uniqid(), 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYear(), 'code' => 'NV-'.uniqid(),
            'employment_status' => 'active', 'user_id' => $user->id,
        ]);

        return [$employee, $user];
    }

    private function as(User $user): array
    {
        $this->tokens[$user->id] ??= $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret@123'])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$user->id]];
    }

    private function assign(array $overrides = []): int
    {
        return $this->postJson("/api/v1/projects/{$this->project->id}/tasks", array_merge([
            'title' => 'Lam API dang nhap', 'assignee_employee_id' => $this->member->id,
            'priority' => 'high', 'points' => 3, 'due_date' => now()->addDays(5)->toDateString(),
        ], $overrides), $this->as($this->leadUser))->assertCreated()->json('data.id');
    }

    public function test_full_flow_assign_start_submit_approve(): void
    {
        $taskId = $this->assign();
        $this->assertTrue(Notification::where('user_id', $this->memberUser->id)->where('type', 'task.assigned')->exists());

        $this->getJson('/api/v1/tasks/me', $this->as($this->memberUser))
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can.start', true)
            ->assertJsonPath('data.0.can.review', false);

        $this->postJson("/api/v1/tasks/{$taskId}/start", [], $this->as($this->memberUser))->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/v1/tasks/{$taskId}/submit", ['note' => 'Xong roi'], $this->as($this->memberUser))
            ->assertOk()->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.submission_note', 'Xong roi');
        $this->assertTrue(Notification::where('user_id', $this->leadUser->id)->where('type', 'task.submitted')->exists());

        $this->postJson("/api/v1/tasks/{$taskId}/review", ['decision' => 'approve', 'quality_score' => 4, 'note' => 'Tot'], $this->as($this->leadUser))
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.quality_score', 4)
            ->assertJsonPath('data.on_time', true);
        $this->assertTrue(Notification::where('user_id', $this->memberUser->id)->where('type', 'task.approved')->exists());

        $this->getJson("/api/v1/projects/{$this->project->id}", $this->as($this->leadUser))
            ->assertJsonPath('data.stats.done', 1)->assertJsonPath('data.stats.progress', 100);
    }

    public function test_lead_returns_work_with_a_reason(): void
    {
        $taskId = $this->assign();
        $this->postJson("/api/v1/tasks/{$taskId}/submit", [], $this->as($this->memberUser))->assertOk();

        // Trả lại bắt buộc có lý do; duyệt bắt buộc có điểm.
        $this->postJson("/api/v1/tasks/{$taskId}/review", ['decision' => 'return'], $this->as($this->leadUser))->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/v1/tasks/{$taskId}/review", ['decision' => 'approve'], $this->as($this->leadUser))->assertStatus(422)->assertJsonValidationErrors('quality_score');

        $this->postJson("/api/v1/tasks/{$taskId}/review", ['decision' => 'return', 'note' => 'Thieu test'], $this->as($this->leadUser))
            ->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.return_count', 1);
        $this->assertTrue(Notification::where('user_id', $this->memberUser->id)->where('type', 'task.returned')->exists());
    }

    public function test_only_the_assignee_works_on_a_task_and_members_cannot_assign_or_review(): void
    {
        [$other, $otherUser] = $this->person();
        ProjectMember::create(['project_id' => $this->project->id, 'employee_id' => $other->id, 'role' => 'member']);
        $taskId = $this->assign();

        $this->postJson("/api/v1/tasks/{$taskId}/start", [], $this->as($otherUser))->assertForbidden();
        $this->postJson("/api/v1/projects/{$this->project->id}/tasks", [
            'title' => 'X', 'priority' => 'low', 'points' => 1, 'due_date' => now()->addDay()->toDateString(),
        ], $this->as($this->memberUser))->assertForbidden();

        $this->postJson("/api/v1/tasks/{$taskId}/submit", [], $this->as($this->memberUser))->assertOk();
        $this->postJson("/api/v1/tasks/{$taskId}/review", ['decision' => 'approve', 'quality_score' => 5], $this->as($otherUser))->assertForbidden();
    }

    public function test_task_can_only_be_assigned_to_project_members(): void
    {
        [$outsider] = $this->person();

        $this->postJson("/api/v1/projects/{$this->project->id}/tasks", [
            'title' => 'X', 'assignee_employee_id' => $outsider->id, 'priority' => 'low', 'points' => 1,
            'due_date' => now()->addDay()->toDateString(),
        ], $this->as($this->leadUser))->assertStatus(422)->assertJsonValidationErrors('assignee_employee_id');
    }

    public function test_lead_cannot_approve_their_own_task_but_a_project_manager_can(): void
    {
        [, $hr] = $this->person('HR');
        $taskId = $this->assign(['assignee_employee_id' => $this->lead->id]);
        $this->postJson("/api/v1/tasks/{$taskId}/submit", [], $this->as($this->leadUser))->assertOk();

        // Người duyệt việc của Lead là người quản lý dự án -> được báo.
        $this->assertTrue(Notification::where('user_id', $hr->id)->where('type', 'task.submitted')->exists());

        $this->postJson("/api/v1/tasks/{$taskId}/review", ['decision' => 'approve', 'quality_score' => 5], $this->as($this->leadUser))->assertForbidden();
        $this->postJson("/api/v1/tasks/{$taskId}/review", ['decision' => 'approve', 'quality_score' => 5], $this->as($hr))->assertOk();
    }

    public function test_late_submission_is_marked_not_on_time(): void
    {
        $taskId = $this->assign(['due_date' => now()->toDateString()]);
        $this->travel(2)->days();

        $this->postJson("/api/v1/tasks/{$taskId}/submit", [], $this->as($this->memberUser))->assertOk();
        $this->postJson("/api/v1/tasks/{$taskId}/review", ['decision' => 'approve', 'quality_score' => 3], $this->as($this->leadUser))
            ->assertOk()->assertJsonPath('data.on_time', false);
    }

    public function test_only_untouched_tasks_can_be_deleted_others_are_cancelled(): void
    {
        $taskId = $this->assign();
        $this->postJson("/api/v1/tasks/{$taskId}/start", [], $this->as($this->memberUser))->assertOk();

        $this->deleteJson("/api/v1/tasks/{$taskId}", [], $this->as($this->leadUser))->assertStatus(422);
        $this->postJson("/api/v1/tasks/{$taskId}/cancel", [], $this->as($this->leadUser))->assertOk()->assertJsonPath('data.status', 'cancelled');

        $fresh = $this->assign();
        $this->deleteJson("/api/v1/tasks/{$fresh}", [], $this->as($this->leadUser))->assertOk();
        $this->assertSoftDeleted('project_tasks', ['id' => $fresh]);
    }

    public function test_closed_projects_do_not_accept_new_tasks(): void
    {
        $this->project->update(['status' => 'completed']);

        $this->postJson("/api/v1/projects/{$this->project->id}/tasks", [
            'title' => 'X', 'priority' => 'low', 'points' => 1, 'due_date' => now()->addDay()->toDateString(),
        ], $this->as($this->leadUser))->assertStatus(422);
    }

    public function test_reminders_are_sent_once_for_due_soon_and_overdue_tasks(): void
    {
        $dueSoon = ProjectTask::create(['project_id' => $this->project->id, 'title' => 'Sap toi han', 'assignee_employee_id' => $this->member->id, 'due_date' => now()->addDay()]);
        $overdue = ProjectTask::create(['project_id' => $this->project->id, 'title' => 'Qua han', 'assignee_employee_id' => $this->member->id, 'due_date' => now()->subDay(), 'status' => 'in_progress']);
        ProjectTask::create(['project_id' => $this->project->id, 'title' => 'Da xong', 'assignee_employee_id' => $this->member->id, 'due_date' => now()->subDay(), 'status' => 'done']);

        $service = app(ProjectTaskService::class);
        $first = $service->sendReminders();
        $this->assertSame([1, 1], [$first['due_soon'], $first['overdue']]);
        $this->assertSame(0, array_sum($service->sendReminders()));

        $this->assertSame(1, Notification::where('user_id', $this->memberUser->id)->where('type', 'task.due_soon')->count());
        $this->assertSame(1, Notification::where('user_id', $this->memberUser->id)->where('type', 'task.overdue')->count());
        $this->assertSame(1, Notification::where('user_id', $this->leadUser->id)->where('type', 'task.overdue')->count());
        $this->assertNotNull($dueSoon->fresh()->due_soon_notified_at);
        $this->assertNotNull($overdue->fresh()->overdue_notified_at);
    }
}
