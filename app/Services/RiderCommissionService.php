<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RiderCommissionService
{
    /** @return array<int, array<string, mixed>> */
    public function tiersFor(int $riderId, string $date): array
    {
        $version = DB::table('rider_commission_versions')
            ->where('rider_id', $riderId)->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->orderByDesc('id')->first();

        return $version ? DB::table('rider_commission_tiers')->where('version_id', $version->id)
            ->orderBy('min_cups')->get(['min_cups', 'amount_per_cup_minor'])
            ->map(fn ($tier): array => [
                'min_cups' => (int) $tier->min_cups,
                'amount_per_cup_minor' => (int) $tier->amount_per_cup_minor,
            ])->all() : [];
    }

    /**
     * One batched projection serves CMS, payroll and the Closing response. An approved day's
     * snapshot wins over later tier edits and later Finance Closing corrections.
     *
     * @param  array<int, int>  $riderIds
     * @return array<int, array<string, array<string, int|bool|null>>>
     */
    public function summaries(array $riderIds, string $from, string $through): array
    {
        if ($riderIds === []) {
            return [];
        }
        $riderIds = array_values(array_unique(array_map('intval', $riderIds)));
        $versions = DB::table('rider_commission_versions')
            ->whereIn('rider_id', $riderIds)->where('effective_from', '<=', $through)
            ->orderByDesc('effective_from')->orderByDesc('id')->get();
        $tiers = DB::table('rider_commission_tiers')->whereIn('version_id', $versions->pluck('id'))
            ->orderBy('min_cups')->get()->groupBy('version_id');
        $byRider = $versions->groupBy('rider_id');
        $cups = DB::table('rider_closings')
            ->leftJoin('rider_closing_lines', 'rider_closing_lines.rider_closing_id', '=', 'rider_closings.id')
            ->leftJoin('products', 'products.id', '=', 'rider_closing_lines.product_id')
            ->whereIn('rider_closings.rider_id', $riderIds)
            ->whereBetween('rider_closings.operating_date', [$from, $through])
            ->groupBy('rider_closings.rider_id', 'rider_closings.operating_date')
            ->selectRaw("rider_closings.rider_id, rider_closings.operating_date, SUM(CASE WHEN products.unit = 'cup' THEN rider_closing_lines.qty_sold ELSE 0 END) AS cups_sold")
            ->get();
        $locks = DB::table('rider_daily_commission_locks')->whereIn('rider_id', $riderIds)
            ->whereBetween('operating_date', [$from, $through])->get();
        $out = [];
        foreach ($cups as $row) {
            $riderId = (int) $row->rider_id;
            $date = $row->operating_date;
            $out[$riderId][$date] = $this->calculate((int) $row->cups_sold, $date, $byRider->get($riderId, collect()), $tiers);
        }
        foreach ($locks as $lock) {
            $out[(int) $lock->rider_id][$lock->operating_date] = [
                'has_closing' => true, 'cups_sold' => (int) $lock->cups_sold,
                'commission_minor' => (int) $lock->commission_minor,
                'tier_min_cups' => $lock->tier_min_cups === null ? null : (int) $lock->tier_min_cups,
                'amount_per_cup_minor' => (int) $lock->amount_per_cup_minor,
                'next_tier_min_cups' => null, 'cups_to_next_tier' => null,
                'locked' => true,
            ];
        }

        return $out;
    }

    /** @return array<string, int|bool|null> */
    public function day(int $riderId, string $date): array
    {
        return $this->summaries([$riderId], $date, $date)[$riderId][$date] ?? [
            'has_closing' => false, 'cups_sold' => 0, 'commission_minor' => 0,
            'tier_min_cups' => null, 'amount_per_cup_minor' => 0,
            'next_tier_min_cups' => null, 'cups_to_next_tier' => null, 'locked' => false,
        ];
    }

    /** @param  iterable<object>  $versions */
    private function calculate(int $cups, string $date, iterable $versions, mixed $tiers): array
    {
        $version = null;
        foreach ($versions as $candidate) {
            if ($candidate->effective_from <= $date) {
                $version = $candidate;
                break;
            }
        }
        $selected = null;
        $next = null;
        foreach ($version ? $tiers->get($version->id, collect()) : [] as $tier) {
            if ((int) $tier->min_cups <= $cups) {
                $selected = $tier;
            } elseif ($next === null) {
                $next = (int) $tier->min_cups;
            }
        }
        $rate = (int) ($selected?->amount_per_cup_minor ?? 0);
        if ($rate !== 0 && $cups > intdiv(PHP_INT_MAX, $rate)) {
            throw new DomainException('Nominal komisi terlalu besar. Cek lagi tier-nya ya.');
        }

        return [
            'has_closing' => true, 'cups_sold' => $cups,
            'commission_minor' => $cups * $rate,
            'tier_min_cups' => $selected ? (int) $selected->min_cups : null,
            'amount_per_cup_minor' => $rate,
            'next_tier_min_cups' => $next,
            'cups_to_next_tier' => $next === null ? null : $next - $cups,
            'locked' => false,
        ];
    }

    public function lockForSettlement(int $cartId, string $date): void
    {
        $riderIds = DB::table('rider_closings')->where('cart_id', $cartId)
            ->where('operating_date', $date)->pluck('rider_id')->unique()->all();
        foreach ($riderIds as $riderId) {
            $summary = $this->day((int) $riderId, $date);
            if (! $summary['has_closing']) {
                continue;
            }
            $versionId = DB::table('rider_commission_versions')
                ->where('rider_id', $riderId)->where('effective_from', '<=', $date)
                ->orderByDesc('effective_from')->orderByDesc('id')->value('id');
            DB::table('rider_daily_commission_locks')->insertOrIgnore([
                'rider_id' => $riderId, 'operating_date' => $date,
                'cups_sold' => $summary['cups_sold'], 'version_id' => $versionId,
                'tier_min_cups' => $summary['tier_min_cups'],
                'amount_per_cup_minor' => $summary['amount_per_cup_minor'],
                'commission_minor' => $summary['commission_minor'], 'locked_at' => now(),
            ]);
        }
    }

    /** An approved payroll cannot rely on a Closing that Rider may still correct. */
    public function hasUnlockedClosing(array $riderIds, string $from, string $through): bool
    {
        if ($riderIds === []) {
            return false;
        }

        return DB::table('rider_closings')
            ->leftJoin('rider_daily_commission_locks', function ($join): void {
                $join->on('rider_daily_commission_locks.rider_id', '=', 'rider_closings.rider_id')
                    ->on('rider_daily_commission_locks.operating_date', '=', 'rider_closings.operating_date');
            })
            ->whereIn('rider_closings.rider_id', $riderIds)
            ->whereBetween('rider_closings.operating_date', [$from, $through])
            ->whereNull('rider_daily_commission_locks.id')->exists();
    }

    /**
     * Append a new version for every target. A repeated Livewire request key is a no-op.
     *
     * @param  array<int, int>  $riderIds
     * @param  array<int, array{min_cups:int, amount_per_cup_minor:int}>  $rows
     */
    public function save(array $riderIds, string $date, array $rows, User $actor, string $requestKey): void
    {
        if (! in_array($actor->role, [Role::FINANCE, Role::MANAGEMENT, Role::ADMINISTRATOR], true)) {
            abort(403);
        }
        $riderIds = array_values(array_unique(array_map('intval', $riderIds)));
        sort($riderIds);
        if ($riderIds === [] || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ||
            ! \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ||
            \DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date ||
            ! preg_match('/^[0-9a-f-]{36}$/iD', $requestKey)) {
            throw ValidationException::withMessages(['tiers' => 'Tanggal, Rider, atau kunci permintaan belum valid ya.']);
        }
        if (User::query()->whereIn('id', $riderIds)->where('role', Role::STAFF->value)->where('is_active', true)->count() !== count($riderIds)) {
            throw ValidationException::withMessages(['riders' => 'Pilih Rider aktif saja ya.']);
        }
        if ($rows === [] || count($rows) > 20) {
            throw ValidationException::withMessages(['tiers' => 'Isi 1 sampai 20 tier ya.']);
        }
        $last = 0;
        foreach ($rows as $row) {
            if (! is_int($row['min_cups'] ?? null) || ! is_int($row['amount_per_cup_minor'] ?? null)
                || $row['min_cups'] <= $last || $row['min_cups'] > 1000000
                || $row['amount_per_cup_minor'] < 0 || $row['amount_per_cup_minor'] > 1000000000) {
                throw ValidationException::withMessages(['tiers' => 'Tier harus urut naik, unik, dan berisi rupiah bulat ya.']);
            }
            $last = $row['min_cups'];
        }
        $hash = hash('sha256', json_encode([$riderIds, $date, $rows], JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($riderIds, $date, $rows, $actor, $requestKey, $hash): void {
            $existing = DB::table('rider_commission_changes')->where('request_key', $requestKey)->first();
            if ($existing) {
                if ($existing->payload_hash !== $hash || (int) $existing->actor_id !== $actor->id) {
                    throw ValidationException::withMessages(['tiers' => 'Kunci permintaan ini sudah dipakai untuk perubahan lain ya.']);
                }

                return;
            }
            $inserted = DB::table('rider_commission_changes')->insertOrIgnore([
                'request_key' => $requestKey, 'payload_hash' => $hash,
                'actor_id' => $actor->id, 'created_at' => now(),
            ]);
            $change = DB::table('rider_commission_changes')->where('request_key', $requestKey)->lockForUpdate()->firstOrFail();
            if ($change->payload_hash !== $hash || (int) $change->actor_id !== $actor->id) {
                throw ValidationException::withMessages(['tiers' => 'Kunci permintaan ini sudah dipakai untuk perubahan lain ya.']);
            }
            if ($inserted === 0) {
                return;
            }
            foreach ($riderIds as $riderId) {
                $versionId = DB::table('rider_commission_versions')->insertGetId([
                    'rider_id' => $riderId, 'effective_from' => $date,
                    'change_id' => $change->id, 'created_at' => now(),
                ]);
                DB::table('rider_commission_tiers')->insert(array_map(fn (array $row): array => [
                    'version_id' => $versionId,
                    'min_cups' => $row['min_cups'],
                    'amount_per_cup_minor' => $row['amount_per_cup_minor'],
                ], $rows));
            }
        }, 3);
    }
}
