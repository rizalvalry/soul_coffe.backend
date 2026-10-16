<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashCategory extends Model
{
    protected $fillable = ['name', 'direction', 'account_id', 'is_active'];

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
        static::saving(function (self $category): void {
            if ($category->exists && ! $category->isDirty(['direction', 'account_id'])) {
                return;
            }
            $type = Account::query()->whereKey($category->account_id)->where('is_active', true)->value('type');
            if (! in_array($category->direction, ['IN', 'OUT'], true)
                || ($category->direction === 'IN' && ! in_array($type, ['REVENUE', 'EQUITY'], true))
                || ($category->direction === 'OUT' && ! in_array($type, ['EXPENSE', 'ASSET'], true))) {
                throw ValidationException::withMessages(['account_id' => 'Akun kategori tidak cocok dengan arah transaksi.']);
            }
        });
        static::deleting(function (self $category): void {
            if ($category->transactions()->exists() || $category->officeItems()->exists()) {
                throw ValidationException::withMessages(['category' => 'Kategori yang sudah dipakai tidak dapat dihapus.']);
            }
        });
        static::updating(function (self $category): void {
            if ($category->isDirty(['name', 'direction', 'account_id']) && $category->transactions()->exists()) {
                throw ValidationException::withMessages(['category' => 'Nama, arah, dan akun kategori yang sudah dipakai tidak dapat diubah.']);
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

    public function officeItems(): HasMany
    {
        return $this->hasMany(OfficeItem::class, 'default_category_id');
    }
}
