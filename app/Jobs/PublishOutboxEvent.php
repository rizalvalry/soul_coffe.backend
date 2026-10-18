<?php

namespace App\Jobs;

use App\Events\SoulEvent;
use App\Models\OutboxEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Publishes one outbox row to the WebSocket layer, then marks it published.
 *
 * Marking happens only after the broadcast returns, so a broadcaster outage leaves `published_at`
 * null and the row is retried. Marking first would lose the event silently — which is exactly the
 * failure the outbox pattern exists to prevent.
 *
 * The row lock serializes a queued worker and the scheduled recovery command; a replay after a
 * successful send exits without broadcasting again.
 */
class PublishOutboxEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 10;

    public function __construct(public readonly int $outboxEventId) {}

    public static function enqueue(int $outboxEventId): void
    {
        $job = new self($outboxEventId);
        $connection = config('queue.default');
        $job->onConnection($connection === 'sync' ? 'database' : $connection);

        Bus::dispatch($job);
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $row = OutboxEvent::query()->whereKey($this->outboxEventId)->lockForUpdate()->first();

            if (! $row || $row->published_at !== null) {
                return;
            }

            $payload = $row->payload_json;
            $channels = $payload['channels'] ?? [];
            unset($payload['channels']);

            if ($channels !== []) {
                SoulEvent::dispatch($channels, $payload);
            }

            $row->forceFill(['published_at' => now()])->save();
        });
    }

    public function backoff(): array
    {
        return [5, 15];
    }
}
