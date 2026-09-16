<?php

namespace Tests\Feature\Console;

use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpireEmployeeContractsTest extends TestCase
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

    public function test_expires_active_contracts_past_their_end_date(): void
    {
        $employee = $this->makeEmployee();
        $contract = $this->makeContract($employee, ['end_date' => now()->subDay()->toDateString()]);

        $this->artisan('contracts:expire')->assertExitCode(0);

        $this->assertSame('expired', $contract->fresh()->status);
    }

    public function test_does_not_touch_contracts_still_within_end_date(): void
    {
        $employee = $this->makeEmployee();
        $contract = $this->makeContract($employee, ['end_date' => now()->addDay()->toDateString()]);

        $this->artisan('contracts:expire');

        $this->assertSame('active', $contract->fresh()->status);
    }

    public function test_does_not_touch_contracts_without_end_date(): void
    {
        $employee = $this->makeEmployee();
        $contract = $this->makeContract($employee, ['end_date' => null]);

        $this->artisan('contracts:expire');

        $this->assertSame('active', $contract->fresh()->status);
    }

    public function test_does_not_touch_already_terminated_contracts(): void
    {
        $employee = $this->makeEmployee();
        $contract = $this->makeContract($employee, [
            'end_date' => now()->subDay()->toDateString(),
            'status' => 'terminated',
            'terminated_at' => now()->subWeek()->toDateString(),
        ]);

        $this->artisan('contracts:expire');

        $this->assertSame('terminated', $contract->fresh()->status);
    }
}
