<?php

namespace Tests\Feature\AttendanceLocation;

use App\Models\AttendanceLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceLocationTest extends TestCase
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

    public function test_unauthenticated_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/attendance-locations');

        $response->assertStatus(401);
    }

    public function test_user_without_manage_permission_cannot_create(): void
    {
        // Manager khong co location.view/location.manage (chi HR moi co).
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->postJson('/api/v1/attendance-locations', [
            'name' => 'Van phong chinh',
            'method' => 'wifi',
            'wifi_ssid' => 'QLNS-Office',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_wifi_location_with_auto_generated_code(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/attendance-locations', [
            'name' => 'Van phong chinh',
            'method' => 'wifi',
            'wifi_ssid' => 'QLNS-Office',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^DD\d{3}$/', $response->json('code'));
        $this->assertDatabaseHas('attendance_locations', ['name' => 'Van phong chinh', 'wifi_ssid' => 'QLNS-Office']);
    }

    public function test_wifi_method_requires_wifi_ssid(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/attendance-locations', [
            'name' => 'Van phong chinh',
            'method' => 'wifi',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('wifi_ssid');
    }

    public function test_gps_method_requires_coordinates_and_radius(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/attendance-locations', [
            'name' => 'Van phong chi nhanh',
            'method' => 'gps',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['latitude', 'longitude', 'radius_meters']);
    }

    public function test_qr_method_auto_generates_secret_not_from_client(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/attendance-locations', [
            'name' => 'Quay le tan',
            'method' => 'qr',
            'qr_secret' => 'HACKED_SECRET',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $location = AttendanceLocation::find($response->json('id'));
        $this->assertNotNull($location->qr_secret);
        $this->assertNotSame('HACKED_SECRET', $location->qr_secret);
    }

    public function test_client_supplied_code_is_ignored_on_create(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/attendance-locations', [
            'name' => 'Van phong chinh',
            'method' => 'wifi',
            'wifi_ssid' => 'QLNS-Office',
            'code' => 'HACK999',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertNotSame('HACK999', $response->json('code'));
    }

    public function test_admin_can_update_attendance_location(): void
    {
        $location = AttendanceLocation::create([
            'code' => 'DD001',
            'name' => 'Ten cu',
            'method' => 'wifi',
            'wifi_ssid' => 'QLNS-Office',
        ]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/attendance-locations/'.$location->id, [
            'name' => 'Ten moi',
            'method' => 'wifi',
            'wifi_ssid' => 'QLNS-Office-2',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200)->assertJson(['name' => 'Ten moi']);
    }

    public function test_admin_can_delete_attendance_location(): void
    {
        $location = AttendanceLocation::create([
            'code' => 'DD001',
            'name' => 'Ten',
            'method' => 'wifi',
            'wifi_ssid' => 'QLNS-Office',
        ]);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->deleteJson('/api/v1/attendance-locations/'.$location->id, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(204);
        $this->assertSoftDeleted('attendance_locations', ['id' => $location->id]);
    }
}
