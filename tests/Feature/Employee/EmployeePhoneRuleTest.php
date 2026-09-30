<?php

namespace Tests\Feature\Employee;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Số điện thoại phải đúng 10 chữ số (2026-09-30) — trước đó dưới 10 số vẫn lọt.
class EmployeePhoneRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function hr(): array
    {
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'hr@qlns.local', 'password' => 'Hr@123456'])->json('access_token');

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_phone_must_be_exactly_ten_digits_when_creating_an_employee(): void
    {
        foreach (['091234567', '09123456789', '09123abc78', '0912 34567'] as $phone) {
            $this->postJson('/api/v1/employees', ['phone' => $phone], $this->hr())
                ->assertStatus(422)
                ->assertJsonValidationErrors('phone');
        }

        $this->postJson('/api/v1/employees', ['phone' => '0912345678'], $this->hr())
            ->assertJsonMissingValidationErrors('phone');
    }
}
