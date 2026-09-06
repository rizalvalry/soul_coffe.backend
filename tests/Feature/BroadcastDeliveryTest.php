<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\SoulEvent;
use App\Jobs\PublishOutboxEvent;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\EventPublisher;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * How a published event actually reaches the socket.
 *
 * These tests exist because of a production failure that no existing test could have caught: the
 * broadcast was dispatched to the `database` queue, the host had no worker and no cron to start
 * one, and so every realtime notification sat in `jobs` forever while the app quietly fell back to
 * a 10-second refetch. The suite passed throughout — nothing asserted that the event actually left
 * the process.
 *
 * So the first test below asserts the thing that was missing: publishing broadcasts NOW, with an
 * empty queue afterwards. If someone reintroduces `PublishOutboxEvent::dispatch()` as the primary
 * path, that test fails.
 */
class BroadcastDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->role(Role::STAFF)->create();
    }

    private function publish(): string
    {
        return app(EventPublisher::class)->publish(
            'RefillRequestApproved',
            'Request disetujui',
            'REF-001 · 20 cups',
            ["user.{$this->staff->id}", 'role.BARISTA'],
            [$this->staff->id],
        );
    }

    /**
     * Points the default broadcaster at one that always throws.
     *
     * A driver rather than a mock of the manager: SoulEvent is ShouldBroadcastNow, so the failure
     * has to surface through the same synchronous path Laravel uses in production for the test to
     * mean anything.
     */
    private function useExplodingBroadcaster(): void
    {
        Broadcast::extend('exploding', fn (): Broadcaster => new class implements Broadcaster
        {
            public function auth($request)
            {
                return true;
            }

            public function validAuthenticationResponse($request, $result)
            {
                return $result;
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new RuntimeException('pusher unreachable');
            }
        });

        config([
            'broadcasting.connections.exploding' => ['driver' => 'exploding'],
            'broadcasting.default' => 'exploding',
        ]);
    }

    /** The regression this whole file is for: no worker, no cron, and the event still goes out. */
    public function test_publishing_broadcasts_immediately_without_touching_the_queue(): void
    {
        Queue::fake();
        Event::fake([SoulEvent::class]);

        $eventId = $this->publish();

        Event::assertDispatched(SoulEvent::class, function (SoulEvent $event) use ($eventId): bool {
            return $event->payload['event_id'] === $eventId
                && $event->payload['type'] === 'RefillRequestApproved'
                && $event->channels === ["user.{$this->staff->id}", 'role.BARISTA'];
        });

        Queue::assertNothingPushed();

        $this->assertNotNull(
            OutboxEvent::query()->where('event_id', $eventId)->value('published_at'),
            'The outbox row must be stamped published once the broadcast returns.',
        );
    }

    /**
     * A broadcaster outage must not fail the business action that caused the event — the approval
     * is already committed, and the user still sees the row in their in-app notification list.
     */
    public function test_a_broadcaster_outage_does_not_fail_the_caller(): void
    {
        Queue::fake();
        $this->useExplodingBroadcaster();

        $eventId = $this->publish();

        $this->assertSame(1, OutboxEvent::query()->where('event_id', $eventId)->count());
    }

    /**
     * ...and the event is not lost: the row stays unpublished and the job goes to the queue, so
     * the next `schedule:run` retries it. This is the only case the worker is needed for.
     */
    public function test_a_broadcaster_outage_falls_back_to_the_queue(): void
    {
        Queue::fake();
        $this->useExplodingBroadcaster();

        $eventId = $this->publish();
        $outboxId = (int) OutboxEvent::query()->where('event_id', $eventId)->value('id');

        $this->assertNull(
            OutboxEvent::query()->where('event_id', $eventId)->value('published_at'),
            'An event that never reached the broadcaster must not be marked published.',
        );

        Queue::assertPushed(
            PublishOutboxEvent::class,
            fn (PublishOutboxEvent $job): bool => $job->outboxEventId === $outboxId,
        );
    }

    /**
     * The two paths can overlap — an inline attempt that succeeded slowly, and a queued retry that
     * was already scheduled — so running the job twice must broadcast once.
     */
    public function test_replaying_the_job_after_a_successful_publish_broadcasts_nothing(): void
    {
        Event::fake([SoulEvent::class]);

        $eventId = $this->publish();
        $outboxId = (int) OutboxEvent::query()->where('event_id', $eventId)->value('id');

        (new PublishOutboxEvent($outboxId))->handle();

        Event::assertDispatchedTimes(SoulEvent::class, 1);
    }
}
