<?php

namespace Tests\Feature\Security;

use App\Mail\AccountLockedMail;
use App\Mail\PasswordResetMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// Tự khóa tài khoản tạm khi bị dò mật khẩu từ nhiều IP (LoginLockoutService, 2026-10-14).
class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    private int $ip = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Mail::fake();
        config([
            'rate_limits.enabled' => true,
            'rate_limits.account_lock_attempts' => 3,
            'rate_limits.account_lock_window_minutes' => 15,
            'rate_limits.account_lock_minutes' => 15,
        ]);
    }

    // Mỗi lần gọi 1 IP mới — giả lập botnet, vượt qua giới hạn email+IP sẵn có.
    private function loginFromNewIp(string $email, string $password)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$this->ip++])
            ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_wrong_passwords_from_many_ips_lock_the_account_and_email_the_owner(): void
    {
        $this->loginFromNewIp('hr@qlns.local', 'SaiMatKhau-1')->assertStatus(401);
        $this->loginFromNewIp('hr@qlns.local', 'SaiMatKhau-2')->assertStatus(401);
        $this->loginFromNewIp('hr@qlns.local', 'SaiMatKhau-3')->assertStatus(401); // lần thứ 3 -> khóa

        Mail::assertQueued(AccountLockedMail::class, fn ($mail) => $mail->hasTo('hr@qlns.local') && $mail->ip === '10.0.0.3');

        // Đang khóa: nhập ĐÚNG mật khẩu cũng bị từ chối (kẻ dò không biết lần nào trúng).
        $response = $this->loginFromNewIp('hr@qlns.local', 'Hr@123456');
        $response->assertStatus(429)->assertHeader('Retry-After');
        $this->assertStringContainsString('tạm khóa', $response->json('message'));

        // Tài khoản khác không bị ảnh hưởng.
        $this->loginFromNewIp('admin@qlns.local', 'Admin@123')->assertOk();
    }

    public function test_lock_expires_by_itself(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->loginFromNewIp('hr@qlns.local', 'SaiMatKhau-x');
        }
        $this->loginFromNewIp('hr@qlns.local', 'Hr@123456')->assertStatus(429);

        $this->travel(16)->minutes();

        $this->loginFromNewIp('hr@qlns.local', 'Hr@123456')->assertOk();
    }

    public function test_successful_login_resets_the_failure_count(): void
    {
        $this->loginFromNewIp('hr@qlns.local', 'SaiMatKhau-x')->assertStatus(401);
        $this->loginFromNewIp('hr@qlns.local', 'SaiMatKhau-x')->assertStatus(401);
        $this->loginFromNewIp('hr@qlns.local', 'Hr@123456')->assertOk(); // đúng -> đếm lại từ 0

        $this->loginFromNewIp('hr@qlns.local', 'SaiMatKhau-x')->assertStatus(401);
        $this->loginFromNewIp('hr@qlns.local', 'SaiMatKhau-x')->assertStatus(401);
        $this->loginFromNewIp('hr@qlns.local', 'Hr@123456')->assertOk();

        Mail::assertNotQueued(AccountLockedMail::class);
    }

    public function test_resetting_the_password_unlocks_the_account_immediately(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->loginFromNewIp('hr@qlns.local', 'SaiMatKhau-x');
        }
        $this->loginFromNewIp('hr@qlns.local', 'Hr@123456')->assertStatus(429);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'hr@qlns.local'])->assertOk();
        $resetUrl = null;
        Mail::assertSent(PasswordResetMail::class, function ($mail) use (&$resetUrl) {
            $resetUrl = $mail->resetUrl;

            return true;
        });
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $query);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $query['token'], 'password' => 'NewSecret@123', 'password_confirmation' => 'NewSecret@123',
        ])->assertOk();

        $this->loginFromNewIp('hr@qlns.local', 'NewSecret@123')->assertOk();
    }

    public function test_unknown_emails_are_locked_the_same_way_without_sending_mail(): void
    {
        // Phản hồi giống hệt email có thật -> không dò được email nào tồn tại.
        for ($i = 0; $i < 3; $i++) {
            $this->loginFromNewIp('khong-ton-tai@qlns.local', 'SaiMatKhau-x')->assertStatus(401);
        }
        $this->loginFromNewIp('khong-ton-tai@qlns.local', 'SaiMatKhau-x')->assertStatus(429);

        Mail::assertNothingQueued();
    }
}
