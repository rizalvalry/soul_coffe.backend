<?php

namespace App\Services;

use App\Enums\PanelModule;
use App\Jobs\PublishOutboxEvent;
use App\Models\AppNotification;
use App\Models\OutboxEvent;
use App\Services\Access\PermissionMatrix;
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
     * @param  array{cart_id?: int, operating_date?: string, source_id?: int}  $context
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
        bool $includeActorPush = false,
        array $context = [],
    ): string {
        $eventId = (string) Str::uuid();

        // Add each current holder's private channel to the existing outbox row. Role channels
        // still carry field-flow and informative events; a new permission needs no extra event.
        $module = match ($type) {
            'RefillRequestSubmitted', 'RefillRequestExpired' => PanelModule::REFILL_APPROVAL,
            'SettlementRecorded', 'SettlementApproved', 'SettlementExpectedStale',
            'SettlementVarianceFlagged', 'SettlementMethodVarianceFlagged',
            'SettlementSelfApproved', 'CartCloseOutVarianceFlagged',
            'RiderClosingRecorded', 'RiderClosingCorrected', 'SaleRecorded', 'SaleVoided',
            'CartArrivalChanged' => PanelModule::SETTLEMENTS,
            'RiderLocationSetupMissing', 'RiderLocationSetupChanged' => PanelModule::RIDER_LOCATION_SETUP,
            'AccountingPeriodReopened' => PanelModule::ACCOUNTING_PERIODS,
            default => null,
        };
        if ($module !== null) {
            foreach (PermissionMatrix::usersWith($module) as $id) {
                $channels[] = 'user.'.$id;
            }
        }

        if (in_array($type, ['RefillRequestSubmitted', 'RefillRequestExpired',
            'SettlementVarianceFlagged', 'SettlementMethodVarianceFlagged',
            'SettlementExpectedStale', 'CartCloseOutVarianceFlagged',
            'RiderLocationSetupMissing', 'AccountingPeriodReopened'], true)) {
            $channels = array_values(array_filter($channels,
                fn (string $channel): bool => ! in_array($channel,
                    ['role.FINANCE', 'role.MANAGEMENT', 'role.ADMINISTRATOR'], true)));
        }

        if (array_intersect(['role.FINANCE', 'role.MANAGEMENT'], $channels)
            && ! in_array('role.ADMINISTRATOR', $channels, true)) {
            $channels[] = 'role.ADMINISTRATOR';
        }

        $payload = [
            'event_id' => $eventId,
            'type' => $type,
            'refill_request_id' => $refillRequestId,
            'status' => $status,
            'title' => $title,
            'body' => $body,
            'at' => now()->toIso8601String(),
        ] + $context;

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
        DB::afterCommit(function () use ($outboxId, $recipients, $actorId, $title, $body, $payload, $includeActorPush): void {
            try {
                if (config('outbox.publish_mode') === 'queue') {
                    PublishOutboxEvent::enqueue((int) $outboxId);
                } else {
                    (new PublishOutboxEvent((int) $outboxId))->handle();
                }
            } catch (Throwable $e) {
                // A failed transport must not undo a committed sale or settlement. The same
                // outbox row stays unpublished for scheduled or lazy recovery.
                Log::warning('broadcast: could not publish outbox event', [
                    'outbox_event_id' => $outboxId,
                    'error' => $e->getMessage(),
                ]);
            }

            // Second transport (E15). Inline rather than queued — see PushNotifier for why.
            // The actor is skipped unless the event explicitly includes them as a recipient.
            if ($recipients !== []) {
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
                        ...($payload['operating_date'] ?? null ? ['operating_date' => $payload['operating_date']] : []),
                    ],
                    $includeActorPush ? null : ($actorId !== null ? (int) $actorId : null),
                );
            }
        });

        return $eventId;
    }
}
