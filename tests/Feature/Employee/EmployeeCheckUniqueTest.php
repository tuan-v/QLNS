<?php

namespace Tests\Feature\Employee;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Kiểm tra trùng ngay khi nhập ở form Thêm/Sửa nhân viên (mục 55 CODE_MAP).
class EmployeeCheckUniqueTest extends TestCase
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

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'full_name' => 'Nhan vien '.uniqid(),
            'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(),
            'code' => 'NV-'.uniqid(),
        ], $overrides));
    }

    public function test_requires_authentication_and_create_or_update_permission(): void
    {
        $this->getJson('/api/v1/employees/check-unique?field=phone&value=0912345678')->assertStatus(401);

        $this->getJson(
            '/api/v1/employees/check-unique?field=phone&value=0912345678',
            $this->auth('manager@qlns.local', 'Manager@123'),
        )->assertStatus(403);
    }

    public function test_reports_taken_and_available_values_for_every_unique_field(): void
    {
        $this->makeEmployee([
            'company_email' => 'a@qlns.local', 'personal_email' => 'a@gmail.com',
            'phone' => '0912345678', 'cccd' => '012345678901', 'personal_tax_code' => '1234567890',
        ]);

        foreach (['company_email' => 'a@qlns.local', 'personal_email' => 'a@gmail.com', 'phone' => '0912345678', 'cccd' => '012345678901', 'personal_tax_code' => '1234567890'] as $field => $value) {
            $this->getJson('/api/v1/employees/check-unique?'.http_build_query(['field' => $field, 'value' => $value]), $this->hr())
                ->assertStatus(200)->assertJsonPath('available', false);
            $this->getJson('/api/v1/employees/check-unique?'.http_build_query(['field' => $field, 'value' => 'khac-'.$value]), $this->hr())
                ->assertStatus(200)->assertJsonPath('available', true);
        }
    }

    public function test_editing_ignores_the_employee_being_edited(): void
    {
        $employee = $this->makeEmployee(['phone' => '0987654321']);

        $this->getJson('/api/v1/employees/check-unique?field=phone&value=0987654321', $this->hr())
            ->assertJsonPath('available', false);
        $this->getJson('/api/v1/employees/check-unique?field=phone&value=0987654321&ignore_id='.$employee->id, $this->hr())
            ->assertJsonPath('available', true);
    }

    public function test_soft_deleted_employees_still_count_like_the_save_rule(): void
    {
        $this->makeEmployee(['cccd' => '999999999999'])->delete();

        $this->getJson('/api/v1/employees/check-unique?field=cccd&value=999999999999', $this->hr())
            ->assertJsonPath('available', false);
    }

    public function test_rejects_unknown_fields(): void
    {
        $this->getJson('/api/v1/employees/check-unique?field=full_name&value=abc', $this->hr())
            ->assertStatus(422)->assertJsonValidationErrors('field');
    }
}
