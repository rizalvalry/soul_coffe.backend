<?php

namespace App\Models;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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

    protected static function booted(): void
    {
        static::updating(fn (): never => throw ValidationException::withMessages(['audit' => 'Audit yang sudah dicatat tidak bisa diubah.']));
        static::deleting(fn (): never => throw ValidationException::withMessages(['audit' => 'Audit yang sudah dicatat tidak bisa dihapus.']));
    }

    public static function recordAction(User $actor, string $action, Model $subject, ?array $before = null, ?array $after = null): self
    {
        return self::query()->create([
            'actor_id' => $actor->id, 'actor_role' => $actor->role->value,
            'action' => $action, 'subject_type' => $subject::class, 'subject_id' => $subject->id,
            'before_json' => $before, 'after_json' => $after,
            'ip' => request()->ip(), 'device_id' => request()->header('X-Device-Id'),
        ]);
    }

    public static function labelForAction(string $action): string
    {
        return $action === 'stock_ledger.negative_balance' ? 'Stok minus tercatat' : $action;
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

        if ($this->subject_type === RiderClosing::class
            && ! PermissionMatrix::can(Auth::user(), PanelModule::CLOSING_LOCATION_EVIDENCE, 'view')) {
            unset($values['gps_lat'], $values['gps_lng'], $values['gps_unavailable'],
                $values['closing_basis'], $values['closing_distance_m'], $values['outside_closing_point']);
        }

        $canReadAdvance = $this->subject_type === EmployeeAdvance::class
            && PermissionMatrix::can(Auth::user(), PanelModule::EMPLOYEE_ADVANCES, 'view');
        $canReadDetails = $this->subject_type === EmployeeAdvance::class
            ? $canReadAdvance : Gate::allows('manage-payroll');
        if (! in_array($this->subject_type, [EmployeeProfile::class, PayrollComponentEntry::class,
            PayrollRun::class, EmployeeAdvance::class], true)
            || ! $canReadDetails) {
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
