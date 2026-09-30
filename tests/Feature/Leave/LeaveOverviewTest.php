<?php

namespace Tests\Feature\Leave;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "Tổng hợp nghỉ phép" (mục 52 CODE_MAP): mỗi nhân viên 1 dòng — quỹ phép năm,
// nghỉ khác/không lương đã duyệt, đơn chờ duyệt, đợt nghỉ hiện tại/sắp tới.
class LeaveOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function auth(string $email, string $password): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    private function hr(): array
    {
        return $this->auth('hr@qlns.local', 'Hr@123456');
    }

    private function makeEmployee(string $name, ?Department $department = null, array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'full_name' => $name,
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now()->subYears(3),
            'code' => 'NV-'.uniqid(),
            'employment_status' => 'active',
            'department_id' => ($department ?? Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]))->id,
        ], $overrides));
    }

    private function leave(Employee $employee, string $typeCode, string $from, string $to, float $days, string $status): LeaveRequest
    {
        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::where('code', $typeCode)->value('id'),
            'from_date' => $from, 'to_date' => $to, 'total_days' => $days,
            'reason' => 'Viec rieng', 'status' => $status,
        ]);
    }

    public function test_requires_authentication_and_view_all_permission(): void
    {
        $this->getJson('/api/v1/leave-requests/overview')->assertStatus(401);

        $user = User::create([
            'email' => 'plain@qlns.local', 'user_name' => 'Plain', 'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);

        $this->getJson('/api/v1/leave-requests/overview', $this->auth('plain@qlns.local', 'Secret@123'))->assertStatus(403);
    }

    public function test_row_shows_annual_balance_other_unpaid_days_and_pending_requests(): void
    {
        $employee = $this->makeEmployee('An Nguyen');
        $annual = LeaveType::where('code', 'annual')->first();
        LeaveBalance::create([
            'employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => now()->year,
            'allocated_days' => 12, 'used_days' => 3,
        ]);
        $this->leave($employee, 'other', now()->year.'-02-02', now()->year.'-02-03', 2, 'approved');
        $this->leave($employee, 'unpaid', now()->year.'-03-02', now()->year.'-03-02', 1, 'approved');
        $this->leave($employee, 'annual', now()->addDays(20)->toDateString(), now()->addDays(21)->toDateString(), 2, 'pending');
        $this->leave($employee, 'annual', now()->year.'-04-01', now()->year.'-04-01', 1, 'rejected');

        $response = $this->getJson('/api/v1/leave-requests/overview?search=An', $this->hr())->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('employee.id', $employee->id);
        $this->assertEquals(12, $row['annual']['allocated_days']);
        $this->assertEquals(3, $row['annual']['used_days']);
        $this->assertEquals(2, $row['annual']['pending_days']);
        $this->assertEquals(9, $row['annual']['remaining_days']);
        $this->assertEquals(2, $row['other_days']);
        $this->assertEquals(1, $row['unpaid_days']);
        $this->assertSame(1, $row['pending_requests']);
    }

    public function test_marks_who_is_on_leave_today_and_shows_the_next_leave(): void
    {
        $onLeave = $this->makeEmployee('Dang nghi');
        $upcoming = $this->makeEmployee('Sap nghi');
        $none = $this->makeEmployee('Khong nghi');
        $this->leave($onLeave, 'annual', now()->subDay()->toDateString(), now()->addDay()->toDateString(), 3, 'approved');
        $this->leave($upcoming, 'annual', now()->addDays(10)->toDateString(), now()->addDays(11)->toDateString(), 2, 'approved');

        $response = $this->getJson('/api/v1/leave-requests/overview?per_page=100', $this->hr())->assertStatus(200);
        $rows = collect($response->json('data'));

        $this->assertTrue($rows->firstWhere('employee.id', $onLeave->id)['on_leave_today']);
        $this->assertFalse($rows->firstWhere('employee.id', $upcoming->id)['on_leave_today']);
        $this->assertSame(now()->addDays(10)->toDateString(), $rows->firstWhere('employee.id', $upcoming->id)['next_leave']['from_date']);
        $this->assertNull($rows->firstWhere('employee.id', $none->id)['next_leave']);
        $this->assertSame(1, $response->json('summary.on_leave_today'));
    }

    public function test_filters_by_department_and_summary_covers_all_matching_employees(): void
    {
        $dept = Department::create(['name' => 'Ke toan', 'code' => 'PB-KT']);
        $a = $this->makeEmployee('A', $dept);
        $b = $this->makeEmployee('B', $dept);
        $this->makeEmployee('C ngoai phong');
        $this->leave($a, 'annual', now()->year.'-05-05', now()->year.'-05-06', 2, 'approved');
        $this->leave($b, 'annual', now()->year.'-06-05', now()->year.'-06-05', 1, 'approved');
        $this->leave($b, 'annual', now()->addDays(30)->toDateString(), now()->addDays(30)->toDateString(), 1, 'manager_approved');

        $response = $this->getJson("/api/v1/leave-requests/overview?department_id={$dept->id}&per_page=1", $this->hr())->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(2, $response->json('summary.total_employees'));
        $this->assertEquals(3, $response->json('summary.days_used'));
        $this->assertSame(1, $response->json('summary.pending_requests'));
    }

    public function test_excludes_resigned_employees_and_other_years_do_not_count(): void
    {
        $gone = $this->makeEmployee('Da nghi viec', null, ['employment_status' => 'resigned']);
        $active = $this->makeEmployee('Van lam');
        $this->leave($active, 'other', (now()->year - 1).'-05-05', (now()->year - 1).'-05-05', 1, 'approved');

        $response = $this->getJson('/api/v1/leave-requests/overview?per_page=100', $this->hr())->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('employee.id');

        $this->assertFalse($ids->contains($gone->id));
        $this->assertEquals(0, collect($response->json('data'))->firstWhere('employee.id', $active->id)['other_days']);
    }
}
