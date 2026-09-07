<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `POST /notifications/read-all` — clears the whole bell badge in one write.
 *
 * Exists because tapping every row individually to make an accumulated badge go away (a busy
 * shift, a phone left signed in for a day) is exactly the kind of friction that teaches people to
 * ignore a notification bell. See src/components/ui/NotificationBell.tsx on the mobile side for
 * the "instant, not eventually consistent" requirement this satisfies.
 */
class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private function notification(User $user, ?string $readAt = null): AppNotification
    {
        return AppNotification::query()->create([
            'user_id' => $user->id,
            'event_id' => (string) Str::uuid(),
            'type' => 'Test',
            'payload_json' => ['title' => 'x', 'body' => 'y'],
            'read_at' => $readAt,
        ]);
    }

    public function test_mark_all_read_clears_every_unread_notification_for_the_caller(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();

        $a = $this->notification($staff);
        $b = $this->notification($staff);
        $alreadyRead = $this->notification($staff, now()->subDay()->toIso8601String());

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/notifications/read-all')
            ->assertNoContent();

        $this->assertNotNull($a->fresh()->read_at);
        $this->assertNotNull($b->fresh()->read_at);
        // Untouched, not re-stamped — a notification read yesterday should not suddenly read
        // "just now" because of an unrelated bulk action today.
        $this->assertEquals($alreadyRead->read_at, $alreadyRead->fresh()->read_at);
    }

    public function test_mark_all_read_never_touches_another_users_notifications(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();
        $other = User::factory()->role(Role::BARISTA)->create();

        $mine = $this->notification($staff);
        $theirs = $this->notification($other);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/notifications/read-all')
            ->assertNoContent();

        $this->assertNotNull($mine->fresh()->read_at);
        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_mark_all_read_with_nothing_unread_is_still_a_success(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/v1/notifications/read-all')
            ->assertNoContent();
    }

    public function test_mark_all_read_requires_authentication(): void
    {
        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/notifications/read-all')
            ->assertStatus(401);
    }
}
