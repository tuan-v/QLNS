<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed();

        Route::middleware(['auth:api', 'permission:rbac.manage'])
            ->get('/test-permission-check', fn () => response()->json(['ok' => true]));
    }

    private function loginAs(string $email, string $password): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        return $response->json('access_token');
    }

    public function test_user_with_required_permission_is_allowed(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->getJson('/test-permission-check', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
    }

    public function test_user_without_required_permission_is_forbidden(): void
    {
        $token = $this->loginAs('employee@qlns.local', 'Employee@123');

        $response = $this->getJson('/test-permission-check', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/test-permission-check');

        $response->assertStatus(401);
    }
}
