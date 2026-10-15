<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use JsonException;

/**
 * R8 — every state transition writes an audit row here. Append-only.
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_log';

    const UPDATED_AT = null;

    protected $fillable = [
        'actor_id',
        'actor_role',
        'action',
        'subject_type',
        'subject_id',
        'before_json',
        'after_json',
        'ip',
        'device_id',
    ];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Payroll values are stored encrypted and only reconstructed for Management. */
    public function displayChanges(string $side): array
    {
        if (! in_array($side, ['before', 'after'], true)) {
            throw new InvalidArgumentException('Invalid audit side.');
        }

        $values = $side === 'before' ? ($this->before_json ?? []) : ($this->after_json ?? []);
        unset($values['_payroll_ciphertext']);

        if ($this->subject_type !== EmployeeProfile::class || ! Gate::allows('manage-payroll')) {
            return $values;
        }

        $ciphertext = ($this->after_json ?? [])['_payroll_ciphertext'] ?? null;
        if (! is_string($ciphertext)) {
            return $values;
        }

        try {
            $detail = json_decode(Crypt::decryptString($ciphertext), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return [...$values, '_detail' => 'Detail payroll tidak dapat dibuka.'];
        }

        return array_replace($values, $detail[$side] ?? []);
    }
}
