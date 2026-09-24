<?php

namespace Tests\Feature\Notification;

use App\Models\Notification;
use App\Models\User;
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
