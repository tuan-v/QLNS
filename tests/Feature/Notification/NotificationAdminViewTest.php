<?php

namespace Tests\Feature\Notification;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationAdminViewTest extends TestCase
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

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'admin-view-'.uniqid().'@qlns.local',
            'user_name' => 'Admin View User',
            'password' => bcrypt('Secret@123'),
            'status' => 'active',
        ], $overrides));
    }

    private function makeNotification(User $user, array $overrides = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'type' => 'leave.decided',
            'title' => 'Tiêu đề',
            'message' => 'Nội dung',
        ], $overrides));
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/notifications/all')->assertStatus(401);
    }

    public function test_user_without_notification_view_all_is_forbidden(): void
    {
        $token = $this->loginAs('manager@qlns.local', 'Manager@123');

        $response = $this->getJson('/api/v1/notifications/all', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_sees_notifications_of_every_user_not_just_their_own(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $this->makeNotification($userA, ['title' => 'Của A']);
        $this->makeNotification($userB, ['title' => 'Của B']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->getJson('/api/v1/notifications/all', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Của A'));
        $this->assertTrue($titles->contains('Của B'));
    }

    public function test_response_includes_recipient_info(): void
    {
        $department = Department::create(['name' => 'Phong test', 'code' => 'PB-TEST-01']);
        $user = $this->makeUser();
        $employee = Employee::create([
            'full_name' => 'Nguyen Van Test', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(), 'code' => 'NV-TEST-01', 'department_id' => $department->id, 'user_id' => $user->id,
        ]);
        $this->makeNotification($user);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->getJson('/api/v1/notifications/all', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.recipient.employee_name', $employee->full_name);
    }

    public function test_can_filter_by_type(): void
    {
        $user = $this->makeUser();
        $this->makeNotification($user, ['type' => 'leave.decided']);
        $this->makeNotification($user, ['type' => 'leave.pending_hr']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->getJson('/api/v1/notifications/all?type=leave.pending_hr', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $types = collect($response->json('data'))->pluck('type')->unique();
        $this->assertEquals(['leave.pending_hr'], $types->values()->all());
    }

    public function test_can_search_by_recipient_employee_name(): void
    {
        $department = Department::create(['name' => 'Phong test', 'code' => 'PB-TEST-02']);
        $matchUser = $this->makeUser();
        Employee::create([
            'full_name' => 'Tran Thi Khop', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(), 'code' => 'NV-TEST-02', 'department_id' => $department->id, 'user_id' => $matchUser->id,
        ]);
        $otherUser = $this->makeUser();
        Employee::create([
            'full_name' => 'Le Van Khac', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(), 'code' => 'NV-TEST-03', 'department_id' => $department->id, 'user_id' => $otherUser->id,
        ]);
        $this->makeNotification($matchUser, ['title' => 'Thông báo A']);
        $this->makeNotification($otherUser, ['title' => 'Thông báo B']);
        $token = $this->loginAs('admin@qlns.local', 'Admin@123');

        $response = $this->getJson('/api/v1/notifications/all?search=Khop', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Thông báo A'));
        $this->assertFalse($titles->contains('Thông báo B'));
    }
}
