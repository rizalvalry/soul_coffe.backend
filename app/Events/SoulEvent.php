<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The single broadcast event for the whole application (docs/04 §Realtime).
 *
 * One event type with a uniform payload means the mobile client needs exactly one handler for
 * every notification, and `event_id` gives it one dedupe key across both the WebSocket and the
 * push transport (E15).
 *
 * ShouldBroadcastNow, not ShouldBroadcast: this is dispatched from inside the queued
 * PublishOutboxEvent job, so queueing it again would add a second hop and a second failure mode
 * for no benefit.
 */
class SoulEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int,string>  $channels  channel names without the `private-` prefix
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly array $channels,
        public readonly array $payload,
    ) {}

    /**
     * @return array<int,Channel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (string $name): Channel => new PrivateChannel($name),
            $this->channels,
        );
    }

    public function broadcastAs(): string
    {
        return 'soul.event';
    }

    /**
     * @return array<string,mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
