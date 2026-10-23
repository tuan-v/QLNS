<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// Đổi mật khẩu khi đang đăng nhập (trang Cài đặt tài khoản, 2026-10-23).
class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create([
            'email' => 'doi-mk@qlns.local', 'user_name' => 'Doi mat khau',
            'password' => Hash::make('Secret@123'), 'status' => 'active',
        ]);
    }

    private function login(string $password = 'Secret@123'): array
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $password])->json();
    }

    private function change(array $tokens, array $data)
    {
        return $this->postJson('/api/v1/auth/change-password', $data, ['Authorization' => 'Bearer '.$tokens['access_token']]);
    }

    public function test_user_changes_password_and_other_sessions_are_logged_out(): void
    {
        $thisDevice = $this->login();
        $otherDevice = $this->login();

        $response = $this->change($thisDevice, [
            'current_password' => 'Secret@123', 'password' => 'MatKhauMoi@1', 'password_confirmation' => 'MatKhauMoi@1',
        ])->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);

        $this->assertTrue(Hash::check('MatKhauMoi@1', $this->user->fresh()->password));
        $this->assertDatabaseCount('password_histories', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'password_changed', 'user_id' => $this->user->id]);

        // Máy khác (và token cũ của chính máy này) không làm mới được nữa; cặp token mới thì được.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $otherDevice['refresh_token']])->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $thisDevice['refresh_token']])->assertUnauthorized();
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $response->json('refresh_token')])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'Secret@123'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'MatKhauMoi@1'])->assertOk();
    }

    public function test_wrong_current_password_is_rejected(): void
    {
        $this->change($this->login(), [
            'current_password' => 'SaiMatKhau1', 'password' => 'MatKhauMoi@1', 'password_confirmation' => 'MatKhauMoi@1',
        ])->assertStatus(422)->assertJsonPath('errors.current_password.0', 'Mật khẩu hiện tại không đúng.');

        $this->assertTrue(Hash::check('Secret@123', $this->user->fresh()->password));
    }

    public function test_new_password_must_be_valid_and_differ_from_current(): void
    {
        $tokens = $this->login();

        $this->change($tokens, ['current_password' => 'Secret@123', 'password' => 'Secret@123', 'password_confirmation' => 'Secret@123'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Mật khẩu mới phải khác mật khẩu hiện tại.');
        $this->change($tokens, ['current_password' => 'Secret@123', 'password' => 'ngan', 'password_confirmation' => 'ngan'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->change($tokens, ['current_password' => 'Secret@123', 'password' => 'MatKhauMoi@1', 'password_confirmation' => 'KhacNhau@1'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'Xác nhận mật khẩu không khớp.');
    }

    public function test_guest_cannot_change_password(): void
    {
        $this->postJson('/api/v1/auth/change-password', [])->assertUnauthorized();
    }
}
