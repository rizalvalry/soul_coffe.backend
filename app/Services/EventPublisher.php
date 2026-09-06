<?php

namespace App\Services;

use App\Jobs\PublishOutboxEvent;
use App\Models\AppNotification;
use App\Models\OutboxEvent;
use App\Services\Push\PushNotifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only publisher of realtime events (docs/04 §Realtime, requirement 3).
 *
 * The outbox pattern is the whole point: the event row is written inside the SAME transaction as
 * the state change, so an event can never describe a state that was rolled back, and a state
 * change can never happen without its event being durably recorded. Broadcasting happens after
 * commit, so a broadcaster outage delays notifications instead of losing them or — worse —
 * rolling back a delivered refill because a socket was down.
 */
class EventPublisher
{
    public function __construct(private readonly PushNotifier $push) {}

    /**
     * Record an event and schedule its delivery.
     *
     * MUST be called inside the transaction that performs the state change.
     *
     * @param  array<int,string>  $channels  channel names without the `private-` prefix
     * @param  array<int,int>  $notifyUserIds  users who get a persisted in-app notification
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

        $recipients = array_values(array_unique(array_map('intval', $notifyUserIds)));
        // Resolved now, not inside the closure: by the time afterCommit runs, a Filament action or
        // a console command may have no request-bound guard left to ask.
        $actorId = Auth::id();

        // Deliver only once the surrounding transaction commits. Delivering inside it would
        // broadcast a row that a rollback then erased.
        DB::afterCommit(function () use ($outboxId, $recipients, $actorId, $title, $body, $payload): void {
            $this->broadcast((int) $outboxId);

            // Second transport (E15). Inline rather than queued — see PushNotifier for why — and
            // never to the actor: the person who just tapped the button is looking at the result.
            $this->push->notifyUsers(
                $recipients,
                $title,
                $body,
                [
                    'event_id' => $payload['event_id'],
                    'type' => $payload['type'],
                    'refill_request_id' => $payload['refill_request_id'],
                    'status' => $payload['status'],
                    'at' => $payload['at'],
                ],
                $actorId !== null ? (int) $actorId : null,
            );
        });

        return $eventId;
    }

    /**
     * Push one outbox row onto the WebSocket, immediately, with the queue as the retry path.
     *
     * This used to be `PublishOutboxEvent::dispatch()` and nothing else, which made every realtime
     * notification depend on a resident queue worker. This host has none — no supervisor, no
     * systemd, and no `crontab` binary — so events accumulated in `jobs` and the app fell back to
     * its 10-second refetch. The fallback working is what made the bug survive: it looked like
     * "realtime is slow" rather than "realtime never ran".
     *
     * Running the job's own `handle()` inline keeps ONE definition of what publishing means
     * (find the row, skip if already published, broadcast, stamp `published_at`), so the inline
     * path and the queued path can never drift apart.
     *
     * On failure the row is still unpublished — `handle()` stamps only after the broadcast returns
     * — so re-dispatching it to the queue loses nothing and duplicates nothing: whichever attempt
     * wins stamps the row, and the other sees `published_at` set and returns. The queue is now the
     * degraded path rather than the only path, which is the right shape for shared hosting.
     */
    private function broadcast(int $outboxId): void
    {
        try {
            (new PublishOutboxEvent($outboxId))->handle();
        } catch (Throwable $e) {
            // Never rethrow: the state change is committed and the event is durable in
            // `outbox_events`. A Pusher outage must not turn a successful approval into a 500.
            Log::warning('broadcast: inline publish failed, falling back to queue', [
                'outbox_event_id' => $outboxId,
                'error' => $e->getMessage(),
            ]);

            PublishOutboxEvent::dispatch($outboxId);
        }
    }
}
