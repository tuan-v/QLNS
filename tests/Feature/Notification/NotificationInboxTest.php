<?php

namespace Tests\Feature\Notification;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Notification;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
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
            'email' => 'inbox-'.uniqid().'@qlns.local',
            'user_name' => 'Inbox User',
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
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    public function test_user_only_sees_own_notifications(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $this->makeNotification($me, ['title' => 'Của tôi']);
        $this->makeNotification($other, ['title' => 'Của người khác']);
        $token = $this->loginAs($me->email, 'Secret@123');

        $response = $this->getJson('/api/v1/notifications', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Của tôi'));
        $this->assertFalse($titles->contains('Của người khác'));
    }

    public function test_index_includes_unread_count(): void
    {
        $me = $this->makeUser();
        $this->makeNotification($me);
        $this->makeNotification($me, ['read_at' => now()]);
        $token = $this->loginAs($me->email, 'Secret@123');

        $response = $this->getJson('/api/v1/notifications', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200)->assertJsonPath('unread_count', 1);
    }

    public function test_can_mark_own_notification_as_read(): void
    {
        $me = $this->makeUser();
        $notification = $this->makeNotification($me);
        $token = $this->loginAs($me->email, 'Secret@123');

        $response = $this->patchJson("/api/v1/notifications/{$notification->id}/read", [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('read_at'));
        $this->assertDatabaseMissing('notifications', ['id' => $notification->id, 'read_at' => null]);
    }

    public function test_cannot_mark_another_users_notification_as_read(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $notification = $this->makeNotification($other);
        $token = $this->loginAs($me->email, 'Secret@123');

        $response = $this->patchJson("/api/v1/notifications/{$notification->id}/read", [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('notifications', ['id' => $notification->id, 'read_at' => null]);
    }

    // 2026-09-29, theo yêu cầu người dùng: ô "Số dòng" trên trang Thông báo
    // (Notifications.vue) trước đây bị ẩn vì Backend cố định 20 dòng/trang —
    // giờ phải nhận đúng ?per_page= để ô đó thật sự có tác dụng.
    public function test_per_page_query_param_controls_page_size(): void
    {
        $me = $this->makeUser();
        for ($i = 0; $i < 5; $i++) {
            $this->makeNotification($me);
        }
        $token = $this->loginAs($me->email, 'Secret@123');

        $response = $this->getJson('/api/v1/notifications?per_page=2', ['Authorization' => 'Bearer '.$token]);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 5);
    }

    // Chuông hiện ảnh/tên người gây ra thông báo (2026-09-29) — suy ra từ bản
    // ghi gốc trong `data`, không lưu thêm cột; thông báo hệ thống thì null.
    public function test_index_includes_actor_derived_from_source_record(): void
    {
        $me = $this->makeUser();
        $department = Department::create(['name' => 'Phong '.uniqid(), 'code' => 'PB-'.uniqid()]);
        $submitter = Employee::create([
            'full_name' => 'Nguoi Nop Don', 'company_email' => uniqid().'@qlns.local',
            'hire_date' => now(), 'code' => 'NV-'.uniqid(), 'department_id' => $department->id,
        ]);
        $workShift = WorkShift::create([
            'code' => 'CA-'.uniqid(), 'name' => 'Ca test',
            'start_time' => '08:00', 'end_time' => '17:00', 'standard_work_minutes' => 480,
        ]);
        $attendance = Attendance::create([
            'employee_id' => $submitter->id, 'work_shift_id' => $workShift->id,
            'attendance_date' => now()->toDateString(), 'first_check_in_at' => now(),
        ]);
        $this->makeNotification($me, ['type' => 'attendance.pending_approval', 'data' => ['attendance_id' => $attendance->id]]);
        $this->makeNotification($me, ['type' => 'leave.decided', 'data' => ['status' => 'approved']]);
        $token = $this->loginAs($me->email, 'Secret@123');

        $response = $this->getJson('/api/v1/notifications', ['Authorization' => 'Bearer '.$token]);

        $byType = collect($response->json('data'))->keyBy('type');
        $this->assertSame($submitter->id, $byType['attendance.pending_approval']['actor']['employee_id']);
        $this->assertSame('Nguoi Nop Don', $byType['attendance.pending_approval']['actor']['full_name']);
        $this->assertNull($byType['leave.decided']['actor']);
    }

    public function test_mark_all_read_only_affects_own_notifications(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $mine = $this->makeNotification($me);
        $othersNotification = $this->makeNotification($other);
        $token = $this->loginAs($me->email, 'Secret@123');

        $response = $this->patchJson('/api/v1/notifications/read-all', [], [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('notifications', ['id' => $mine->id]);
        $this->assertDatabaseMissing('notifications', ['id' => $mine->id, 'read_at' => null]);
        $this->assertDatabaseHas('notifications', ['id' => $othersNotification->id, 'read_at' => null]);
    }
}
