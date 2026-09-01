<?php

namespace App\Jobs;

use App\Events\SoulEvent;
use App\Models\OutboxEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Publishes one outbox row to the WebSocket layer, then marks it published.
 *
 * Marking happens only after the broadcast returns, so a Reverb outage leaves `published_at`
 * null and `soul:publish-outbox` will retry the row later. Marking first would lose the event
 * silently — which is exactly the failure the outbox pattern exists to prevent.
 */
class PublishOutboxEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $outboxEventId) {}

    public function handle(): void
    {
        $row = OutboxEvent::query()->find($this->outboxEventId);

        if (! $row || $row->published_at !== null) {
            return; // already published, or swept away — nothing to do
        }

        $payload = $row->payload_json;
        $channels = $payload['channels'] ?? [];
        unset($payload['channels']);

        if ($channels === []) {
            // Nothing to deliver to; mark it done so the sweeper stops retrying forever.
            $row->forceFill(['published_at' => now()])->save();

            return;
        }

        SoulEvent::dispatch($channels, $payload);

        $row->forceFill(['published_at' => now()])->save();
    }

    public function backoff(): array
    {
        return [5, 15, 60, 300];
    }
}
