<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class JournalEntry extends Model
{
    public $timestamps = false;

    protected $fillable = ['number', 'entry_date', 'period_id', 'description', 'source_type', 'source_id', 'purpose', 'status', 'reverses_entry_id', 'posted_by'];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'source_id' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw ValidationException::withMessages(['journal' => 'Jurnal yang diposting tidak dapat diubah.']));
        static::deleting(fn (): never => throw ValidationException::withMessages(['journal' => 'Jurnal yang diposting tidak dapat dihapus.']));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'entry_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
