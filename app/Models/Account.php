<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Account extends Model
{
    protected $fillable = ['code', 'name', 'type', 'normal_balance', 'parent_id', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_system' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $account): void {
            if ($account->is_system && $account->isDirty()) {
                throw ValidationException::withMessages(['account' => 'Akun sistem tidak dapat diubah.']);
            }
        });

        static::deleting(fn (): never => throw ValidationException::withMessages(['account' => 'Akun tidak dapat dihapus. Nonaktifkan akun non-sistem.']));
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }
}
