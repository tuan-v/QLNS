<?php

namespace Tests\Feature\Project;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\Role;
use App\Models\User;
use App\Services\KpiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// KPI tháng từ công việc dự án (giai đoạn B): công thức 40/30/30 theo điểm độ khó, tính theo hạn chót,
// phạm vi xem (HR / Trưởng phòng / Lead / chính mình), tự chốt sau khi hết tháng.
class KpiTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    private Department $department;

    private Project $project;

    private Project $otherProject;

    private Employee $lead;

    private User $leadUser;

    private Employee $member;

    private User $memberUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(Carbon::parse('2026-10-20 10:00'));

        $this->department = Department::create(['name' => 'Phong KPI', 'code' => 'KPI-1']);
        [$this->lead, $this->leadUser] = $this->person();
        [$this->member, $this->memberUser] = $this->person(['department_id' => $this->department->id]);

        $this->project = $this->project('DA-K1', $this->lead);
        $this->otherProject = $this->project('DA-K2', $this->person()[0]);
        ProjectMember::create(['project_id' => $this->project->id, 'employee_id' => $this->member->id, 'role' => 'member']);
        ProjectMember::create(['project_id' => $this->otherProject->id, 'employee_id' => $this->member->id, 'role' => 'member']);
    }

    private function person(array $overrides = [], string $role = 'Employee'): array
    {
        $user = User::create([
            'email' => 'kpi-'.uniqid().'@qlns.local', 'user_name' => 'Kpi User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', $role)->first()->users()->attach($user->id);
        $employee = Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(), 'company_email' => uniqid().'@qlns.local',
            'hire_date' => '2025-01-06', 'code' => 'NV-'.uniqid(), 'employment_status' => 'active', 'user_id' => $user->id,
        ], $overrides));

        return [$employee, $user];
    }

    private function project(string $code, Employee $lead, string $status = 'active'): Project
    {
        $project = Project::create([
            'code' => $code, 'name' => 'Du an '.$code, 'lead_employee_id' => $lead->id,
            'start_date' => '2026-09-01', 'status' => $status,
        ]);
        ProjectMember::create(['project_id' => $project->id, 'employee_id' => $lead->id, 'role' => 'lead']);

        return $project;
    }

    private function task(Project $project, int $points, string $due, string $status, ?string $submitted = null, ?int $quality = null): ProjectTask
    {
        return ProjectTask::create([
            'project_id' => $project->id, 'title' => 'Viec '.uniqid(), 'assignee_employee_id' => $this->member->id,
            'priority' => 'medium', 'points' => $points, 'start_date' => '2026-10-01', 'due_date' => $due,
            'status' => $status, 'submitted_at' => $submitted, 'quality_score' => $quality,
            'completed_at' => $status === 'done' ? $submitted : null,
        ]);
    }

    private function as(User $user): array
    {
        $this->tokens[$user->id] ??= $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret@123'])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$user->id]];
    }

    private function hr(): array
    {
        $this->tokens['hr'] ??= $this->postJson('/api/v1/auth/login', ['email' => 'hr@qlns.local', 'password' => 'Hr@123456'])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens['hr']];
    }

    private function october(array $headers, string $query = ''): array
    {
        return $this->getJson('/api/v1/kpi?year=2026&month=10'.$query, $headers)->assertOk()->json();
    }

    public function test_score_is_40_30_30_weighted_by_points_and_counted_by_due_date(): void
    {
        $this->task($this->project, 8, '2026-10-10', 'done', '2026-10-09 16:00', 4);       // đúng hạn, mức 4
        $this->task($this->project, 5, '2026-10-12', 'done', '2026-10-14 09:00', 5);       // trễ hạn, mức 5
        $this->task($this->project, 3, '2026-10-15', 'in_progress');                         // quá hạn chưa xong
        $this->task($this->project, 2, '2026-10-16', 'cancelled');                           // đã hủy: bỏ
        $this->task($this->project, 5, '2026-11-03', 'done', '2026-10-18 10:00', 5);       // hạn tháng 11: không tính tháng 10
        $cancelledProject = $this->project('DA-K3', $this->lead, 'cancelled');
        $this->task($cancelledProject, 8, '2026-10-10', 'todo');                             // dự án đã hủy: bỏ

        $item = collect($this->october($this->hr())['items'])->firstWhere('employee.id', $this->member->id);

        // Hoàn thành 13/16 = 81,25% · Đúng hạn 8/13 = 61,54% · Chất lượng (8×4 + 5×5)/13 = 4,38 → 87,69%
        // KPI = 0,4×81,25 + 0,3×61,54 + 0,3×87,69 = 77,3 → loại B
        $this->assertSame(3, $item['task_count']);
        $this->assertSame(16, $item['points_due']);
        $this->assertEquals(81.3, $item['completion_rate']);
        $this->assertEquals(61.5, $item['on_time_rate']);
        $this->assertEquals(4.38, $item['quality_avg']);
        $this->assertEquals(77.3, $item['score']);
        $this->assertSame('B', $item['grade']['key']);
        $this->assertFalse($item['partial']);
    }

    public function test_detail_lists_counted_tasks_per_project(): void
    {
        $this->task($this->project, 3, '2026-10-10', 'done', '2026-10-10 17:00', 5);
        $this->task($this->otherProject, 2, '2026-10-11', 'todo');

        $data = $this->getJson("/api/v1/kpi/employees/{$this->member->id}?year=2026&month=10", $this->hr())->assertOk()->json();

        $this->assertCount(2, $data['projects']);
        $this->assertCount(2, $data['tasks']);
        $this->assertTrue($data['tasks'][0]['on_time']);
        $this->assertSame('DA-K1', $data['tasks'][0]['project']['code']);
        $this->assertSame('provisional', $data['status']);
        $this->assertSame('2026-11-06', $data['lock_date']);
    }

    public function test_scope_manager_lead_and_self(): void
    {
        $this->task($this->project, 3, '2026-10-10', 'done', '2026-10-09 10:00', 4);
        $this->task($this->otherProject, 5, '2026-10-10', 'todo');

        // Trưởng phòng của phòng nhân viên: thấy đủ cả 2 dự án.
        [$manager, $managerUser] = $this->person([], 'Manager');
        $this->department->update(['manager_id' => $manager->id]);
        $managerItem = collect($this->october($this->as($managerUser))['items'])->firstWhere('employee.id', $this->member->id);
        $this->assertSame(8, $managerItem['points_due']);
        $this->assertFalse($managerItem['partial']);

        // Lead dự án 1: chỉ thấy phần việc trong dự án mình, đánh dấu partial.
        $leadItems = collect($this->october($this->as($this->leadUser))['items']);
        $this->assertSame(3, $leadItems->firstWhere('employee.id', $this->member->id)['points_due']);
        $this->assertTrue($leadItems->firstWhere('employee.id', $this->member->id)['partial']);
        $this->assertSame(['DA-K1'], collect($this->getJson('/api/v1/kpi/filters', $this->as($this->leadUser))->json('projects'))->pluck('code')->all());

        // Nhân viên: chỉ thấy chính mình; xem người khác thì 403.
        [$colleague, $colleagueUser] = $this->person();
        $this->assertSame([], $this->october($this->as($colleagueUser))['items']);
        $this->getJson("/api/v1/kpi/employees/{$this->member->id}?year=2026&month=10", $this->as($colleagueUser))->assertForbidden();
        $this->getJson("/api/v1/kpi/employees/{$this->member->id}?year=2026&month=10", $this->as($this->memberUser))
            ->assertOk()->assertJsonPath('metrics.points_due', 8);
        $this->assertSame('self', $this->getJson('/api/v1/kpi/filters', $this->as($colleagueUser))->json('scope'));
    }

    public function test_month_locks_after_grace_days_and_stops_changing(): void
    {
        $task = $this->task($this->project, 5, '2026-10-10', 'done', '2026-10-09 10:00', 3);
        $kpi = app(KpiService::class);

        $this->assertSame([], $kpi->lockDueMonths(Carbon::parse('2026-11-05 23:00')));
        $this->assertSame(['2026-10'], $kpi->lockDueMonths(Carbon::parse('2026-11-06 00:15')));
        $this->assertSame([], $kpi->lockDueMonths(Carbon::parse('2026-11-07 00:15'))); // chạy lại không chốt trùng

        $task->update(['quality_score' => 5]); // sửa sau khi đã chốt
        $data = $this->october($this->hr());

        $this->assertSame('locked', $data['status']);
        $this->assertEquals(3, $data['items'][0]['quality_avg']);
    }

    public function test_period_is_required(): void
    {
        $this->getJson('/api/v1/kpi?year=2026', $this->hr())->assertStatus(422)->assertJsonValidationErrors('month');
        $this->getJson('/api/v1/kpi?year=2026&month=13', $this->hr())->assertStatus(422);
    }
}
