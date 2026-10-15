<?php

namespace App\Models;

use App\Casts\EncryptedInteger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

class EmployeeProfile extends Model
{
    public const EMPLOYMENT_STATUSES = ['tetap', 'kontrak', 'harian'];

    public const PTKP_STATUSES = ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'];

    protected $fillable = [
        'user_id',
        'division',
        'position_title',
        'employment_status',
        'base_salary_minor',
        'ptkp_status',
        'npwp',
        'bpjs_tk_enrolled',
        'bpjs_kes_enrolled',
        'payroll_active',
        'default_cart_id',
    ];

    protected $hidden = [
        'base_salary_minor', 'npwp', 'ptkp_status',
        'bpjs_tk_enrolled', 'bpjs_kes_enrolled', 'payroll_active',
    ];

    public function setAttribute($key, $value)
    {
        if (in_array($key, ['base_salary_minor', 'npwp', 'employment_status', 'ptkp_status'], true)
            && is_string($value) && trim($value) === '') {
            $value = null;
        }

        $allowed = match ($key) {
            'employment_status' => self::EMPLOYMENT_STATUSES,
            'ptkp_status' => self::PTKP_STATUSES,
            default => null,
        };
        if ($value !== null && $allowed !== null && ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Invalid {$key} value.");
        }

        return parent::setAttribute($key, $value);
    }

    protected function casts(): array
    {
        return [
            'base_salary_minor' => EncryptedInteger::class,
            'npwp' => 'encrypted',
            'bpjs_tk_enrolled' => 'boolean',
            'bpjs_kes_enrolled' => 'boolean',
            'payroll_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(fn (self $profile) => $profile->auditSensitiveChanges(true));
        static::updated(fn (self $profile) => $profile->auditSensitiveChanges(false));
    }

    private function auditSensitiveChanges(bool $created): void
    {
        $changed = array_values(array_filter(
            ['base_salary_minor', 'npwp', 'ptkp_status', 'bpjs_tk_enrolled', 'bpjs_kes_enrolled', 'payroll_active'],
            fn (string $field): bool => $created
                ? $this->getAttribute($field) !== null
                : array_key_exists($field, $this->getChanges()),
        ));

        if ($changed === []) {
            return;
        }

        /** @var User|null $actor */
        $actor = Auth::user();
        $before = [];
        $after = [];
        $detail = ['before' => [], 'after' => []];
        foreach ($changed as $field) {
            $before[$field] = $created || $this->getRawOriginal($field) === null ? null : '[redacted]';
            $after[$field] = $this->getAttribute($field) === null ? null : '[redacted]';
            $detail['before'][$field] = $created ? null : $this->getOriginal($field);
            $detail['after'][$field] = $this->getAttribute($field);
        }
        // The generic audit JSON remains safe for Administrator and Finance. Management can
        // reconstruct the old and new values through the payroll gate in AuditLog::displayChanges.
        $after['_payroll_ciphertext'] = Crypt::encryptString(json_encode($detail, JSON_THROW_ON_ERROR));

        AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role?->value,
            'action' => $created ? 'employee_payroll_created' : 'employee_payroll_updated',
            'subject_type' => self::class,
            'subject_id' => $this->getKey(),
            'before_json' => $created ? null : $before,
            'after_json' => $after,
            'ip' => request()?->ip(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function defaultCart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'default_cart_id');
    }
}
