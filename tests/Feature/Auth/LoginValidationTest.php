<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_rejects_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_rejects_invalid_email_format(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'not-an-email',
            'password' => 'Secret@123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_rejects_password_shorter_than_minimum(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@qlns.local',
            'password' => '1234567',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_login_rejects_email_submitted_as_array(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => ['user@qlns.local'],
            'password' => 'Secret@123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_rejects_password_submitted_as_array(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@qlns.local',
            'password' => ['Secret@123'],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_refresh_rejects_missing_token(): void
    {
        $response = $this->postJson('/api/v1/auth/refresh', []);

        $response->assertStatus(422)->assertJsonValidationErrors(['refresh_token']);
    }
}
