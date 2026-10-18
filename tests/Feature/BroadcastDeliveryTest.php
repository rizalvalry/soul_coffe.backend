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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FailingBroadcaster;
use Tests\TestCase;

/** Queued delivery and recovery of the transactional outbox. */
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

    public function test_publishing_queues_then_worker_broadcasts_same_payload(): void
    {
        Queue::fake();
        Event::fake([SoulEvent::class]);

        $eventId = $this->publish();
        $row = OutboxEvent::query()->where('event_id', $eventId)->sole();
        Queue::assertPushed(PublishOutboxEvent::class, fn (PublishOutboxEvent $job): bool => $job->outboxEventId === $row->id
            && $job->connection === 'database');
        Event::assertNotDispatched(SoulEvent::class);
        $this->assertNull($row->published_at);

        (new PublishOutboxEvent($row->id))->handle();

        $expectedPayload = $row->payload_json;
        unset($expectedPayload['channels']);
        Event::assertDispatched(SoulEvent::class, function (SoulEvent $event) use ($eventId, $expectedPayload): bool {
            return $event->payload['event_id'] === $eventId
                && $event->payload['type'] === 'RefillRequestApproved'
                && $event->payload === $expectedPayload
                && $event->channels === ["user.{$this->staff->id}", 'role.BARISTA'];
        });

        $this->assertNotNull(
            OutboxEvent::query()->where('event_id', $eventId)->value('published_at'),
            'The outbox row must be stamped published once the broadcast returns.',
        );
    }

    public function test_database_worker_consumes_queued_outbox_job(): void
    {
        Event::fake([SoulEvent::class]);

        $eventId = $this->publish();
        $row = OutboxEvent::query()->where('event_id', $eventId)->sole();
        $this->assertNull($row->published_at);
        $this->assertSame(1, DB::table('jobs')->count());

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3]);

        $this->assertNotNull($row->fresh()->published_at);
        $this->assertSame(0, DB::table('jobs')->count());
        Event::assertDispatchedTimes(SoulEvent::class, 1);
    }

    /** The queued failure leaves the event available for recovery. */
    public function test_a_broadcaster_outage_keeps_the_outbox_row_for_retry(): void
    {
        Queue::fake();
        $this->useExplodingBroadcaster();

        $eventId = $this->publish();
        $row = OutboxEvent::query()->where('event_id', $eventId)->sole();

        Queue::assertPushed(PublishOutboxEvent::class);
        try {
            (new PublishOutboxEvent($row->id))->handle();
            $this->fail('The fake broadcaster should fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('pusher unreachable', $e->getMessage());
        }
        $this->assertNull($row->fresh()->published_at);
    }

    /** Recovery only retries rows older than the configured age. */
    public function test_recovery_respects_configured_age_and_skips_published_rows(): void
    {
        Queue::fake();
        Event::fake([SoulEvent::class]);
        config(['outbox.recovery_age_minutes' => 7]);

        $eventId = (string) Str::uuid();
        $row = OutboxEvent::create([
            'event_id' => $eventId,
            'name' => 'RefillRequestApproved',
            'payload_json' => [
                'event_id' => $eventId,
                'type' => 'RefillRequestApproved',
                'channels' => ['role.BARISTA'],
            ],
        ]);

        $row->forceFill(['created_at' => now()->subMinutes(6)])->save();
        Artisan::call('outbox:recover');
        Queue::assertNothingPushed();
        Event::assertNotDispatched(SoulEvent::class);

        $row->forceFill(['created_at' => now()->subMinutes(8)])->save();
        Artisan::call('outbox:recover');
        $this->assertNotNull($row->fresh()->published_at);
        Event::assertDispatchedTimes(SoulEvent::class, 1);

        Artisan::call('outbox:recover');
        Event::assertDispatchedTimes(SoulEvent::class, 1);
    }

    public function test_repeated_recovery_does_not_add_jobs_or_resend_the_event(): void
    {
        Event::fake([SoulEvent::class]);
        $eventId = $this->publish();
        $row = OutboxEvent::query()->where('event_id', $eventId)->sole();
        $row->forceFill(['created_at' => now()->subMinutes(6)])->save();

        Artisan::call('outbox:recover');
        $this->travel(61)->seconds();
        Artisan::call('outbox:recover');

        $this->assertSame(1, DB::table('jobs')->count());
        Event::assertDispatchedTimes(SoulEvent::class, 1);
    }

    public function test_failed_queue_insert_is_recovered_by_the_scheduler(): void
    {
        config(['queue.connections.database.table' => 'missing_outbox_jobs']);
        $eventId = $this->publish();
        $row = OutboxEvent::query()->where('event_id', $eventId)->sole();
        $this->assertNull($row->published_at);

        Event::fake([SoulEvent::class]);
        $row->forceFill(['created_at' => now()->subMinutes(6)])->save();
        Artisan::call('outbox:recover');

        $this->assertNotNull($row->fresh()->published_at);
        Event::assertDispatchedTimes(SoulEvent::class, 1);
    }

    public function test_failed_recovery_retries_without_piling_up_queued_jobs(): void
    {
        $eventId = $this->publish();
        $row = OutboxEvent::query()->where('event_id', $eventId)->sole();
        $row->forceFill(['created_at' => now()->subMinutes(6)])->save();
        FailingBroadcaster::activate();

        Artisan::call('outbox:recover');
        $this->travel(61)->seconds();
        Artisan::call('outbox:recover');

        $this->assertNull($row->fresh()->published_at);
        $this->assertSame(1, DB::table('jobs')->count());

        Event::fake([SoulEvent::class]);
        Artisan::call('outbox:recover');
        $this->assertNotNull($row->fresh()->published_at);
        Event::assertDispatchedTimes(SoulEvent::class, 1);
    }

    public function test_poisoned_older_rows_do_not_starve_a_newer_recoverable_row(): void
    {
        $rows = collect();
        foreach (range(1, 11) as $number) {
            $eventId = (string) Str::uuid();
            $row = OutboxEvent::create([
                'event_id' => $eventId,
                'name' => $number <= 10 ? 'Poison' : 'RefillRequestApproved',
                'payload_json' => [
                    'event_id' => $eventId,
                    'type' => $number <= 10 ? 'Poison' : 'RefillRequestApproved',
                    'channels' => ['role.BARISTA'],
                ],
            ]);
            $row->forceFill(['created_at' => now()->subMinutes(6)])->save();
            $rows->push($row);
        }
        FailingBroadcaster::activate('Poison');

        Artisan::call('outbox:recover');
        $this->assertNull($rows->last()->fresh()->published_at);

        Artisan::call('outbox:recover');
        $this->assertNotNull($rows->last()->fresh()->published_at);
        $this->assertNull($rows->first()->fresh()->published_at);
    }

    public function test_outbox_uses_configured_async_queue_connection(): void
    {
        Queue::fake();
        config(['queue.default' => 'redis']);

        $this->publish();

        Queue::assertPushed(PublishOutboxEvent::class, fn (PublishOutboxEvent $job): bool => $job->connection === 'redis');
    }

    public function test_pusher_request_timeout_is_five_seconds(): void
    {
        $this->assertSame(5, config('broadcasting.connections.pusher.options.timeout'));
        $this->assertSame(2, config('broadcasting.connections.pusher.client_options.connect_timeout'));
    }

    public function test_recovery_and_worker_schedule_have_short_overlap_expiry(): void
    {
        $recovery = collect(Schedule::events())->first(fn ($event): bool => str_contains($event->command, 'outbox:recover'));
        $worker = collect(Schedule::events())->first(fn ($event): bool => str_contains($event->command, 'queue:work'));

        $this->assertNotNull($recovery);
        $this->assertNotNull($worker);
        $this->assertSame('* * * * *', $recovery->expression);
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertSame(2, $recovery->expiresAt);
        $this->assertSame(5, $worker->expiresAt);
    }

    /** A replay after a completed publish does not send a second event. */
    public function test_replaying_the_job_after_a_successful_publish_broadcasts_nothing(): void
    {
        Queue::fake();
        Event::fake([SoulEvent::class]);

        $eventId = $this->publish();
        $outboxId = (int) OutboxEvent::query()->where('event_id', $eventId)->value('id');

        (new PublishOutboxEvent($outboxId))->handle();
        (new PublishOutboxEvent($outboxId))->handle();

        Event::assertDispatchedTimes(SoulEvent::class, 1);
    }
}
