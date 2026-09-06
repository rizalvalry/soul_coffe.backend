<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePinResetRequest;
use App\Models\AuditLog;
use App\Models\PinResetRequest;
use App\Models\User;
use App\Services\EventPublisher;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * "Lupa PIN" from the login screen (docs/04 §Auth).
 *
 * Unauthenticated by nature — the caller cannot sign in, that is the problem. So the response is
 * the same 202 whether or not the phone exists, whether or not the password matched: the caller
 * learns nothing, and the Administrator sees everything (including whether the password was
 * right) in the panel. Every accepted request also lands on every Administrator's phone as a push.
 *
 * Throttled hard at the route (see routes/api.php): this creates rows and pushes humans.
 */
class PinResetRequestController extends Controller
{
    public function __construct(private readonly EventPublisher $events) {}

    public function store(StorePinResetRequest $request): JsonResponse
    {
        $phone = PhoneNumber::normalize($request->validated('phone'));
        $email = mb_strtolower(trim($request->validated('email')));

        $user = User::query()->where('phone_e164', $phone)->first();

        // Unknown or inactive account: acknowledge and record nothing. A row per random phone
        // would let anyone fill the Administrator's queue with noise.
        if ($user && $user->is_active) {
            $passwordVerified = Hash::check($request->validated('password'), $user->password);

            DB::transaction(function () use ($user, $email, $passwordVerified, $request): void {
                $pending = PinResetRequest::query()
                    ->where('user_id', $user->id)
                    ->pending()
                    ->lockForUpdate()
                    ->first();

                if ($pending) {
                    // One open request per person. A repeat refreshes what the admin sees rather
                    // than stacking duplicates — and does not re-buzz every administrator.
                    $pending->forceFill([
                        'email' => $email,
                        'password_verified' => $pending->password_verified || $passwordVerified,
                        'requested_ip' => $request->ip(),
                        'attempts' => $pending->attempts + 1,
                    ])->save();

                    return;
                }

                $reset = PinResetRequest::query()->create([
                    'user_id' => $user->id,
                    'phone_e164' => $user->phone_e164,
                    'email' => $email,
                    'password_verified' => $passwordVerified,
                    'status' => PinResetRequest::STATUS_PENDING,
                    'requested_ip' => $request->ip(),
                    'attempts' => 1,
                ]);

                AuditLog::query()->create([
                    'actor_id' => $user->id,
                    'actor_role' => $user->role->value,
                    'action' => 'auth.pin_reset.requested',
                    'subject_type' => PinResetRequest::class,
                    'subject_id' => $reset->id,
                    'before_json' => null,
                    'after_json' => ['email' => $email, 'password_verified' => $passwordVerified],
                    'ip' => $request->ip(),
                    'device_id' => null,
                ]);

                $admins = User::query()
                    ->where('role', Role::ADMINISTRATOR)
                    ->where('is_active', true)
                    ->pluck('id')
                    ->all();

                $this->events->publish(
                    'PinResetRequested',
                    'Permintaan reset PIN',
                    sprintf(
                        '%s (%s) lupa PIN — %s. Buat kata sandi baru di panel admin.',
                        $user->name,
                        $user->role->label(),
                        $passwordVerified ? 'kata sandi terverifikasi' : 'kata sandi TIDAK cocok',
                    ),
                    ['role.ADMINISTRATOR'],
                    $admins,
                );
            });
        }

        return response()->json([
            'data' => [
                'status' => 'ACCEPTED',
                'message' => 'Permintaan terkirim. Administrator akan membuat kata sandi baru dan menghubungi Anda lewat email yang diisi.',
            ],
        ], 202);
    }
}
