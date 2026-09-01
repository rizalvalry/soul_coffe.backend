<?php

namespace App\Services;

use App\Jobs\PublishOutboxEvent;
use App\Models\AppNotification;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only publisher of realtime events (docs/04 §Realtime, requirement 3).
 *
 * The outbox pattern is the whole point: the event row is written inside the SAME transaction as
 * the state change, so an event can never describe a state that was rolled back, and a state
 * change can never happen without its event being durably recorded. Broadcasting is deferred to
 * a job dispatched after commit, so a Reverb outage delays notifications instead of losing them
 * or — worse — rolling back a delivered refill because a socket was down.
 */
class EventPublisher
{
    /**
     * Record an event and schedule its delivery.
     *
     * MUST be called inside the transaction that performs the state change.
     *
     * @param  array<int,string>  $channels    channel names without the `private-` prefix
     * @param  array<int,int>  $notifyUserIds users who get a persisted in-app notification
     * @return string the event_id, which is the client's dedupe key across WebSocket and push (E15)
     */
    public function publish(
        string $type,
        string $title,
        string $body,
        array $channels,
        array $notifyUserIds = [],
        ?int $refillRequestId = null,
        ?string $status = null,
    ): string {
        $eventId = (string) Str::uuid();

        $payload = [
            'event_id' => $eventId,
            'type' => $type,
            'refill_request_id' => $refillRequestId,
            'status' => $status,
            'title' => $title,
            'body' => $body,
            'at' => now()->toIso8601String(),
        ];

        OutboxEvent::create([
            'event_id' => $eventId,
            'name' => $type,
            // Channels ride along in the stored payload and are stripped before broadcasting,
            // so the delivery job needs no second source of truth for routing.
            'payload_json' => $payload + ['channels' => array_values(array_unique($channels))],
        ]);

        // One notification row per recipient, sharing the event_id. The client dedupes on it, so
        // the same event arriving by socket and by push renders once (E15).
        foreach (array_values(array_unique(array_map('intval', $notifyUserIds))) as $userId) {
            AppNotification::create([
                'user_id' => $userId,
                'event_id' => $eventId,
                'type' => $type,
                'payload_json' => $payload,
            ]);
        }

        $outboxId = OutboxEvent::query()->where('event_id', $eventId)->value('id');

        // Dispatch only once the surrounding transaction commits. Dispatching inside it would let
        // the worker pick up a row that a rollback then erased.
        DB::afterCommit(function () use ($outboxId): void {
            PublishOutboxEvent::dispatch((int) $outboxId);
        });

        return $eventId;
    }
}
