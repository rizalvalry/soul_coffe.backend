<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class EmployeeAdvanceMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'advance_id', 'request_key', 'entry_date', 'kind', 'amount_minor', 'reason',
        'journal_entry_id', 'cash_account_id', 'payroll_run_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date', 'created_at' => 'datetime', 'amount_minor' => 'integer',
            'advance_id' => 'integer', 'cash_account_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw ValidationException::withMessages(['movement' => 'Mutasi kasbon tidak bisa diubah. Catat koreksi baru ya.']));
        static::deleting(fn (): never => throw ValidationException::withMessages(['movement' => 'Mutasi kasbon tidak bisa dihapus. Catat koreksi baru ya.']));
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'advance_id');
    }
}
