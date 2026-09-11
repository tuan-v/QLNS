<?php

namespace Tests\Feature\Leave;

use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Ngày 43: LeaveTypeController chưa từng có Feature Test riêng (chỉ được
// dùng gián tiếp qua LeaveRequestTest khi tạo đơn) — bổ sung cho đủ, vì đây
// vẫn là 1 phần của module Nghỉ phép (mục 19, đổ dropdown khi tạo đơn).
class LeaveTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function loginAs(string $email, string $password): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        return $response->json('access_token');
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/leave-types');

        $response->assertStatus(401);
    }

    public function test_index_returns_only_active_leave_types(): void
    {
        LeaveType::create([
            'code' => 'inactive-test',
            'name' => 'Loai phep da khoa',
            'annual_entitlement_days' => 0,
            'is_paid' => false,
            'is_active' => false,
        ]);
        $user = User::create([
            'email' => 'lt-'.uniqid().'@qlns.local', 'user_name' => 'LT User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/leave-types', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $codes = array_column($response->json(), 'code');
        // 5 loại phép seed sẵn (LeaveTypeSeeder) đều active.
        $this->assertContains('annual', $codes);
        $this->assertContains('sick', $codes);
        $this->assertNotContains('inactive-test', $codes);
    }

    public function test_index_includes_annual_entitlement_days_and_is_paid(): void
    {
        $user = User::create([
            'email' => 'lt-'.uniqid().'@qlns.local', 'user_name' => 'LT User',
            'password' => bcrypt('Secret@123'), 'status' => 'active',
        ]);
        Role::where('name', 'Employee')->first()->users()->attach($user->id);
        $token = $this->loginAs($user->email, 'Secret@123');

        $response = $this->getJson('/api/v1/leave-types', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $byCode = collect($response->json())->keyBy('code');
        // JSON không phân biệt int/float cho số nguyên tròn (12.0 -> 12 khi
        // encode) — dùng assertEquals thay vì assertSame cho đúng bản chất.
        $this->assertEquals(12, $byCode['annual']['annual_entitlement_days']);
        $this->assertTrue($byCode['annual']['is_paid']);
        $this->assertEquals(0, $byCode['sick']['annual_entitlement_days']);
    }
}
