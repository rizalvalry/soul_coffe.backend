<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class JournalLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['entry_id', 'account_id', 'debit_minor', 'credit_minor', 'cart_id', 'memo'];

    protected function casts(): array
    {
        return ['debit_minor' => 'integer', 'credit_minor' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw ValidationException::withMessages(['journal' => 'Baris jurnal tidak dapat diubah.']));
        static::deleting(fn (): never => throw ValidationException::withMessages(['journal' => 'Baris jurnal tidak dapat dihapus.']));
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
