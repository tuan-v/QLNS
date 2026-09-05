<?php

namespace Tests\Feature\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    private function loginAs(string $email, string $password): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        return $response->json('access_token');
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
        ], $overrides));
    }

    private function contractPayload(array $overrides = []): array
    {
        return array_merge([
            'contract_number' => 'HD-'.uniqid(),
            'contract_type' => 'thu_viec',
            'start_date' => '2024-01-01',
            'agreed_salary' => 10000000,
            'insurance_salary' => 10000000,
            'contract_file' => UploadedFile::fake()->create('hop-dong.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    public function test_unauthenticated_cannot_list_contracts(): void
    {
        $employee = $this->makeEmployee();

        $response = $this->getJson('/api/v1/employees/'.$employee->id.'/contracts');

        $response->assertStatus(401);
    }

    public function test_user_without_update_permission_cannot_create_contract(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_contract_with_pdf_file(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload([
            'contract_number' => 'HD-CREATE-001',
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.contract_number', 'HD-CREATE-001');
        $this->assertDatabaseHas('employee_contracts', [
            'employee_id' => $employee->id,
            'contract_number' => 'HD-CREATE-001',
        ]);
        $contract = $employee->contracts()->first();
        Storage::disk('local')->assertExists($contract->contract_file_path);
    }

    public function test_contract_file_is_required(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $payload = $this->contractPayload();
        unset($payload['contract_file']);

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $payload, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('contract_file');
    }

    public function test_contract_number_must_be_unique(): void
    {
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->postJson('/api/v1/employees/'.$employeeA->id.'/contracts', $this->contractPayload([
            'contract_number' => 'HD-DUP-001',
        ]), [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(201);

        $response = $this->postJson('/api/v1/employees/'.$employeeB->id.'/contracts', $this->contractPayload([
            'contract_number' => 'HD-DUP-001',
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('contract_number');
    }

    public function test_can_list_contracts_for_employee(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(201);

        $response = $this->getJson('/api/v1/employees/'.$employee->id.'/contracts', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_can_download_own_contract_file(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $contractId = $created->json('data.id');

        $response = $this->get('/api/v1/employees/'.$employee->id.'/contracts/'.$contractId.'/download', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_cannot_download_contract_via_mismatched_employee(): void
    {
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employeeA->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $contractId = $created->json('data.id');

        // Hop dong thuoc employeeA, nhung URL lai ghep voi employeeB (IDOR)
        $response = $this->get('/api/v1/employees/'.$employeeB->id.'/contracts/'.$contractId.'/download', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(404);
    }
}
