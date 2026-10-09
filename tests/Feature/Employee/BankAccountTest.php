<?php

namespace Tests\Feature\Employee;

use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeChecklist;
use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Tài khoản ngân hàng nhận lương: nhân viên tự nhập -> chờ HR xác nhận; chỉ tài khoản
// đã xác nhận mới làm tài khoản nhận lương; chống trùng; chuẩn hóa tên chủ tài khoản.
class BankAccountTest extends TestCase
{
    use RefreshDatabase;

    private array $tokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function headersFor(string $email, string $password = 'Secret@123'): array
    {
        $this->tokens[$email] ??= $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->json('access_token');

        return ['Authorization' => 'Bearer ' . $this->tokens[$email]];
    }

    private function hr(): array
    {
        return $this->headersFor('hr@qlns.local', 'Hr@123456');
    }

    /** @return array{0: Employee, 1: array} */
    private function employeeWithLogin(): array
    {
        $user = User::create([
            'email' => 'bank-' . uniqid() . '@qlns.local',
            'user_name' => 'Bank user',
            'password' => bcrypt('Secret@123'),
            'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $employee = Employee::create([
            'full_name' => 'Nguyen Van Bank',
            'company_email' => uniqid() . '@qlns.local',
            'hire_date' => now()->toDateString(),
            'code' => 'NV-' . uniqid(),
            'employment_status' => 'probation',
            'user_id' => $user->id,
        ]);

        return [$employee, $this->headersFor($user->email)];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'bank_code' => 'VCB',
            'bank_branch' => 'Ha Noi',
            'account_number' => '0123 4567 89',
            'account_holder' => 'nguyễn văn bank',
        ], $overrides);
    }

    public function test_self_added_account_waits_for_hr_and_is_normalised(): void
    {
        [$employee, $headers] = $this->employeeWithLogin();

        $account = $this->postJson('/api/v1/employees/me/bank-accounts', $this->payload(), $headers)
            ->assertCreated()->json('data');

        $this->assertSame('pending', $account['status']);
        $this->assertFalse($account['is_primary']);
        $this->assertSame('0123456789', $account['account_number']);
        $this->assertSame('NGUYEN VAN BANK', $account['account_holder']);
        $this->assertStringStartsWith('Vietcombank', $account['bank_name']);
        $this->assertTrue(Notification::where('type', 'bank_account.pending')->exists());

        // Chưa xác nhận -> không đặt làm tài khoản nhận lương được.
        $this->postJson("/api/v1/employees/me/bank-accounts/{$account['id']}/primary", [], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('is_primary');
    }

    public function test_hr_verification_makes_primary_notifies_and_ticks_onboarding(): void
    {
        [$employee, $headers] = $this->employeeWithLogin();
        $this->postJson('/api/v1/onboarding/checklists', ['employee_id' => $employee->id, 'type' => 'onboarding'], $this->hr())->assertCreated();
        $id = $this->postJson('/api/v1/employees/me/bank-accounts', $this->payload(), $headers)->json('data.id');

        $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts/{$id}/review", ['status' => 'verified'], $this->hr())
            ->assertOk()->assertJsonPath('data.status', 'verified')->assertJsonPath('data.is_primary', true);

        $this->assertTrue(Notification::where('user_id', $employee->user_id)->where('type', 'bank_account.reviewed')->exists());

        $checklist = $this->getJson('/api/v1/onboarding/me', $headers)->assertOk()->json('data.0');
        $item = collect($checklist['items'])->firstWhere('auto_key', 'bank_account_verified');
        $this->assertNotNull($item['completed_at']);
    }

    public function test_employee_cannot_edit_verified_account_but_can_fix_rejected_one(): void
    {
        [$employee, $headers] = $this->employeeWithLogin();
        $first = $this->postJson('/api/v1/employees/me/bank-accounts', $this->payload(), $headers)->json('data.id');
        $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts/{$first}/review", ['status' => 'verified'], $this->hr())->assertOk();

        $this->putJson("/api/v1/employees/me/bank-accounts/{$first}", $this->payload(['account_number' => '999999999']), $headers)
            ->assertStatus(422)->assertJsonValidationErrors('account_number');

        $second = $this->postJson('/api/v1/employees/me/bank-accounts', $this->payload(['bank_code' => 'TCB', 'account_number' => '1111111111']), $headers)->json('data.id');
        $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts/{$second}/review", ['status' => 'rejected'], $this->hr())
            ->assertStatus(422)->assertJsonValidationErrors('review_note');
        $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts/{$second}/review", ['status' => 'rejected', 'review_note' => 'Sai ten'], $this->hr())
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->putJson("/api/v1/employees/me/bank-accounts/{$second}", $this->payload(['bank_code' => 'TCB', 'account_number' => '2222222222']), $headers)
            ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.review_note', null);
    }

    public function test_duplicates_are_rejected_within_and_across_employees(): void
    {
        [, $headers] = $this->employeeWithLogin();
        [, $otherHeaders] = $this->employeeWithLogin();

        $this->postJson('/api/v1/employees/me/bank-accounts', $this->payload(), $headers)->assertCreated();
        $this->postJson('/api/v1/employees/me/bank-accounts', $this->payload(), $headers)
            ->assertStatus(422)->assertJsonPath('errors.account_number.0', 'Tài khoản này đã có trong hồ sơ.');
        $this->postJson('/api/v1/employees/me/bank-accounts', $this->payload(), $otherHeaders)
            ->assertStatus(422)->assertJsonPath('errors.account_number.0', 'Số tài khoản này đang thuộc hồ sơ của nhân viên khác.');
    }

    public function test_access_is_limited_to_owner_and_hr(): void
    {
        [$employee, $headers] = $this->employeeWithLogin();
        [, $otherHeaders] = $this->employeeWithLogin();
        $id = $this->postJson('/api/v1/employees/me/bank-accounts', $this->payload(), $headers)->json('data.id');

        $this->getJson("/api/v1/employees/{$employee->id}/bank-accounts", $otherHeaders)->assertForbidden();
        $this->deleteJson("/api/v1/employees/me/bank-accounts/{$id}", [], $otherHeaders)->assertNotFound();
        $this->getJson("/api/v1/employees/{$employee->id}/bank-accounts", $this->hr())->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_primary_cannot_be_deleted_by_employee_and_hr_deletion_promotes_another(): void
    {
        [$employee, $headers] = $this->employeeWithLogin();
        $first = $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts", $this->payload(), $this->hr())
            ->assertCreated()->assertJsonPath('data.is_primary', true)->json('data.id');
        $second = $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts", $this->payload(['bank_code' => 'MB', 'account_number' => '3333333333']), $this->hr())
            ->assertCreated()->assertJsonPath('data.is_primary', false)->json('data.id');

        $this->deleteJson("/api/v1/employees/me/bank-accounts/{$first}", [], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('is_primary');

        $this->deleteJson("/api/v1/employees/{$employee->id}/bank-accounts/{$first}", [], $this->hr())->assertOk();
        $this->assertTrue(EmployeeBankAccount::findOrFail($second)->is_primary);

        // Thêm lại đúng số đã xóa vẫn được (dòng xóa mềm không tính trùng).
        $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts", $this->payload(), $this->hr())->assertCreated();
    }

    public function test_setting_primary_keeps_a_single_primary_account(): void
    {
        [$employee, $headers] = $this->employeeWithLogin();
        $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts", $this->payload(), $this->hr())->assertCreated();
        $second = $this->postJson("/api/v1/employees/{$employee->id}/bank-accounts", $this->payload(['bank_code' => 'MB', 'account_number' => '3333333333']), $this->hr())->json('data.id');

        $this->postJson("/api/v1/employees/me/bank-accounts/{$second}/primary", [], $headers)->assertOk();

        $this->assertSame([$second], EmployeeBankAccount::where('employee_id', $employee->id)->where('is_primary', true)->pluck('id')->all());
        $this->assertSame(0, EmployeeChecklist::count());
    }
}
