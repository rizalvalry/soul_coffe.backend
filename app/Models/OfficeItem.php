<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class OfficeItem extends Model
{
    protected $fillable = ['name', 'unit', 'default_category_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::deleting(function (self $item): void {
            if ($item->transactions()->exists()) {
                throw ValidationException::withMessages(['office_item' => 'Barang kantor yang sudah dipakai tidak dapat dihapus.']);
            }
        });
    }

    public function defaultCategory(): BelongsTo
    {
        return $this->belongsTo(CashCategory::class, 'default_category_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashTransaction::class);
    }
}
