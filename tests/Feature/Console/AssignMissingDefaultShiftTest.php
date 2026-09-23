<?php

namespace Tests\Feature\Console;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// "Bất kỳ nhân viên nào cũng tự động có ca mặc định" (2026-09-24, theo yêu
// cầu người dùng) — lưới an toàn hằng ngày, xem comment đầu
// AssignMissingDefaultShift.php.
class AssignMissingDefaultShiftTest extends TestCase
{
    use RefreshDatabase;

    private function makeEmployee(array $overrides = []): Employee
    {
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);

        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
            'department_id' => $department->id,
            'employment_status' => 'active',
        ], $overrides));
    }

    private function makeDefaultShift(): WorkShift
    {
        return WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca mac dinh',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
            'is_active' => true, 'is_default' => true,
        ]);
    }

    public function test_assigns_default_shift_to_employee_with_no_assignment(): void
    {
        $this->makeDefaultShift();
        $employee = $this->makeEmployee();

        $this->artisan('shifts:assign-missing-default')->assertExitCode(0);

        $this->assertDatabaseHas('employee_shift_assignments', [
            'employee_id' => $employee->id, 'status' => 'active',
        ]);
    }

    public function test_does_not_touch_employee_who_already_has_an_assignment(): void
    {
        $defaultShift = $this->makeDefaultShift();
        $otherShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca dem', 'start_time' => '22:00', 'end_time' => '06:00',
            'standard_work_minutes' => 480,
        ]);
        $employee = $this->makeEmployee();
        $existing = EmployeeShiftAssignment::create([
            'employee_id' => $employee->id, 'work_shift_id' => $otherShift->id,
            'effective_from' => now()->toDateString(), 'work_days' => [1, 2, 3, 4, 5, 6, 7], 'status' => 'active',
        ]);

        $this->artisan('shifts:assign-missing-default');

        $this->assertDatabaseCount('employee_shift_assignments', 1);
        $this->assertDatabaseHas('employee_shift_assignments', [
            'id' => $existing->id, 'work_shift_id' => $otherShift->id,
        ]);
        $this->assertDatabaseMissing('employee_shift_assignments', ['work_shift_id' => $defaultShift->id]);
    }

    public function test_does_not_touch_resigned_employees(): void
    {
        $this->makeDefaultShift();
        $employee = $this->makeEmployee(['employment_status' => 'resigned']);

        $this->artisan('shifts:assign-missing-default');

        $this->assertDatabaseCount('employee_shift_assignments', 0);
        $this->assertNotNull($employee->id);
    }

    public function test_probation_employee_without_assignment_gets_default_too(): void
    {
        $this->makeDefaultShift();
        $employee = $this->makeEmployee(['employment_status' => 'probation']);

        $this->artisan('shifts:assign-missing-default');

        $this->assertDatabaseHas('employee_shift_assignments', ['employee_id' => $employee->id]);
    }

    public function test_reports_when_no_default_shift_is_configured(): void
    {
        $this->makeEmployee();

        $this->artisan('shifts:assign-missing-default')->assertExitCode(0);

        $this->assertDatabaseCount('employee_shift_assignments', 0);
    }

    public function test_assigns_to_multiple_employees_in_one_run(): void
    {
        $this->makeDefaultShift();
        $first = $this->makeEmployee();
        $second = $this->makeEmployee();

        $this->artisan('shifts:assign-missing-default');

        $this->assertDatabaseHas('employee_shift_assignments', ['employee_id' => $first->id]);
        $this->assertDatabaseHas('employee_shift_assignments', ['employee_id' => $second->id]);
    }
}
