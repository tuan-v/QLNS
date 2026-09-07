<?php

namespace Tests\Feature\Position;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionTest extends TestCase
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
        $response = $this->getJson('/api/v1/positions');

        $response->assertStatus(401);
    }

    public function test_user_without_manage_permission_cannot_create(): void
    {
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->postJson('/api/v1/positions', [
            'department_id' => $department->id,
            'name' => 'Test',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_create_position_with_auto_generated_code(): void
    {
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/positions', [
            'department_id' => $department->id,
            'name' => 'Truong phong',
            'level' => 1,
            'position_allowance' => 2000000,
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertMatchesRegularExpression('/^CV\d{3}$/', $response->json('code'));
        $this->assertDatabaseHas('positions', ['department_id' => $department->id, 'name' => 'Truong phong']);
    }

    public function test_client_supplied_code_is_ignored_on_create(): void
    {
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/positions', [
            'department_id' => $department->id,
            'name' => 'Test',
            'code' => 'HACK999',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(201);
        $this->assertNotSame('HACK999', $response->json('code'));
    }

    public function test_create_requires_department_id_and_name(): void
    {
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->postJson('/api/v1/positions', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['department_id', 'name']);
    }

    public function test_admin_can_update_position(): void
    {
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $position = Position::create(['department_id' => $department->id, 'name' => 'Ten cu', 'code' => 'CV001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/positions/'.$position->id, [
            'department_id' => $department->id,
            'name' => 'Ten moi',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200)->assertJson(['name' => 'Ten moi']);
    }

    public function test_update_ignores_client_supplied_code(): void
    {
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $position = Position::create(['department_id' => $department->id, 'name' => 'Ten', 'code' => 'CV001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->putJson('/api/v1/positions/'.$position->id, [
            'department_id' => $department->id,
            'name' => 'Ten',
            'code' => 'ZZZ',
        ], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('positions', ['id' => $position->id, 'code' => 'CV001']);
    }

    public function test_admin_can_delete_position(): void
    {
        $department = Department::create(['name' => 'Phong', 'code' => 'PB001']);
        $position = Position::create(['department_id' => $department->id, 'name' => 'Ten', 'code' => 'CV001']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->deleteJson('/api/v1/positions/'.$position->id, [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(204);
        $this->assertSoftDeleted('positions', ['id' => $position->id]);
    }

    public function test_can_filter_positions_by_department(): void
    {
        $deptA = Department::create(['name' => 'Phong A', 'code' => 'PB001']);
        $deptB = Department::create(['name' => 'Phong B', 'code' => 'PB002']);
        Position::create(['department_id' => $deptA->id, 'name' => 'Vi tri A', 'code' => 'CV001']);
        Position::create(['department_id' => $deptB->id, 'name' => 'Vi tri B', 'code' => 'CV002']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->getJson('/api/v1/positions?department_id='.$deptA->id, [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Vi tri A'));
        $this->assertFalse($names->contains('Vi tri B'));
    }
}
