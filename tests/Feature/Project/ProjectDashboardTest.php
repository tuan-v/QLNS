<?php

namespace Tests\Feature\Project;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Trang Tổng quan (2026-10-15): công việc hôm nay, việc chờ duyệt, dự án cần chú ý, nghỉ lễ sắp tới.
class ProjectDashboardTest extends TestCase
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
            'email' => 'dash-'.uniqid().'@qlns.local', 'user_name' => 'Dash User',
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

    private function dashboard(User $user): array
    {
        $this->tokens[$user->id] ??= $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret@123'])->json('access_token');

        return $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer '.$this->tokens[$user->id]])->assertOk()->json();
    }

    private function project(Employee $lead, array $attributes = []): Project
    {
        $project = Project::create(array_merge([
            'code' => 'DA-'.uniqid(), 'name' => 'Du an '.uniqid(), 'lead_employee_id' => $lead->id,
            'start_date' => now()->subMonth(), 'status' => 'active',
        ], $attributes));
        ProjectMember::create(['project_id' => $project->id, 'employee_id' => $lead->id, 'role' => 'lead']);

        return $project;
    }

    private function task(Project $project, Employee $assignee, array $attributes = []): ProjectTask
    {
        ProjectMember::firstOrCreate(['project_id' => $project->id, 'employee_id' => $assignee->id], ['role' => 'member']);

        return ProjectTask::create(array_merge([
            'project_id' => $project->id, 'title' => 'Viec '.uniqid(), 'assignee_employee_id' => $assignee->id,
            'due_date' => now()->addDays(10)->toDateString(),
        ], $attributes));
    }

    public function test_my_work_counts_and_focus_list(): void
    {
        [$lead] = $this->person();
        [$member, $memberUser] = $this->person();
        $project = $this->project($lead);
        $this->task($project, $member, ['title' => 'Qua han', 'due_date' => now()->subDay()->toDateString(), 'status' => 'in_progress']);
        $this->task($project, $member, ['title' => 'Han hom nay', 'due_date' => now()->toDateString()]);
        $this->task($project, $member, ['title' => 'Bat dau hom nay', 'start_date' => now()->toDateString()]);
        $this->task($project, $member, ['title' => 'Cho duyet', 'status' => 'in_review', 'submitted_at' => now()]);
        $this->task($project, $member, ['title' => 'Xa', 'due_date' => now()->addMonth()->toDateString()]);
        $this->task($project, $member, ['title' => 'Xong', 'status' => 'done']);

        $work = $this->dashboard($memberUser)['my_work'];

        $this->assertSame(['overdue' => 1, 'due_today' => 1, 'start_today' => 1, 'in_progress' => 1, 'waiting_review' => 1, 'open' => 5], $work['counts']);
        // Thứ tự gấp: quá hạn -> hạn hôm nay -> bắt đầu hôm nay; việc còn xa không hiện.
        $this->assertSame(['Qua han', 'Han hom nay', 'Bat dau hom nay'], array_column($work['focus'], 'title'));
        $this->assertSame(['overdue', 'due_today', 'start_today'], array_column($work['focus'], 'reason'));
    }

    public function test_lead_sees_tasks_waiting_for_their_review_and_manager_sees_the_leads_own(): void
    {
        [, $hr] = $this->person('HR');
        [$lead, $leadUser] = $this->person();
        [$member] = $this->person();
        $project = $this->project($lead);
        $this->task($project, $member, ['status' => 'in_review', 'submitted_at' => now()]);
        $this->task($project, $lead, ['status' => 'in_review', 'submitted_at' => now()]); // việc của chính Lead

        $leadWork = $this->dashboard($leadUser)['my_work'];
        $this->assertSame(1, $leadWork['to_review']['count']);
        $this->assertSame($member->full_name, $leadWork['to_review']['items'][0]['assignee']);

        $hrDashboard = $this->dashboard($hr);
        $this->assertSame(1, $hrDashboard['my_work']['to_review']['count']);
        $this->assertContains('project_task_review', array_column($hrDashboard['action_items'], 'type'));
    }

    public function test_projects_needing_attention_are_listed_for_project_managers_only(): void
    {
        [, $hr] = $this->person('HR');
        [$lead, $leadUser] = $this->person();
        [$member] = $this->person();
        $late = $this->project($lead, ['name' => 'Tre han', 'end_date' => now()->subDay()->toDateString()]);
        $this->task($late, $member);
        $withOverdueTask = $this->project($lead, ['name' => 'Co viec qua han']);
        $this->task($withOverdueTask, $member, ['due_date' => now()->subDays(2)->toDateString()]);
        $this->project($lead, ['name' => 'On dinh']);

        $attention = $this->dashboard($hr)['projects_attention'];
        $this->assertSame(['Tre han', 'Co viec qua han'], array_column($attention, 'name'));
        $this->assertSame('past_end', $attention[0]['reasons'][0]['key']);

        $this->assertSame([], $this->dashboard($leadUser)['projects_attention']);
    }

    public function test_employees_only_see_confirmed_upcoming_holidays(): void
    {
        [, $employeeUser] = $this->person();
        [, $hr] = $this->person('HR');
        Holiday::query()->delete();
        Holiday::create(['holiday_date' => now()->addDays(5)->toDateString(), 'name' => 'Le da xac nhan', 'source' => 'manual', 'is_paid' => true, 'confirmed_at' => now()]);
        Holiday::create(['holiday_date' => now()->addDays(9)->toDateString(), 'name' => 'Le chua xac nhan', 'source' => 'manual', 'is_paid' => true]);

        $forEmployee = $this->dashboard($employeeUser)['upcoming_holidays'];
        $this->assertSame(['Le da xac nhan'], array_column($forEmployee, 'name'));
        $this->assertSame(5, $forEmployee[0]['days_until']);

        $this->assertSame(['Le da xac nhan', 'Le chua xac nhan'], array_column($this->dashboard($hr)['upcoming_holidays'], 'name'));
    }
}
