<?php

namespace Tests\Feature\Security;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeShiftAssignment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Phạm vi theo phòng ban (2026-10-22, quyết định người dùng): Trưởng phòng chỉ thấy phòng mình quản lý + phòng
// con ở Nhân viên, Tổng quan, Chấm công, Nghỉ phép, Phòng ban/Chức vụ, Tìm kiếm; không thấy lương. HR/Admin
// vẫn xem cả công ty. Xem DepartmentScopeService + middleware employee.scope.
class DepartmentScopeTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    private Employee $head;

    private Employee $inOwn;

    private Employee $inChild;

    private Employee $outside;

    private Department $own;

    private Department $child;

    private Department $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->travelTo(Carbon::parse('2026-10-14 09:00')); // Thứ Tư

        $managerUser = User::where('email', 'manager@qlns.local')->firstOrFail();
        $this->own = Department::create(['name' => 'Phong Ky thuat', 'code' => 'PB-KT']);
        $this->child = Department::create(['name' => 'To Backend', 'code' => 'PB-BE', 'parent_id' => $this->own->id]);
        $this->other = Department::create(['name' => 'Phong Ke toan', 'code' => 'PB-KTO']);

        $this->head = $this->employee('Truong Phong', 'NV-TP', $this->own, ['user_id' => $managerUser->id]);
        $this->own->update(['manager_id' => $this->head->id]);
        $this->inOwn = $this->employee('Nhan Vien KT', 'NV-KT', $this->own);
        $this->inChild = $this->employee('Nhan Vien BE', 'NV-BE', $this->child);
        $this->outside = $this->employee('Nhan Vien KTO', 'NV-KTO', $this->other);
    }

    private function employee(string $name, string $code, Department $department, array $overrides = []): Employee
    {
        $employee = Employee::create(array_merge([
            'full_name' => $name, 'code' => $code, 'company_email' => strtolower($code).'@qlns.local',
            'hire_date' => '2026-01-05', 'employment_status' => 'active', 'department_id' => $department->id,
        ], $overrides));
        EmployeeContract::create([
            'employee_id' => $employee->id, 'contract_number' => 'HD-'.$code, 'contract_type' => 'chinh_thuc',
            'start_date' => '2026-01-05', 'agreed_salary' => 15_000_000, 'insurance_salary' => 15_000_000, 'status' => 'active',
        ]);

        return $employee;
    }

    private function as(string $who): array
    {
        $credentials = [
            'hr' => ['hr@qlns.local', 'Hr@123456'],
            'manager' => ['manager@qlns.local', 'Manager@123'],
        ][$who];

        $this->tokens[$who] ??= $this->postJson('/api/v1/auth/login', ['email' => $credentials[0], 'password' => $credentials[1]])->json('access_token');

        return ['Authorization' => 'Bearer '.$this->tokens[$who]];
    }

    public function test_employee_list_and_stats_only_cover_own_department_and_children(): void
    {
        $codes = collect($this->getJson('/api/v1/employees?per_page=100', $this->as('manager'))->assertOk()->json('data'))->pluck('code');

        $this->assertEqualsCanonicalizing(['NV-TP', 'NV-KT', 'NV-BE'], $codes->all());
        $this->getJson('/api/v1/employees/stats', $this->as('manager'))->assertJsonPath('total', 3);

        // HR vẫn cả công ty.
        $hrCodes = collect($this->getJson('/api/v1/employees?per_page=100', $this->as('hr'))->json('data'))->pluck('code');
        $this->assertContains('NV-KTO', $hrCodes->all());
    }

    public function test_cannot_open_employee_of_other_department_or_its_sub_resources(): void
    {
        $id = $this->outside->id;

        $this->getJson("/api/v1/employees/{$id}", $this->as('manager'))->assertForbidden();
        $this->getJson("/api/v1/employees/{$id}/contracts", $this->as('manager'))->assertForbidden();
        $this->getJson("/api/v1/employees/{$id}/documents", $this->as('manager'))->assertForbidden();
        $this->getJson("/api/v1/employees/{$id}/transfers", $this->as('manager'))->assertForbidden();
        $this->getJson("/api/v1/attendances/history/{$id}", $this->as('manager'))->assertForbidden();
        $this->getJson("/api/v1/leave-requests/balances/{$id}", $this->as('manager'))->assertForbidden();
        $this->getJson("/api/v1/leave-balances/{$id}", $this->as('manager'))->assertForbidden();

        $this->getJson("/api/v1/employees/{$this->inChild->id}", $this->as('manager'))->assertOk();
        $this->getJson("/api/v1/employees/{$this->inChild->id}/contracts", $this->as('manager'))->assertOk();
        $this->getJson("/api/v1/employees/{$id}", $this->as('hr'))->assertOk();
    }

    public function test_manager_does_not_see_salary_of_staff_but_sees_own(): void
    {
        $this->getJson("/api/v1/employees/{$this->inOwn->id}", $this->as('manager'))->assertJsonPath('data.agreed_salary', null);
        $contract = $this->getJson("/api/v1/employees/{$this->inOwn->id}/contracts", $this->as('manager'))->json('data.0');
        $this->assertNull($contract['agreed_salary']);
        $this->assertNull($contract['insurance_salary']);

        $listed = collect($this->getJson('/api/v1/employees?per_page=100', $this->as('manager'))->json('data'))->keyBy('code');
        $this->assertNull($listed['NV-KT']['agreed_salary']);
        $this->assertEquals(15_000_000, $listed['NV-TP']['agreed_salary']); // chính mình

        $this->assertEquals(15_000_000, $this->getJson("/api/v1/employees/{$this->inOwn->id}", $this->as('hr'))->json('data.agreed_salary'));
    }

    public function test_department_tree_list_and_positions_are_scoped(): void
    {
        Position::create(['department_id' => $this->child->id, 'code' => 'CV-BE', 'name' => 'Dev BE']);
        Position::create(['department_id' => $this->other->id, 'code' => 'CV-KT', 'name' => 'Ke toan vien']);

        $tree = $this->getJson('/api/v1/departments/tree', $this->as('manager'))->assertOk()->json();
        $this->assertCount(1, $tree);
        $this->assertSame('Phong Ky thuat', $tree[0]['name']);
        $this->assertSame('To Backend', $tree[0]['children'][0]['name']);

        $names = collect($this->getJson('/api/v1/departments?per_page=100', $this->as('manager'))->json('data'))->pluck('name');
        $this->assertEqualsCanonicalizing(['Phong Ky thuat', 'To Backend'], $names->all());

        $positions = collect($this->getJson('/api/v1/positions?per_page=100', $this->as('manager'))->json('data'))->pluck('name');
        $this->assertContains('Dev BE', $positions->all());
        $this->assertNotContains('Ke toan vien', $positions->all());
    }

    public function test_quick_search_only_finds_own_department(): void
    {
        $titles = collect($this->getJson('/api/v1/search?q=Nhan Vien', $this->as('manager'))->assertOk()->json('data'))->pluck('title');

        $this->assertEqualsCanonicalizing(['Nhan Vien KT', 'Nhan Vien BE'], $titles->all());
    }

    public function test_dashboard_counts_only_own_departments(): void
    {
        $response = $this->getJson('/api/v1/dashboard', $this->as('manager'))->assertOk();

        $response->assertJsonPath('employee_stats.total', 3);
        $this->assertEqualsCanonicalizing(['Phong Ky thuat', 'To Backend'], $response->json('department_scope'));
        $this->assertEqualsCanonicalizing(
            ['Phong Ky thuat', 'To Backend'],
            collect($response->json('department_distribution'))->pluck('name')->all(),
        );
        $this->assertNull($this->getJson('/api/v1/dashboard', $this->as('hr'))->json('department_scope'));
    }

    public function test_attendance_overview_and_list_are_scoped(): void
    {
        $shift = WorkShift::create(['code' => 'CA-SC', 'name' => 'Ca hanh chinh', 'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480]);
        foreach ([$this->inOwn, $this->outside] as $employee) {
            EmployeeShiftAssignment::create([
                'employee_id' => $employee->id, 'work_shift_id' => $shift->id, 'effective_from' => '2026-01-01',
                'work_days' => [1, 2, 3, 4, 5], 'status' => 'active',
            ]);
            Attendance::create([
                'employee_id' => $employee->id, 'work_shift_id' => $shift->id,
                'attendance_date' => '2026-10-14', 'first_check_in_at' => now(),
            ]);
        }

        $rows = collect($this->getJson('/api/v1/attendances/overview?date=2026-10-14', $this->as('manager'))->assertOk()->json('data.rows'));
        $this->assertSame([$this->inOwn->id], $rows->pluck('employee.id')->unique()->values()->all());

        $listed = collect($this->getJson('/api/v1/attendances', $this->as('manager'))->assertOk()->json('data'))->pluck('employee_id');
        $this->assertSame([$this->inOwn->id], $listed->unique()->values()->all());
    }

    public function test_leave_requests_and_overview_are_scoped(): void
    {
        $type = LeaveType::create([
            'code' => 'lt-scope', 'name' => 'Phep nam', 'annual_entitlement_days' => 12,
            'is_paid' => true, 'allow_carry_forward' => false, 'is_active' => true,
        ]);
        foreach ([$this->inChild, $this->outside] as $employee) {
            LeaveRequest::create([
                'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'from_date' => '2026-10-20', 'to_date' => '2026-10-20',
                'start_session' => 'full', 'end_session' => 'full', 'total_days' => 1, 'reason' => 'x', 'status' => 'pending',
            ]);
        }

        $listed = collect($this->getJson('/api/v1/leave-requests', $this->as('manager'))->assertOk()->json('data'))->pluck('employee_id');
        $this->assertSame([$this->inChild->id], $listed->all());

        $overview = collect($this->getJson('/api/v1/leave-requests/overview', $this->as('manager'))->assertOk()->json('data'));
        $this->assertNotContains($this->outside->id, $overview->pluck('employee.id')->all());
        $this->assertContains($this->inChild->id, $overview->pluck('employee.id')->all());
    }

    public function test_manager_heading_no_department_sees_nobody(): void
    {
        $this->own->update(['manager_id' => null]);

        $this->assertSame([], $this->getJson('/api/v1/employees', $this->as('manager'))->assertOk()->json('data'));
        $this->getJson("/api/v1/employees/{$this->inOwn->id}", $this->as('manager'))->assertForbidden();
        // Hồ sơ chính mình vẫn mở được.
        $this->getJson("/api/v1/employees/{$this->head->id}", $this->as('manager'))->assertOk();
    }
}
