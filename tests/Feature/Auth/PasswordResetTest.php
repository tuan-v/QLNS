<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $password = 'Secret@123'): User
    {
        return User::create([
            'email' => 'user@qlns.local',
            'user_name' => 'Nguoi dung test',
            'password' => Hash::make($password),
            'status' => 'active',
        ]);
    }

    // --- Yêu cầu đặt lại (forgot-password) ---

    public function test_forgot_password_with_existing_email_sends_mail(): void
    {
        Mail::fake();
        $this->createUser();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'user@qlns.local',
        ]);

        $response->assertOk();
        Mail::assertSent(PasswordResetMail::class);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_forgot_password_with_unknown_email_does_not_leak_existence(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'khong-ton-tai@qlns.local',
        ]);

        // Trả về CÙNG status/message như email tồn tại — không được để lộ
        // qua status khác hay message khác (User Enumeration).
        $response->assertOk();
        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_forgot_password_requires_valid_email_format(): void
    {
        $response = $this->postJson('/api/v1/auth/forgot-password', [
            'email' => 'khong-phai-email',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_requesting_new_reset_invalidates_previous_token(): void
    {
        Mail::fake();
        $this->createUser();

        $firstUrl = null;
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'user@qlns.local']);
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$firstUrl) {
            $firstUrl = $mail->resetUrl;

            return true;
        });

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'user@qlns.local']);

        $firstToken = $this->extractToken($firstUrl);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $firstToken,
            'password' => 'NewSecret@123',
            'password_confirmation' => 'NewSecret@123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('token');
    }

    // --- Đặt lại mật khẩu (reset-password) ---

    public function test_can_reset_password_with_valid_token(): void
    {
        Mail::fake();
        $user = $this->createUser();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'user@qlns.local']);

        $resetUrl = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$resetUrl) {
            $resetUrl = $mail->resetUrl;

            return true;
        });

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $this->extractToken($resetUrl),
            'password' => 'NewSecret@123',
            'password_confirmation' => 'NewSecret@123',
        ]);

        $response->assertOk();

        // Mật khẩu cũ không còn dùng được, mật khẩu mới đăng nhập được.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@qlns.local',
            'password' => 'Secret@123',
        ])->assertStatus(401);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@qlns.local',
            'password' => 'NewSecret@123',
        ])->assertOk();

        $this->assertDatabaseCount('password_histories', 1);
        $this->assertDatabaseHas('password_histories', ['user_id' => $user->id]);
    }

    public function test_reset_password_with_invalid_token_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'token-khong-ton-tai',
            'password' => 'NewSecret@123',
            'password_confirmation' => 'NewSecret@123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_reset_password_token_cannot_be_reused(): void
    {
        Mail::fake();
        $this->createUser();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'user@qlns.local']);

        $resetUrl = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$resetUrl) {
            $resetUrl = $mail->resetUrl;

            return true;
        });
        $token = $this->extractToken($resetUrl);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => 'NewSecret@123',
            'password_confirmation' => 'NewSecret@123',
        ])->assertOk();

        $second = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => 'AnotherSecret@123',
            'password_confirmation' => 'AnotherSecret@123',
        ]);

        $second->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_reset_password_requires_confirmation_to_match(): void
    {
        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'bat-ky-token-nao',
            'password' => 'NewSecret@123',
            'password_confirmation' => 'KhacNhau@123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_reset_password_revokes_existing_refresh_tokens(): void
    {
        Mail::fake();
        $this->createUser();

        // Đăng nhập trước để có 1 refresh token đang hoạt động — mô phỏng
        // đúng tình huống thật: máy A đang đăng nhập, người dùng quên mật
        // khẩu và đặt lại từ máy B, máy A phải bị đăng xuất ngầm.
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@qlns.local',
            'password' => 'Secret@123',
        ])->json();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'user@qlns.local']);
        $resetUrl = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$resetUrl) {
            $resetUrl = $mail->resetUrl;

            return true;
        });

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $this->extractToken($resetUrl),
            'password' => 'NewSecret@123',
            'password_confirmation' => 'NewSecret@123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ])->assertStatus(401);
    }

    private function extractToken(string $resetUrl): string
    {
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);

        return $query['token'];
    }
}
