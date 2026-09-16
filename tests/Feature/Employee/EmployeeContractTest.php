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

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);

        // Số hợp đồng do hệ thống tự sinh theo contract_type ("thu_viec" mặc
        // định của contractPayload() -> tiền tố "HDTV-"), không nhận từ client.
        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^HDTV-\d{3}$/', $response->json('data.contract_number'));
        $this->assertDatabaseHas('employee_contracts', [
            'employee_id' => $employee->id,
            'contract_number' => $response->json('data.contract_number'),
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

    public function test_client_supplied_contract_number_is_ignored_on_create(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload([
            'contract_number' => 'HACK-999',
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertNotSame('HACK-999', $response->json('data.contract_number'));
    }

    public function test_contract_numbers_auto_increment_per_prefix(): void
    {
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        // contractPayload() mac dinh contract_type = "thu_viec" -> tien to "HDTV-".
        $first = $this->postJson('/api/v1/employees/'.$employeeA->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $second = $this->postJson('/api/v1/employees/'.$employeeB->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertNotSame($first->json('data.contract_number'), $second->json('data.contract_number'));
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

    public function test_employee_can_download_own_contract_even_without_employee_view(): void
    {
        $selfUser = \App\Models\User::create([
            'email' => 'self-contract@qlns.local', 'user_name' => 'Self',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        \App\Models\Role::where('name', 'Employee')->first()->users()->attach($selfUser->id);
        $employee = $this->makeEmployee(['user_id' => $selfUser->id]);

        $adminToken = $this->loginAs('admin@qlns.local', 'Admin@123');
        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$adminToken,
        ]);
        $contractId = $created->json('data.id');

        $selfToken = $this->loginAs('self-contract@qlns.local', 'Secret@123');
        $response = $this->get('/api/v1/employees/'.$employee->id.'/contracts/'.$contractId.'/download', [
            'Authorization' => 'Bearer '.$selfToken,
        ]);

        $response->assertStatus(200);
    }

    public function test_user_without_permission_or_ownership_cannot_download_contract(): void
    {
        $otherUser = \App\Models\User::create([
            'email' => 'other-contract@qlns.local', 'user_name' => 'Other',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        \App\Models\Role::where('name', 'Employee')->first()->users()->attach($otherUser->id);
        $this->makeEmployee(['user_id' => $otherUser->id]);
        $employee = $this->makeEmployee();

        $adminToken = $this->loginAs('admin@qlns.local', 'Admin@123');
        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$adminToken,
        ]);
        $contractId = $created->json('data.id');

        $otherToken = $this->loginAs('other-contract@qlns.local', 'Secret@123');
        $response = $this->get('/api/v1/employees/'.$employee->id.'/contracts/'.$contractId.'/download', [
            'Authorization' => 'Bearer '.$otherToken,
        ]);

        $response->assertStatus(403);
    }

    public function test_me_contracts_returns_own_contracts_without_employee_view(): void
    {
        $selfUser = \App\Models\User::create([
            'email' => 'self-me-contract@qlns.local', 'user_name' => 'Self',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        \App\Models\Role::where('name', 'Employee')->first()->users()->attach($selfUser->id);
        $employee = $this->makeEmployee(['user_id' => $selfUser->id]);

        $adminToken = $this->loginAs('admin@qlns.local', 'Admin@123');
        $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$adminToken,
        ])->assertStatus(201);

        $selfToken = $this->loginAs('self-me-contract@qlns.local', 'Secret@123');
        $response = $this->getJson('/api/v1/employees/me/contracts', [
            'Authorization' => 'Bearer '.$selfToken,
        ]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_creating_new_contract_auto_expires_previous_active_contract(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $first = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $firstId = $first->json('data.id');
        $this->assertSame('active', $first->json('data.status'));

        // Ky hop dong THU HAI cho CUNG nhan vien nay -> hop dong dau tien
        // phai tu dong chuyen sang "expired", khong can HR vao tay tung cai.
        $second = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload([
            'contract_type' => 'chinh_thuc',
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $second->assertStatus(201);
        $this->assertSame('active', $second->json('data.status'));

        $this->assertDatabaseHas('employee_contracts', [
            'id' => $firstId,
            'status' => 'expired',
        ]);
    }

    // Regression: ký hợp đồng TRƯỚC ngày bắt đầu (renew sớm) không được phép
    // đụng tới hợp đồng đang thật sự áp dụng ngay lúc tạo — nếu không, hợp
    // đồng cũ (còn hiệu lực cả tháng nữa) bị hiển thị sai thành "hết hạn" và
    // HR không chấm dứt được nó (terminate() chỉ cho từ active).
    public function test_creating_contract_with_future_start_date_does_not_touch_current_active_contract(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $current = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload([
            'start_date' => '2024-01-01',
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $currentId = $current->json('data.id');
        $this->assertSame('active', $current->json('data.status'));

        $future = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload([
            'contract_type' => 'chinh_thuc',
            'start_date' => now()->addMonths(2)->toDateString(),
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $future->assertStatus(201);
        $this->assertSame('pending', $future->json('data.status'));

        $this->assertDatabaseHas('employee_contracts', [
            'id' => $currentId,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_terminate_active_contract(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $contractId = $created->json('data.id');

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts/'.$contractId.'/terminate', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertSame('terminated', $response->json('data.status'));
        $this->assertNotNull($response->json('data.terminated_at'));
        $this->assertDatabaseHas('employee_contracts', [
            'id' => $contractId,
            'status' => 'terminated',
        ]);
    }

    public function test_cannot_terminate_an_already_terminated_contract(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $contractId = $created->json('data.id');

        $this->postJson('/api/v1/employees/'.$employee->id.'/contracts/'.$contractId.'/terminate', [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(200);

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts/'.$contractId.'/terminate', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_cannot_terminate_contract_via_mismatched_employee(): void
    {
        $employeeA = $this->makeEmployee();
        $employeeB = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $created = $this->postJson('/api/v1/employees/'.$employeeA->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $contractId = $created->json('data.id');

        $response = $this->postJson('/api/v1/employees/'.$employeeB->id.'/contracts/'.$contractId.'/terminate', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(404);
    }

    public function test_pending_contract_cannot_be_terminated(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $future = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload([
            'start_date' => now()->addMonths(2)->toDateString(),
        ]), [
            'Authorization' => 'Bearer '.$token,
        ]);
        $futureId = $future->json('data.id');
        $this->assertSame('pending', $future->json('data.status'));

        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts/'.$futureId.'/terminate', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_user_without_update_permission_cannot_terminate_contract(): void
    {
        $employee = $this->makeEmployee();
        $adminToken = $this->loginAs('admin@qlns.local', 'Admin@123');
        $created = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts', $this->contractPayload(), [
            'Authorization' => 'Bearer '.$adminToken,
        ]);
        $contractId = $created->json('data.id');

        $managerToken = $this->loginAs('manager@qlns.local', 'Manager@123');
        $response = $this->postJson('/api/v1/employees/'.$employee->id.'/contracts/'.$contractId.'/terminate', [], [
            'Authorization' => 'Bearer '.$managerToken,
        ]);

        $response->assertStatus(403);
    }
}
