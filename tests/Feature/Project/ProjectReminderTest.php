<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\ProjectTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Nhắc theo ngày + thông báo khi có thay đổi trong module Dự án (2026-10-15).
class ProjectReminderTest extends TestCase
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
            'code' => 'DA-001', 'name' => 'Du an nhac', 'lead_employee_id' => $this->lead->id,
            'start_date' => now()->subMonth(), 'status' => 'active',
        ]);
        ProjectMember::create(['project_id' => $this->project->id, 'employee_id' => $this->lead->id, 'role' => 'lead']);
        ProjectMember::create(['project_id' => $this->project->id, 'employee_id' => $this->member->id, 'role' => 'member']);
    }

    private function person(string $role = 'Employee'): array
    {
        $user = User::create([
            'email' => 'rem-'.uniqid().'@qlns.local', 'user_name' => 'Rem User',
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

    private function task(array $attributes): ProjectTask
    {
        return ProjectTask::create(array_merge([
            'project_id' => $this->project->id, 'title' => 'Viec '.uniqid(),
            'assignee_employee_id' => $this->member->id, 'due_date' => now()->addDays(10)->toDateString(),
        ], $attributes));
    }

    private function notified(User $user, string $type): int
    {
        return Notification::where('user_id', $user->id)->where('type', $type)->count();
    }

    // ---------------------------------------------------------------- nhắc theo ngày

    public function test_start_day_and_due_today_are_reminded_once(): void
    {
        $this->task(['start_date' => now()->toDateString()]);
        $this->task(['start_date' => now()->toDateString(), 'status' => 'in_progress']); // đã bắt đầu -> không nhắc
        $this->task(['due_date' => now()->toDateString(), 'status' => 'in_progress']);
        $this->task(['due_date' => now()->toDateString(), 'status' => 'in_review']); // đã nộp -> không nhắc

        $service = app(ProjectTaskService::class);
        $result = $service->sendReminders();
        $this->assertSame(1, $result['start_today']);
        $this->assertSame(1, $result['due_today']);
        $this->assertSame(0, array_sum($service->sendReminders()));

        $this->assertSame(1, $this->notified($this->memberUser, 'task.start_today'));
        $this->assertSame(1, $this->notified($this->memberUser, 'task.due_today'));
    }

    public function test_long_pending_review_reminds_the_lead_and_resubmitting_resets_it(): void
    {
        $task = $this->task(['status' => 'in_review', 'submitted_at' => now()->subDays(3)]);
        $this->task(['status' => 'in_review', 'submitted_at' => now()->subDay()]); // mới nộp 1 ngày -> chưa nhắc

        $service = app(ProjectTaskService::class);
        $this->assertSame(1, $service->sendReminders()['review_pending']);
        $this->assertSame(1, $this->notified($this->leadUser, 'task.review_pending'));
        $this->assertSame(0, $service->sendReminders()['review_pending']);

        // Trả lại rồi nộp lại -> đếm lại từ đầu.
        $this->postJson("/api/v1/tasks/{$task->id}/review", ['decision' => 'return', 'note' => 'Sua'], $this->as($this->leadUser))->assertOk();
        $this->postJson("/api/v1/tasks/{$task->id}/submit", [], $this->as($this->memberUser))->assertOk();
        $this->assertNull($task->fresh()->review_reminded_at);
    }

    public function test_on_hold_projects_get_no_task_reminders(): void
    {
        $this->project->update(['status' => 'on_hold']);
        $this->task(['due_date' => now()->toDateString()]);

        $this->assertSame(0, array_sum(app(ProjectTaskService::class)->sendReminders()));
    }

    public function test_project_start_ending_soon_and_overdue_reminders(): void
    {
        [, $hr] = $this->person('HR');
        $service = app(ProjectService::class);

        // Tới ngày bắt đầu mà còn "Lên kế hoạch" -> nhắc Lead, KHÔNG tự đổi trạng thái.
        $this->project->update(['status' => 'planning', 'start_date' => now()->toDateString()]);
        $this->assertSame(1, $service->sendProjectReminders()['start']);
        $this->assertSame('planning', $this->project->fresh()->status);
        $this->assertSame(1, $this->notified($this->leadUser, 'project.start_due'));

        // Còn 2 ngày kết thúc, còn việc mở -> nhắc Lead.
        $this->project->update(['status' => 'active', 'end_date' => now()->addDays(2)->toDateString()]);
        $this->task([]);
        $this->assertSame(1, $service->sendProjectReminders()['ending_soon']);
        $this->assertSame(1, $this->notified($this->leadUser, 'project.ending_soon'));

        // Quá ngày kết thúc -> Lead + người quản lý dự án.
        $this->project->update(['end_date' => now()->subDay()->toDateString()]);
        $this->assertSame(1, $service->sendProjectReminders()['overdue']);
        $this->assertSame(1, $this->notified($this->leadUser, 'project.overdue'));
        $this->assertSame(1, $this->notified($hr, 'project.overdue'));
        $this->assertSame(0, array_sum($service->sendProjectReminders()));
    }

    public function test_changing_project_dates_rearms_the_reminders(): void
    {
        [, $hr] = $this->person('HR');
        $this->project->update(['end_date' => now()->subDay()->toDateString()]);
        app(ProjectService::class)->sendProjectReminders();
        $this->assertNotNull($this->project->fresh()->overdue_notified_at);

        $this->putJson("/api/v1/projects/{$this->project->id}", ['end_date' => now()->addMonth()->toDateString()], $this->as($hr))->assertOk();
        $this->assertNull($this->project->fresh()->overdue_notified_at);
    }

    // ---------------------------------------------------------------- báo khi thay đổi

    public function test_reassigning_tells_the_old_assignee_and_due_change_tells_the_assignee(): void
    {
        [$other, $otherUser] = $this->person();
        ProjectMember::create(['project_id' => $this->project->id, 'employee_id' => $other->id, 'role' => 'member']);
        $task = $this->task([]);

        $this->putJson("/api/v1/tasks/{$task->id}", ['due_date' => now()->addDays(20)->toDateString()], $this->as($this->leadUser))->assertOk();
        $this->assertSame(1, $this->notified($this->memberUser, 'task.due_changed'));

        $this->putJson("/api/v1/tasks/{$task->id}", ['assignee_employee_id' => $other->id], $this->as($this->leadUser))->assertOk();
        $this->assertSame(1, $this->notified($this->memberUser, 'task.unassigned'));
        $this->assertSame(1, $this->notified($otherUser, 'task.assigned'));
    }

    public function test_cancel_and_delete_tell_the_assignee(): void
    {
        $cancelled = $this->task(['status' => 'in_progress']);
        $deleted = $this->task([]);

        $this->postJson("/api/v1/tasks/{$cancelled->id}/cancel", [], $this->as($this->leadUser))->assertOk();
        $this->deleteJson("/api/v1/tasks/{$deleted->id}", [], $this->as($this->leadUser))->assertOk();

        $this->assertSame(1, $this->notified($this->memberUser, 'task.cancelled'));
        $this->assertSame(1, $this->notified($this->memberUser, 'task.deleted'));
    }

    public function test_member_removal_and_project_status_change_are_notified(): void
    {
        $this->putJson("/api/v1/projects/{$this->project->id}", ['status' => 'on_hold'], $this->as($this->leadUser))->assertOk();
        $this->assertSame(1, $this->notified($this->memberUser, 'project.status_changed'));
        $this->assertSame(0, $this->notified($this->leadUser, 'project.status_changed')); // người thao tác không tự báo

        $this->putJson("/api/v1/projects/{$this->project->id}", ['status' => 'active'], $this->as($this->leadUser))->assertOk();
        $this->deleteJson("/api/v1/projects/{$this->project->id}/members/{$this->member->id}", [], $this->as($this->leadUser))->assertOk();
        $this->assertSame(1, $this->notified($this->memberUser, 'project.member_removed'));
    }
}
