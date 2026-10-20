<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FixedAssetService
{
    public function __construct(private readonly JournalService $journals) {}

    public function acquire(array $input, User $actor): FixedAsset
    {
        Gate::forUser($actor)->authorize('record-fixed-assets');
        $data = Validator::make($input, [
            'uuid' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:fixed_asset_categories,id'],
            'acquired_on' => ['required', 'date_format:Y-m-d'],
            'cost_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'useful_life_months' => ['required', 'integer', 'min:1', 'max:1200'],
            'residual_minor' => ['required', 'integer', 'min:0'],
            'location' => ['nullable', 'string', 'max:255'],
            'cart_id' => ['nullable', 'integer', 'exists:carts,id'],
            'acquisition_mode' => ['required', 'in:CASH,DEBT,OPENING'],
            'funding_account_id' => ['required_unless:acquisition_mode,OPENING', 'nullable', 'integer', 'exists:accounts,id'],
        ])->validate();
        if ($data['residual_minor'] >= $data['cost_minor']) {
            throw ValidationException::withMessages(['residual_minor' => 'Nilai sisa harus lebih kecil dari harga perolehan.']);
        }
        if ($data['acquisition_mode'] === 'OPENING' && ! empty($data['funding_account_id'])) {
            throw ValidationException::withMessages(['funding_account_id' => 'Aset saldo awal dicatat lewat jurnal saldo awal.']);
        }

        return DB::transaction(function () use ($data, $actor): FixedAsset {
            $this->journals->assertOpenDate($data['acquired_on']);
            $funding = $data['acquisition_mode'] === 'OPENING' ? null : Account::query()->findOrFail($data['funding_account_id']);
            if ($funding && (! $funding->is_active || ! in_array($funding->code, $data['acquisition_mode'] === 'DEBT' ? ['2-1400'] : ['1-1100', '1-1200', '1-1300'], true))) {
                throw ValidationException::withMessages(['funding_account_id' => 'Pilih akun kas, bank, atau hutang yang sesuai.']);
            }
            $uuid = $data['uuid'] ?? (string) Str::uuid();
            $existing = FixedAsset::query()->where('uuid', $uuid)->first();
            if ($existing) {
                if ($existing->name !== $data['name'] || $existing->cost_minor !== (int) $data['cost_minor']) {
                    throw ValidationException::withMessages(['uuid' => 'Aset dengan kunci ini sudah dicatat berbeda.']);
                }

                return $existing;
            }
            $asset = FixedAsset::query()->create([
                ...$data, 'uuid' => $uuid, 'funding_account_id' => $funding?->id,
                'status' => 'ACTIVE', 'created_by' => $actor->id,
            ]);
            if ($funding) {
                $this->journals->post([
                    'entry_date' => $data['acquired_on'], 'description' => 'Perolehan aset '.$asset->name,
                    'source_type' => 'fixed_asset', 'source_id' => $asset->id, 'purpose' => 'ACQUISITION',
                    'posted_by' => $actor->id,
                ], [
                    $this->line('1-1800', (int) $asset->cost_minor, 0),
                    ['account_id' => $funding->id, 'debit_minor' => 0, 'credit_minor' => (int) $asset->cost_minor],
                ]);
            }
            $this->audit($actor, $asset, 'fixed_asset.acquired', ['mode' => $asset->acquisition_mode, 'cost_minor' => $asset->cost_minor]);

            return $asset;
        }, 3);
    }

    public function postMonthly(Carbon $month, ?User $actor = null): int
    {
        if ($actor) {
            Gate::forUser($actor)->authorize('post-fixed-asset-depreciation');
        }
        $date = $month->copy()->endOfMonth()->toDateString();
        DB::transaction(fn () => $this->journals->assertOpenDate($date));
        $start = $month->copy()->startOfMonth();
        $count = 0;
        FixedAsset::query()->where('status', 'ACTIVE')->whereDate('acquired_on', '<=', $date)
            ->orderBy('id')->chunkById(100, function ($assets) use ($date, $start, $actor, &$count): void {
                foreach ($assets as $asset) {
                    DB::transaction(function () use ($asset, $date, $start, $actor, &$count): void {
                        $locked = FixedAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
                        if ($locked->status !== 'ACTIVE') {
                            return;
                        }
                        $elapsed = ((int) $start->year - (int) $locked->acquired_on->year) * 12
                            + (int) $start->month - (int) $locked->acquired_on->month;
                        if ($elapsed < 0 || $elapsed >= $locked->useful_life_months) {
                            return;
                        }
                        $purpose = 'DEPRECIATION_'.$start->format('Ym');
                        if (JournalEntry::query()->where('source_type', 'fixed_asset_depreciation')
                            ->where('source_id', $locked->id)->where('purpose', $purpose)->exists()) {
                            return;
                        }
                        $basis = $locked->cost_minor - $locked->residual_minor;
                        $base = intdiv($basis, $locked->useful_life_months);
                        $amount = $elapsed === $locked->useful_life_months - 1
                            ? $basis - $base * ($locked->useful_life_months - 1) : $base;
                        if ($amount <= 0) {
                            return;
                        }
                        $this->journals->post([
                            'entry_date' => $date, 'description' => 'Penyusutan '.$locked->name.' '.$start->format('Y-m'),
                            'source_type' => 'fixed_asset_depreciation', 'source_id' => $locked->id,
                            'purpose' => $purpose, 'posted_by' => $actor?->id,
                        ], [$this->line('6-2000', $amount, 0), $this->line('1-1900', 0, $amount)]);
                        $count++;
                    }, 3);
                }
            });

        return $count;
    }

    public function dispose(FixedAsset $asset, string $date, int $proceeds, ?int $cashAccountId, User $actor): FixedAsset
    {
        Gate::forUser($actor)->authorize('record-fixed-assets');
        Validator::make(compact('date', 'proceeds'), [
            'date' => ['required', 'date_format:Y-m-d'], 'proceeds' => ['required', 'integer', 'min:0'],
        ])->validate();
        if ($proceeds > 0 && ! $cashAccountId) {
            throw ValidationException::withMessages(['cash_account_id' => 'Pilih akun kas penerimaan pelepasan.']);
        }

        return DB::transaction(function () use ($asset, $date, $proceeds, $cashAccountId, $actor): FixedAsset {
            $this->journals->assertOpenDate($date);
            $locked = FixedAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'DISPOSED') {
                if ($locked->disposed_on?->toDateString() === $date && $locked->disposal_proceeds_minor === $proceeds) {
                    return $locked;
                }
                throw ValidationException::withMessages(['asset' => 'Aset ini sudah dilepas dengan rincian berbeda.']);
            }
            if ($date < $locked->acquired_on->toDateString()) {
                throw ValidationException::withMessages(['date' => 'Tanggal pelepasan tidak boleh sebelum perolehan.']);
            }
            $cash = $proceeds > 0 ? Account::query()->findOrFail($cashAccountId) : null;
            if ($cash && (! $cash->is_active || ! in_array($cash->code, ['1-1100', '1-1200', '1-1300'], true))) {
                throw ValidationException::withMessages(['cash_account_id' => 'Pilih akun kas atau bank yang aktif.']);
            }
            $accumulated = (int) DB::table('journal_entries as e')->join('journal_lines as l', 'l.entry_id', '=', 'e.id')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')->where('e.source_type', 'fixed_asset_depreciation')
                ->where('e.source_id', $locked->id)->where('a.code', '1-1900')
                ->selectRaw('COALESCE(SUM(l.credit_minor - l.debit_minor), 0) AS amount')->value('amount');
            $book = $locked->cost_minor - $accumulated;
            $difference = $book - $proceeds;
            $lines = [$this->line('1-1800', 0, $locked->cost_minor)];
            if ($accumulated > 0) {
                $lines[] = $this->line('1-1900', $accumulated, 0);
            }
            if ($cash) {
                $lines[] = ['account_id' => $cash->id, 'debit_minor' => $proceeds, 'credit_minor' => 0];
            }
            if ($difference !== 0) {
                $lines[] = $this->line('6-2100', max(0, $difference), max(0, -$difference));
            }
            $this->journals->post([
                'entry_date' => $date, 'description' => 'Pelepasan aset '.$locked->name,
                'source_type' => 'fixed_asset_disposal', 'source_id' => $locked->id,
                'purpose' => 'DISPOSAL', 'posted_by' => $actor->id,
            ], $lines);
            $locked->update(['status' => 'DISPOSED', 'disposed_on' => $date,
                'disposal_proceeds_minor' => $proceeds, 'disposal_account_id' => $cash?->id]);
            $this->audit($actor, $locked, 'fixed_asset.disposed', ['book_minor' => $book, 'proceeds_minor' => $proceeds]);

            return $locked;
        }, 3);
    }

    private function line(string $code, int $debit, int $credit): array
    {
        return ['account_id' => Account::query()->where('code', $code)->firstOrFail()->id,
            'debit_minor' => $debit, 'credit_minor' => $credit];
    }

    private function audit(User $actor, FixedAsset $asset, string $action, array $after): void
    {
        AuditLog::query()->create(['actor_id' => $actor->id, 'actor_role' => $actor->role->value,
            'action' => $action, 'subject_type' => FixedAsset::class, 'subject_id' => $asset->id,
            'after_json' => $after, 'ip' => request()->ip()]);
    }
}
