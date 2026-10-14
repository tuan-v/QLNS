<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Dự án: ai tạo được, ai thấy được, Lead quản lý thành viên (2026-10-14, giai đoạn A).
class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function person(string $role = 'Employee'): array
    {
        $user = User::create([
            'email' => 'prj-'.uniqid().'@qlns.local', 'user_name' => 'Prj User',
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

    private function createProject(User $hr, Employee $lead): int
    {
        return $this->postJson('/api/v1/projects', [
            'name' => 'Du an QLNS', 'lead_employee_id' => $lead->id, 'start_date' => now()->toDateString(),
        ], $this->as($hr))->assertCreated()->json('data.id');
    }

    public function test_hr_creates_a_project_lead_becomes_member_and_is_notified(): void
    {
        [, $hr] = $this->person('HR');
        [$lead, $leadUser] = $this->person();

        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Du an QLNS', 'lead_employee_id' => $lead->id, 'start_date' => now()->toDateString(),
        ], $this->as($hr));

        $response->assertCreated()
            ->assertJsonPath('data.code', 'DA-001')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.members.0.role', 'lead')
            ->assertJsonPath('data.members.0.employee.id', $lead->id);
        $this->assertTrue(Notification::where('user_id', $leadUser->id)->where('type', 'project.lead_assigned')->exists());
    }

    public function test_plain_employee_cannot_create_projects(): void
    {
        [$employee, $user] = $this->person();

        $this->postJson('/api/v1/projects', [
            'name' => 'X', 'lead_employee_id' => $employee->id, 'start_date' => now()->toDateString(),
        ], $this->as($user))->assertForbidden();
    }

    public function test_employees_only_see_projects_they_belong_to(): void
    {
        [, $hr] = $this->person('HR');
        [$lead, $leadUser] = $this->person();
        [, $outsider] = $this->person();
        $projectId = $this->createProject($hr, $lead);

        $this->getJson('/api/v1/projects', $this->as($leadUser))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.my_role', 'lead');
        $this->getJson('/api/v1/projects', $this->as($outsider))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/projects/{$projectId}", $this->as($outsider))->assertForbidden();
        $this->getJson('/api/v1/projects', $this->as($hr))->assertJsonCount(1, 'data');
    }

    public function test_lead_adds_and_removes_members_but_a_member_cannot(): void
    {
        [, $hr] = $this->person('HR');
        [$lead, $leadUser] = $this->person();
        [$memberA, $memberAUser] = $this->person();
        [$memberB] = $this->person();
        $projectId = $this->createProject($hr, $lead);

        $this->getJson("/api/v1/projects/{$projectId}/member-candidates?search=".urlencode($memberA->full_name), $this->as($leadUser))
            ->assertOk()->assertJsonPath('data.0.id', $memberA->id);

        $this->postJson("/api/v1/projects/{$projectId}/members", ['employee_ids' => [$memberA->id]], $this->as($leadUser))
            ->assertOk()->assertJsonPath('added', 1);
        $this->assertTrue(Notification::where('user_id', $memberAUser->id)->where('type', 'project.member_added')->exists());

        // Thành viên thường không thêm người được.
        $this->postJson("/api/v1/projects/{$projectId}/members", ['employee_ids' => [$memberB->id]], $this->as($memberAUser))
            ->assertForbidden();

        // Không xóa được Lead; xóa thành viên bình thường được.
        $this->deleteJson("/api/v1/projects/{$projectId}/members/{$lead->id}", [], $this->as($leadUser))->assertStatus(422);
        $this->deleteJson("/api/v1/projects/{$projectId}/members/{$memberA->id}", [], $this->as($leadUser))->assertOk();
    }

    public function test_member_with_open_tasks_cannot_be_removed(): void
    {
        [, $hr] = $this->person('HR');
        [$lead, $leadUser] = $this->person();
        [$member] = $this->person();
        $projectId = $this->createProject($hr, $lead);
        $this->postJson("/api/v1/projects/{$projectId}/members", ['employee_ids' => [$member->id]], $this->as($leadUser));
        ProjectTask::create(['project_id' => $projectId, 'title' => 'Viec', 'assignee_employee_id' => $member->id, 'due_date' => now()->addDays(3)]);

        $this->deleteJson("/api/v1/projects/{$projectId}/members/{$member->id}", [], $this->as($leadUser))
            ->assertStatus(422)->assertJsonValidationErrors('employee');
    }

    public function test_changing_the_lead_keeps_the_old_lead_as_a_member(): void
    {
        [, $hr] = $this->person('HR');
        [$oldLead] = $this->person();
        [$newLead, $newLeadUser] = $this->person();
        $projectId = $this->createProject($hr, $oldLead);

        $this->putJson("/api/v1/projects/{$projectId}", ['lead_employee_id' => $newLead->id], $this->as($hr))->assertOk();

        $project = Project::find($projectId);
        $this->assertSame($newLead->id, $project->lead_employee_id);
        $this->assertSame('member', $project->members()->where('employee_id', $oldLead->id)->value('role'));
        $this->assertSame('lead', $project->members()->where('employee_id', $newLead->id)->value('role'));
        $this->assertTrue(Notification::where('user_id', $newLeadUser->id)->where('type', 'project.lead_assigned')->exists());
    }

    public function test_lead_can_only_change_status_description_and_end_date(): void
    {
        [, $hr] = $this->person('HR');
        [$lead, $leadUser] = $this->person();
        [$other] = $this->person();
        $projectId = $this->createProject($hr, $lead);

        $this->putJson("/api/v1/projects/{$projectId}", [
            'name' => 'Doi ten', 'lead_employee_id' => $other->id, 'status' => 'on_hold',
        ], $this->as($leadUser))->assertOk();

        $project = Project::find($projectId);
        $this->assertSame('Du an QLNS', $project->name);
        $this->assertSame($lead->id, $project->lead_employee_id);
        $this->assertSame('on_hold', $project->status);
    }

    public function test_project_with_completed_tasks_cannot_be_deleted(): void
    {
        [, $hr] = $this->person('HR');
        [$lead] = $this->person();
        $projectId = $this->createProject($hr, $lead);
        ProjectTask::create(['project_id' => $projectId, 'title' => 'Viec', 'due_date' => now(), 'status' => 'done']);

        $this->deleteJson("/api/v1/projects/{$projectId}", [], $this->as($hr))->assertStatus(422);
    }
}
