<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class TaxSettingVersion extends Model
{
    protected $fillable = [
        'tax_type', 'is_active', 'rate_bps', 'price_mode', 'scheme', 'effective_from',
        'effective_until', 'legal_note', 'monthly_threshold_minor', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean', 'rate_bps' => 'integer', 'effective_from' => 'date',
            'effective_until' => 'date', 'monthly_threshold_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw ValidationException::withMessages(['tax' => 'Riwayat tarif pajak tidak bisa diubah.']));
        static::deleting(fn (): never => throw ValidationException::withMessages(['tax' => 'Riwayat tarif pajak tidak bisa dihapus.']));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
