<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\RiderLocationSetup;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RiderLocationSetupService
{
    public function __construct(private readonly EventPublisher $events) {}

    public function active(User $rider): ?RiderLocationSetup
    {
        return RiderLocationSetup::query()->with('location')->where('rider_id', $rider->id)
            ->whereNull('superseded_at')->latest('id')->first();
    }

    public function riders(): array
    {
        return User::query()->where('role', Role::STAFF)->where('is_active', true)
            ->with(['locationSetups' => fn ($query) => $query->with(['location:id,name,lat,lng,geofence_m', 'changedBy:id,name'])->latest('id')])
            ->orderBy('name')->get(['id', 'name'])->map(function (User $rider): array {
                $history = $rider->locationSetups;

                return [
                    'id' => $rider->id,
                    'name' => $rider->name,
                    'setup' => $history->firstWhere('superseded_at', null)?->toArray(),
                    'history' => $history->map(fn (RiderLocationSetup $row): array => $row->toArray())->all(),
                ];
            })->all();
    }

    public function save(User $actor, User $rider, ?int $locationId, string $basis, ?string $idempotencyKey = null): RiderLocationSetup
    {
        if (! $actor->role->grants(Role::MANAGEMENT) || ! $actor->is_active) {
            abort(403);
        }
        if ($rider->role !== Role::STAFF || ! $rider->is_active) {
            throw new RuntimeException('Rider aktif tidak ditemukan ya.');
        }

        return DB::transaction(function () use ($actor, $rider, $locationId, $basis, $idempotencyKey): RiderLocationSetup {
            User::query()->whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $requestHash = hash('sha256', json_encode([$rider->id, $locationId, $basis], JSON_THROW_ON_ERROR));
            $keyHash = $idempotencyKey === null ? null : hash('sha256', $actor->id.':'.$idempotencyKey);
            if ($keyHash !== null) {
                $prior = DB::table('rider_location_setup_idempotency_keys')->where('key_hash', $keyHash)->first();
                if ($prior) {
                    if ($prior->request_hash !== $requestHash) {
                        throw new HttpResponseException(response()->json([
                            'code' => 'IDEMPOTENCY_CONFLICT',
                            'message' => 'Kunci kirim ini sudah dipakai buat setup lain. Muat ulang ya.',
                        ], 409));
                    }

                    return RiderLocationSetup::query()->with(['location', 'changedBy'])
                        ->findOrFail($prior->rider_location_setup_id);
                }
            }
            if ($basis === 'selling_location') {
                $location = Location::query()->find($locationId);
                if (! $location || $location->lat === null || $location->lng === null) {
                    throw new RuntimeException('Pilih titik jualan yang sudah punya pin GPS ya.');
                }
            }
            $old = RiderLocationSetup::query()->where('rider_id', $rider->id)
                ->whereNull('superseded_at')->latest('id')->lockForUpdate()->first();
            if ($old && (int) $old->location_id === (int) $locationId && $old->basis === $basis) {
                $this->rememberKey($keyHash, $requestHash, $old->id);

                return $old->load(['location', 'changedBy']);
            }
            $before = $old?->only(['location_id', 'basis']);
            $old?->update(['superseded_at' => now()]);
            $new = RiderLocationSetup::query()->create([
                'rider_id' => $rider->id,
                'location_id' => $locationId,
                'basis' => $basis,
                'changed_by' => $actor->id,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'actor_role' => $actor->role->value,
                'action' => 'rider_location_setup.changed',
                'subject_type' => RiderLocationSetup::class, 'subject_id' => $new->id,
                'before_json' => $before,
                'after_json' => $new->only(['rider_id', 'location_id', 'basis']),
                'ip' => request()->ip(),
            ]);
            $this->events->publish(
                'RiderLocationSetupChanged',
                'Setup Lokasi Rider diperbarui',
                "Setup lokasi {$rider->name} sudah diperbarui ya.",
                ['role.MANAGEMENT', 'role.BARISTA', 'user.'.$rider->id],
                context: ['source_id' => $new->id],
            );
            $this->rememberKey($keyHash, $requestHash, $new->id);

            return $new->load(['location', 'changedBy']);
        });
    }

    private function rememberKey(?string $keyHash, string $requestHash, int $setupId): void
    {
        if ($keyHash === null) {
            return;
        }

        DB::table('rider_location_setup_idempotency_keys')->insert([
            'key_hash' => $keyHash,
            'request_hash' => $requestHash,
            'rider_location_setup_id' => $setupId,
            'created_at' => now(),
        ]);
    }
}
