<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
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

    public function test_login_with_valid_credentials_returns_tokens(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@qlns.local',
            'password' => 'Secret@123',
        ]);

        $response->assertOk()->assertJsonStructure([
            'access_token', 'refresh_token', 'token_type', 'expires_in', 'user' => ['id', 'email', 'user_name'],
        ]);
    }

    public function test_login_with_invalid_password_is_rejected(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@qlns.local',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
    }

    public function test_me_requires_valid_access_token(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_me_returns_authenticated_user_with_valid_token(): void
    {
        $this->createUser();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@qlns.local',
            'password' => 'Secret@123',
        ])->json();

        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer '.$login['access_token'],
        ]);

        $response->assertOk()->assertJson([
            'email' => 'user@qlns.local',
        ]);
    }

    public function test_refresh_token_rotates_and_old_token_becomes_invalid(): void
    {
        $this->createUser();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@qlns.local',
            'password' => 'Secret@123',
        ])->json();

        $refreshed = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ]);

        $refreshed->assertOk()->assertJsonStructure(['access_token', 'refresh_token']);
        $this->assertNotSame($login['refresh_token'], $refreshed->json('refresh_token'));

        $reused = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ]);

        $reused->assertStatus(401);
    }

    public function test_logout_revokes_refresh_token(): void
    {
        $this->createUser();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@qlns.local',
            'password' => 'Secret@123',
        ])->json();

        $logout = $this->postJson('/api/v1/auth/logout', [
            'refresh_token' => $login['refresh_token'],
        ], [
            'Authorization' => 'Bearer '.$login['access_token'],
        ]);

        $logout->assertOk();

        $reused = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => $login['refresh_token'],
        ]);

        $reused->assertStatus(401);
    }

    public function test_expired_access_token_is_rejected(): void
    {
        $this->createUser();
        config(['config_jwt.access_ttl_minutes' => -1]);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@qlns.local',
            'password' => 'Secret@123',
        ])->json();

        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer '.$login['access_token'],
        ]);

        $response->assertStatus(401);
    }

    public function test_malformed_access_token_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer this-is-not-a-valid-jwt',
        ]);

        $response->assertStatus(401);
    }

    // Ngày 44 — bug thật đã vấp: token trên KHÔNG có đủ 3 phần cách nhau bởi
    // dấu chấm nên vỡ ngay ở bước đếm segment (UnexpectedValueException, đã
    // được bắt từ trước). Token dưới đây CÓ đủ 3 phần đúng hình dạng JWT
    // nhưng phần payload giải mã base64 ra không phải JSON hợp lệ —
    // firebase/php-jwt ném DomainException ở bước này, một loại lỗi KHÁC
    // chưa từng được JwtService::decodeAccessToken() bắt tới trước khi sửa,
    // khiến toàn bộ request vỡ thành 500 thay vì 401 sạch để Frontend tự làm
    // mới token. Phát hiện qua kiểm thử thật (Playwright) với 1 access_token
    // bị hỏng trong localStorage, không phải suy đoán.
    public function test_access_token_with_invalid_payload_encoding_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer expired.invalid.token',
        ]);

        $response->assertStatus(401);
    }
}
