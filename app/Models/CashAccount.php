<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashAccount extends Model
{
    protected $fillable = ['name', 'account_id', 'is_active'];

    public function save(array $options = []): bool
    {
        if (! $this->exists) {
            return parent::save($options);
        }

        return DB::transaction(function () use ($options): bool {
            self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return DB::transaction(function (): ?bool {
            self::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            return parent::delete();
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            if ($account->exists && ! $account->isDirty('account_id')) {
                return;
            }
            if (Account::query()->whereKey($account->account_id)->where('type', 'ASSET')->where('is_active', true)->doesntExist()) {
                throw ValidationException::withMessages(['account_id' => 'Akun kas harus memakai akun aset yang aktif.']);
            }
        });
        static::deleting(function (self $account): void {
            if ($account->hasLedgerActivity()) {
                throw ValidationException::withMessages(['cash_account' => 'Akun kas yang sudah dipakai tidak dapat dihapus.']);
            }
        });
        static::updating(function (self $account): void {
            if ($account->isDirty(['name', 'account_id']) && $account->hasLedgerActivity()) {
                throw ValidationException::withMessages(['cash_account' => 'Nama dan akun buku besar yang sudah dipakai tidak dapat diubah.']);
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }

    private function hasLedgerActivity(): bool
    {
        return $this->transactions()->exists()
            || DB::table('journal_lines')->where('account_id', $this->getOriginal('account_id'))->exists();
    }

    public function balanceMinor(): int
    {
        return (int) DB::table('journal_lines')
            ->where('account_id', $this->account_id)
            ->selectRaw('COALESCE(SUM(CAST(debit_minor AS SIGNED) - CAST(credit_minor AS SIGNED)), 0) AS balance')
            ->value('balance');
    }
}
