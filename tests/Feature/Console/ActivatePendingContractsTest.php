<?php

namespace Tests\Feature\Console;

use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivatePendingContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function makeEmployee(): Employee
    {
        return Employee::create([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
        ]);
    }

    private function makeContract(Employee $employee, array $overrides = []): EmployeeContract
    {
        return EmployeeContract::create(array_merge([
            'employee_id' => $employee->id,
            'contract_number' => 'HD-'.uniqid(),
            'contract_type' => 'thu_viec',
            'start_date' => '2024-01-01',
            'agreed_salary' => 10_000_000,
            'status' => 'active',
        ], $overrides));
    }

    public function test_activates_pending_contracts_whose_start_date_has_arrived(): void
    {
        $employee = $this->makeEmployee();
        $pending = $this->makeContract($employee, [
            'start_date' => now()->subDay()->toDateString(),
            'status' => 'pending',
        ]);

        $this->artisan('contracts:activate-pending')->assertExitCode(0);

        $this->assertSame('active', $pending->fresh()->status);
    }

    public function test_activating_a_pending_contract_supersedes_the_old_active_contract(): void
    {
        $employee = $this->makeEmployee();
        $old = $this->makeContract($employee, ['status' => 'active']);
        $pending = $this->makeContract($employee, [
            'start_date' => now()->subDay()->toDateString(),
            'status' => 'pending',
        ]);

        $this->artisan('contracts:activate-pending');

        $this->assertSame('expired', $old->fresh()->status);
        $this->assertSame('active', $pending->fresh()->status);
    }

    public function test_does_not_touch_pending_contracts_whose_start_date_has_not_arrived(): void
    {
        $employee = $this->makeEmployee();
        $pending = $this->makeContract($employee, [
            'start_date' => now()->addMonth()->toDateString(),
            'status' => 'pending',
        ]);

        $this->artisan('contracts:activate-pending');

        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_does_not_touch_contracts_that_are_not_pending(): void
    {
        $employee = $this->makeEmployee();
        $active = $this->makeContract($employee, ['status' => 'active']);

        $this->artisan('contracts:activate-pending');

        $this->assertSame('active', $active->fresh()->status);
    }
}
