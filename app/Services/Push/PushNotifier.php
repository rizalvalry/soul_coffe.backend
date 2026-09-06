<?php

namespace App\Services\Push;

use App\Models\DevicePushToken;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push transport for business events — the second leg alongside the WebSocket (E15).
 *
 * Called synchronously after the state-change transaction commits, NOT from the queue. That is a
 * deliberate departure from PublishOutboxEvent: on this hosting the `database` queue has no
 * resident worker, so a queued push would sit in `jobs` until a cron tick that may never come.
 * A push that arrives thirty minutes late is worse than useless for "your refill is ready". The
 * cost is a few hundred milliseconds of HTTP on the request that caused the event, capped by the
 * client timeout — acceptable for an internal ops API with a handful of recipients per event.
 *
 * Never throws. A push provider outage must not fail the approval that triggered it; the event is
 * already durable in `outbox_events` and `notifications`, so nothing is lost, only not pushed.
 */
class PushNotifier
{
    public function __construct(private readonly FcmClient $fcm) {}

    public function isEnabled(): bool
    {
        return $this->fcm->isConfigured();
    }

    /**
     * @param  array<int,int|string>  $userIds
     * @param  array<string,mixed>  $data  extra key/values delivered to the app (coerced to strings)
     * @param  int|null  $excludeUserId  usually the actor — the person who just tapped the button is
     *                                   looking at the confirmation and does not need to be buzzed
     */
    public function notifyUsers(array $userIds, string $title, string $body, array $data = [], ?int $excludeUserId = null): void
    {
        try {
            $ids = array_values(array_unique(array_map('intval', $userIds)));
            if ($excludeUserId !== null) {
                $ids = array_values(array_filter($ids, fn (int $id) => $id !== $excludeUserId));
            }

            if ($ids === []) {
                return;
            }

            if (! $this->isEnabled()) {
                Log::debug('push: skipped, FCM not configured', ['type' => $data['type'] ?? null, 'recipients' => count($ids)]);

                return;
            }

            $registrations = DevicePushToken::query()
                ->whereIn('user_id', $ids)
                ->whereNull('failed_at')
                ->orderByDesc('last_seen_at')
                ->limit(200)
                ->get(['id', 'user_id', 'token']);

            if ($registrations->isEmpty()) {
                return;
            }

            $results = $this->fcm->sendMany($registrations->pluck('token')->all(), $title, $body, $data);

            $dead = $registrations
                ->filter(fn (DevicePushToken $row) => ($results[$row->token] ?? null) === FcmResult::Unregistered)
                ->pluck('id');

            if ($dead->isNotEmpty()) {
                DevicePushToken::query()->whereIn('id', $dead)->delete();
            }

            $failed = count(array_filter($results, fn (FcmResult $r) => $r === FcmResult::Failed));
            if ($failed > 0) {
                Log::warning('push: some deliveries failed', ['type' => $data['type'] ?? null, 'failed' => $failed, 'total' => count($results)]);
            }
        } catch (Throwable $e) {
            Log::error('push: notifier error', ['type' => $data['type'] ?? null, 'error' => $e->getMessage()]);
        }
    }
}
