<?php

namespace App\Services;

use App\Enums\PanelModule;
use App\Models\AuditLog;
use App\Models\DeviceLoginAttempt;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Access\PermissionMatrix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceBindingService
{
    public const PENDING_CODE = 'DEVICE_PENDING';

    public const REJECTED_CODE = 'DEVICE_REJECTED';

    public const UPDATE_CODE = 'DEVICE_UPDATE_REQUIRED';

    /** @return array{token?: string, code?: string, message?: string} */
    public function login(User $user, Request $request): array
    {
        return DB::transaction(function () use ($user, $request): array {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $id = trim((string) $request->input('device_id', ''));
            $details = [
                'device_name' => (string) $request->input('device_name'),
                'model' => $request->input('model'),
                'os_version' => $request->input('os_version'),
                'app_version' => $request->input('app_version'),
            ];
            $enforce = config('soul.device_binding_enforce');

            if ($id === '') {
                if ($enforce) {
                    $this->attempt($user, $request, null, false, 'DEVICE_UPDATE_REQUIRED');

                    return ['code' => self::UPDATE_CODE,
                        'message' => 'Perbarui aplikasi dulu, lalu coba login lagi ya.'];
                }
                $this->attempt($user, $request, null, true, 'UNKNOWN_DEVICE');

                return ['token' => $user->createToken($details['device_name'])->plainTextToken];
            }

            $active = UserDevice::query()->where('user_id', $user->id)
                ->where('status', UserDevice::ACTIVE)->lockForUpdate()->first();
            $device = UserDevice::query()->where('user_id', $user->id)
                ->where('device_id', $id)->lockForUpdate()->first();

            if ($device?->status === UserDevice::REJECTED && $enforce) {
                $this->attempt($user, $request, $device, false, 'DEVICE_REJECTED');

                return ['code' => self::REJECTED_CODE,
                    'message' => 'HP ini ditolak Administrator. Hubungi Administrator kalau mau dipakai lagi ya.'];
            }

            if ($active === null && $device?->status !== UserDevice::REJECTED) {
                if ($enforce) {
                    // Tokens issued by old APKs have no device identity and cannot survive binding.
                    $user->tokens()->whereNull('user_device_id')->delete();
                    DevicePushToken::query()->where('user_id', $user->id)
                        ->whereNull('user_device_id')->delete();
                }
                $device ??= new UserDevice(['user_id' => $user->id, 'device_id' => $id]);
                $device->fill($details + ['status' => UserDevice::ACTIVE, 'last_login_at' => now()]);
                $device->save();
                AuditLog::recordAction($user, 'auth.device.first_bound', $device, null,
                    ['device' => $device->maskedId(), 'status' => UserDevice::ACTIVE]);
                $reason = 'FIRST_BINDING';
            } elseif ($active?->device_id === $id) {
                $device = $active;
                $device->fill($details + ['last_login_at' => now()])->save();
                $reason = 'ACTIVE_DEVICE';
            } else {
                if ($device?->status !== UserDevice::REJECTED) {
                    $newRequest = $device?->status !== UserDevice::PENDING;
                    $device ??= new UserDevice(['user_id' => $user->id, 'device_id' => $id]);
                    $device->fill($details + ['status' => UserDevice::PENDING]);
                    $device->save();
                    if ($newRequest) {
                        AuditLog::recordAction($user, 'auth.device.requested', $device, null,
                            ['device' => $device->maskedId(), 'status' => UserDevice::PENDING]);
                        $recipients = array_values(array_unique(array_merge(
                            PermissionMatrix::usersWith(PanelModule::DEVICES, 'edit'),
                            User::managementIds(),
                        )));
                        app(EventPublisher::class)->publish('DeviceApprovalRequested',
                            'HP baru menunggu persetujuan',
                            $user->name.' minta pakai '.($details['model'] ?: $details['device_name']).' untuk login.',
                            array_map(fn (int $id): string => 'user.'.$id, $recipients),
                            notifyUserIds: $recipients,
                            context: ['source_id' => $device->id]);
                    }
                }
                if ($enforce) {
                    $this->attempt($user, $request, $device, false, 'DEVICE_PENDING');

                    return ['code' => self::PENDING_CODE,
                        'message' => 'HP baru perlu disetujui Administrator. Minta persetujuan, lalu coba login lagi.'];
                }
                $device->last_login_at = now();
                $device->save();
                $reason = $device->status === UserDevice::REJECTED ? 'REJECTED_TRANSITION' : 'PENDING_TRANSITION';
            }

            $this->attempt($user, $request, $device, true, $reason);
            $created = $user->createToken($details['device_name']);
            $created->accessToken->forceFill(['user_device_id' => $device->id])->save();

            return ['token' => $created->plainTextToken];
        });
    }

    public function credentialFailure(?User $user, Request $request): void
    {
        if ($user !== null) {
            $this->attempt($user, $request, null, false, 'BAD_CREDENTIALS');
        }
    }

    public function decide(UserDevice $device, User $actor, string $action, string $reason): void
    {
        abort_if(trim($reason) === '', 422, 'Alasan wajib diisi.');
        abort_unless(in_array($action, ['approve', 'reject', 'revoke'], true), 422);

        DB::transaction(function () use ($device, $actor, $action, $reason): void {
            $user = User::query()->whereKey($device->user_id)->lockForUpdate()->firstOrFail();
            $device = UserDevice::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            abort_unless(PermissionMatrix::can($actor->refresh(), PanelModule::DEVICES, 'edit'), 403);
            abort_if($action !== 'revoke' && $device->status !== UserDevice::PENDING, 409,
                'Permintaan HP ini sudah diproses.');
            abort_if($action === 'revoke' && $device->status === UserDevice::REVOKED, 409,
                'HP ini sudah dilepas.');

            $before = $device->status;
            if ($action === 'approve') {
                $previous = UserDevice::query()->where('user_id', $user->id)
                    ->where('status', UserDevice::ACTIVE)->lockForUpdate()->first();
                if ($previous !== null) {
                    $previous->status = UserDevice::REVOKED;
                    $previous->save();
                    $this->revokeTokens($user, $previous);
                    AuditLog::recordAction($actor, 'auth.device.replaced', $previous,
                        ['status' => UserDevice::ACTIVE], ['status' => UserDevice::REVOKED, 'reason' => $reason]);
                } else {
                    // Tokens from old APKs have no device mapping and cannot be trusted after approval.
                    $user->tokens()->whereNull('user_device_id')->delete();
                    DevicePushToken::query()->where('user_id', $user->id)
                        ->whereNull('user_device_id')->delete();
                }
                $device->status = UserDevice::ACTIVE;
                $device->approved_by = $actor->id;
                $device->approved_at = now();
            } elseif ($action === 'reject') {
                $device->status = UserDevice::REJECTED;
                $this->revokeTokens($user, $device);
            } else {
                $device->status = UserDevice::REVOKED;
                $this->revokeTokens($user, $device);
            }
            $device->save();
            AuditLog::recordAction($actor, 'auth.device.'.$action, $device,
                ['status' => $before], ['status' => $device->status, 'reason' => $reason]);
        });
    }

    private function revokeTokens(User $user, UserDevice $device): void
    {
        $user->tokens()->where(function ($query) use ($device): void {
            $query->where('user_device_id', $device->id)->orWhereNull('user_device_id');
        })->delete();
        DevicePushToken::query()->where('user_id', $user->id)
            ->where(function ($query) use ($device): void {
                $query->where('user_device_id', $device->id)->orWhereNull('user_device_id');
            })->delete();
    }

    private function attempt(User $user, Request $request, ?UserDevice $device, bool $success, string $reason): void
    {
        DeviceLoginAttempt::query()->create([
            'user_id' => $user->id,
            'user_device_id' => $device?->id,
            'device_id' => $request->input('device_id'),
            'device_name' => $request->input('device_name'),
            'model' => $request->input('model'),
            'ip' => $request->ip(),
            'succeeded' => $success,
            'reason' => $reason,
        ]);
    }
}
