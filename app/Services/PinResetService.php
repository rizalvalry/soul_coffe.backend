<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PinResetRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * The only writer of a resolved "lupa PIN" request.
 *
 * Resolving is three changes that must happen together or not at all:
 *
 *   1. the password becomes what the Administrator typed;
 *   2. `login_pin_hash` is cleared, which is what reopens the password sign-in route
 *      (AuthController::login refuses a password while a PIN exists);
 *   3. every Sanctum token is revoked, so a device that still holds a session cannot keep acting
 *      as the account whose credentials just changed under it.
 *
 * Miss (2) and the new password is unusable. Miss (3) and a stolen phone keeps its access after
 * the very reset that was meant to take it away. Hence one transaction, one entry point.
 *
 * Push registrations are deliberately NOT deleted: the same person will sign back in on the same
 * phone, and the token is re-registered on that login anyway. On a phone that was actually lost,
 * the revoked session is what stops the app from opening; a push preview is not the exposure.
 */
class PinResetService
{
    public function resolve(PinResetRequest $request, User $administrator, string $newPassword, ?string $note = null): void
    {
        DB::transaction(function () use ($request, $administrator, $newPassword, $note): void {
            /** @var PinResetRequest $locked */
            $locked = PinResetRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            // Two administrators opening the queue at the same moment: the second one finds the
            // row already handled and changes nothing, rather than setting a second password that
            // silently invalidates the one the first administrator already read out.
            if (! $locked->isPending()) {
                return;
            }

            /** @var User $user */
            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();

            $user->forceFill([
                // The `hashed` cast on User covers `password`, but forceFill bypasses nothing:
                // the cast still applies on save. Hashing here as well would store a hash of a
                // hash, so it is deliberately left to the cast.
                'password' => $newPassword,
                'login_pin_hash' => null,
                'login_pin_failures' => 0,
                'login_pin_locked_until' => null,
            ])->save();

            $user->tokens()->delete();

            $locked->forceFill([
                'status' => PinResetRequest::STATUS_RESOLVED,
                'resolved_by' => $administrator->id,
                'resolved_at' => now(),
                'resolution_note' => $note,
            ])->save();

            AuditLog::query()->create([
                'actor_id' => $administrator->id,
                'actor_role' => $administrator->role->value,
                'action' => 'auth.pin_reset.resolved',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'before_json' => null,
                // Never the password itself. What matters for the trail is that access was reset,
                // by whom, and against which request.
                'after_json' => [
                    'request_id' => $locked->id,
                    'password_replaced' => true,
                    'login_pin_cleared' => true,
                    'sessions_revoked' => true,
                    'note' => $note,
                ],
                'ip' => request()?->ip(),
                'device_id' => null,
            ]);
        });
    }

    public function reject(PinResetRequest $request, User $administrator, string $reason): void
    {
        DB::transaction(function () use ($request, $administrator, $reason): void {
            /** @var PinResetRequest $locked */
            $locked = PinResetRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPending()) {
                return;
            }

            $locked->forceFill([
                'status' => PinResetRequest::STATUS_REJECTED,
                'resolved_by' => $administrator->id,
                'resolved_at' => now(),
                'resolution_note' => $reason,
            ])->save();

            AuditLog::query()->create([
                'actor_id' => $administrator->id,
                'actor_role' => $administrator->role->value,
                'action' => 'auth.pin_reset.rejected',
                'subject_type' => PinResetRequest::class,
                'subject_id' => $locked->id,
                'before_json' => null,
                'after_json' => ['reason' => $reason],
                'ip' => request()?->ip(),
                'device_id' => null,
            ]);
        });
    }
}
