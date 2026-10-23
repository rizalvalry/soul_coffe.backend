<?php

namespace App\Services;

use App\Enums\PanelModule;
use App\Models\AuditLog;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\LocationSetting;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LocationSettings
{
    private const SNAPSHOT_KEY = 'soul.location_settings_snapshot';

    public function defaults(): array
    {
        return $this->snapshot()['values'];
    }

    /** Values and revision come from the same row so a page cannot pair stale values with a new revision. */
    public function snapshot(): array
    {
        if (request()->attributes->has(self::SNAPSHOT_KEY)) {
            return request()->attributes->get(self::SNAPSHOT_KEY);
        }
        $row = LocationSetting::query()->find(1);

        $snapshot = ['values' => $this->values($row), 'version' => $row?->version ?? 0];
        request()->attributes->set(self::SNAPSHOT_KEY, $snapshot);

        return $snapshot;
    }

    private function values(?LocationSetting $row): array
    {
        return [
            'absen_radius_m' => $row?->absen_radius_m ?? (int) config('soul.absen_geofence_m', 10),
            'customer_radius_m' => $row?->customer_radius_m ?? 2000,
            'absen_requires_gps' => $row?->absen_requires_gps ?? (bool) config('soul.absen_requires_gps', true),
        ];
    }

    public function absenRadius(): int
    {
        return $this->defaults()['absen_radius_m'];
    }

    public function customerRadius(): int
    {
        return $this->defaults()['customer_radius_m'];
    }

    public function requiresGps(): bool
    {
        return $this->defaults()['absen_requires_gps'];
    }

    public function radiusFor(CentralKitchen|Location|null $place): int
    {
        return (int) ($place?->geofence_m ?: $this->absenRadius());
    }

    public function saveDefaults(User $actor, array $values, string $reason, int $expectedVersion): void
    {
        $this->authorize($actor, PanelModule::LOCATION_SETTINGS);
        $data = Validator::make([...$values, 'reason' => $reason], [
            'absen_radius_m' => ['required', 'integer', 'between:5,5000'],
            'customer_radius_m' => ['required', 'integer', 'between:100,20000'],
            'absen_requires_gps' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'absen_radius_m.required' => 'Isi radius bawaan absen dulu ya.',
            'absen_radius_m.integer' => 'Radius bawaan absen harus angka bulat ya.',
            'absen_radius_m.between' => 'Radius bawaan absen harus 5–5.000 m ya.',
            'customer_radius_m.required' => 'Isi radius CustomerApps dulu ya.',
            'customer_radius_m.integer' => 'Radius CustomerApps harus angka bulat ya.',
            'customer_radius_m.between' => 'Radius CustomerApps harus 100–20.000 m ya.',
            'absen_requires_gps.required' => 'Pilih status GPS dulu ya.',
            'absen_requires_gps.boolean' => 'Pilih status GPS yang valid ya.',
            'reason.required' => 'Isi alasan perubahan dulu ya.',
            'reason.min' => 'Alasan perubahan minimal 5 karakter ya.',
            'reason.max' => 'Alasan perubahan maksimal 2.000 karakter ya.',
        ])->validate();

        DB::transaction(function () use ($actor, $data, $reason, $expectedVersion): void {
            if ($expectedVersion === 0) {
                // A missing row uses config as its fallback. Seed it atomically before locking so
                // two first-time editors cannot both attempt to insert row #1.
                DB::table('location_settings')->insertOrIgnore([
                    'id' => 1,
                    'absen_radius_m' => (int) config('soul.absen_geofence_m', 10),
                    'customer_radius_m' => 2000,
                    'absen_requires_gps' => (bool) config('soul.absen_requires_gps', true),
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $row = LocationSetting::query()->lockForUpdate()->find(1);
            if (($row?->version ?? 0) !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'defaults' => 'Pengaturan sudah diubah orang lain. Muat ulang halaman, lalu coba lagi ya.',
                ]);
            }
            $before = $this->values($row);
            if ($row === null) {
                $row = new LocationSetting;
                $row->id = 1;
            }
            $after = [
                'absen_radius_m' => (int) $data['absen_radius_m'],
                'customer_radius_m' => (int) $data['customer_radius_m'],
                'absen_requires_gps' => (bool) $data['absen_requires_gps'],
            ];
            if ($before === $after) {
                return;
            }
            $row->fill($after);
            $row->version = $expectedVersion + 1;
            $row->save();
            AuditLog::recordAction($actor, 'location_settings.changed', $row,
                [...$before, 'reason' => $reason], [...$after, 'reason' => $reason]);
        });
        request()->attributes->remove(self::SNAPSHOT_KEY);
    }

    public function saveRadius(User $actor, CentralKitchen|Location $subject, int|string $radius, string $reason, PanelModule $module = PanelModule::LOCATION_SETTINGS, bool $useDefault = false): void
    {
        $this->authorize($actor, $module);
        $data = Validator::make(['radius' => $radius, 'reason' => $reason], [
            'radius' => $useDefault ? ['nullable'] : ['required', 'integer', 'between:5,5000'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'radius.required' => 'Isi radius dulu ya.',
            'radius.integer' => 'Radius harus angka bulat ya.',
            'radius.between' => 'Radius harus 5–5.000 m ya.',
            'reason.required' => 'Isi alasan perubahan dulu ya.',
            'reason.min' => 'Alasan perubahan minimal 5 karakter ya.',
            'reason.max' => 'Alasan perubahan maksimal 2.000 karakter ya.',
        ])->validate();

        DB::transaction(function () use ($actor, $subject, $data, $reason, $useDefault): void {
            $row = $subject::query()->lockForUpdate()->findOrFail($subject->id);
            $before = (int) $row->geofence_m;
            $beforeEffective = $this->radiusFor($row);
            $after = $useDefault ? 0 : (int) $data['radius'];
            if ($before === $after) {
                return;
            }
            $row->update(['geofence_m' => $after]);
            AuditLog::recordAction($actor, 'location_radius.changed', $row,
                ['geofence_m' => $before, 'effective_radius_m' => $beforeEffective, 'reason' => $reason],
                ['geofence_m' => $after, 'effective_radius_m' => $this->radiusFor($row), 'reason' => $reason]);
        });
    }

    private function authorize(User $actor, PanelModule $module): void
    {
        abort_unless(PermissionMatrix::can($actor, $module, 'edit'), 403);
    }
}
