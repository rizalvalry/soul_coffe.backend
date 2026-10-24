<?php

namespace App\Models;

use App\Casts\EncryptedInteger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EmployeeProfile extends Model
{
    private ?string $salaryEffectiveFrom = null;

    private bool $skipSalaryVersionWrite = false;

    public const EMPLOYMENT_STATUSES = ['tetap', 'kontrak', 'harian'];

    public const PTKP_STATUSES = ['TK/0', 'TK/1', 'TK/2', 'TK/3', 'K/0', 'K/1', 'K/2', 'K/3'];

    protected $fillable = [
        'user_id',
        'division',
        'position_title',
        'employment_status',
        'base_salary_minor',
        'salary_basis',
        'daily_rate_minor',
        'ptkp_status',
        'npwp',
        'bpjs_tk_enrolled',
        'bpjs_kes_enrolled',
        'payroll_active',
        'default_cart_id',
    ];

    protected $hidden = [
        'base_salary_minor', 'daily_rate_minor', 'salary_basis', 'npwp', 'ptkp_status',
        'bpjs_tk_enrolled', 'bpjs_kes_enrolled', 'payroll_active',
    ];

    public function setAttribute($key, $value)
    {
        if (in_array($key, ['base_salary_minor', 'daily_rate_minor', 'npwp', 'employment_status', 'ptkp_status'], true)
            && is_string($value) && trim($value) === '') {
            $value = null;
        }

        $allowed = match ($key) {
            'employment_status' => self::EMPLOYMENT_STATUSES,
            'ptkp_status' => self::PTKP_STATUSES,
            'salary_basis' => ['bulanan', 'harian'],
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
            'daily_rate_minor' => EncryptedInteger::class,
            'npwp' => 'encrypted',
            'bpjs_tk_enrolled' => 'boolean',
            'bpjs_kes_enrolled' => 'boolean',
            'payroll_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $profile): void {
            $profile->recordSalaryVersion('2026-10-01');
            $profile->auditSensitiveChanges(true);
        });
        static::updated(function (self $profile): void {
            if (! $profile->skipSalaryVersionWrite
                && array_intersect(['base_salary_minor', 'daily_rate_minor', 'salary_basis'], array_keys($profile->getChanges()))) {
                $profile->recordSalaryVersion($profile->salaryEffectiveFrom
                    ?? now('Asia/Jakarta')->startOfMonth()->addMonth()->format('Y-m-d'));
            }
            $profile->auditSensitiveChanges(false);
            $profile->salaryEffectiveFrom = null;
        });
    }

    public function save(array $options = []): bool
    {
        return DB::transaction(fn (): bool => parent::save($options));
    }

    public function setSalaryEffectiveFrom(string $date): void
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $date)) {
            throw new InvalidArgumentException('Bulan berlaku gaji belum valid ya.');
        }
        $this->salaryEffectiveFrom = $date;
    }

    public function salaryVersionFor(string $date): ?EmployeeSalaryVersion
    {
        return $this->salaryVersions()->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->first()
            ?? $this->salaryVersions()->orderBy('effective_from')->first();
    }

    private function recordSalaryVersion(string $date): void
    {
        $this->saveSalaryVersion($date, [
            'salary_basis' => $this->salary_basis ?? 'bulanan',
            'base_salary_minor' => $this->base_salary_minor,
            'daily_rate_minor' => $this->daily_rate_minor,
        ]);
    }

    /** @param array{salary_basis: string, base_salary_minor: ?int, daily_rate_minor: ?int} $values */
    public function saveSalaryVersion(string $date, array $values): EmployeeSalaryVersion
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $date)) {
            throw new InvalidArgumentException('Bulan berlaku gaji belum valid ya.');
        }
        $old = $this->salaryVersions()->whereDate('effective_from', $date)->first();
        $prior = $old ?? $this->salaryVersionFor($date);
        $before = $prior ? [
            'salary_basis' => $prior->salary_basis,
            'base_salary_minor' => $prior->base_salary_minor,
            'daily_rate_minor' => $prior->daily_rate_minor,
        ] : null;
        if ($old && $before === $values) {
            return $old;
        }
        $version = $this->salaryVersions()->updateOrCreate(['effective_from' => $date], $values);
        /** @var User|null $actor */
        $actor = Auth::user();
        AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role?->value,
            'action' => 'employee_salary_version_saved',
            'subject_type' => EmployeeSalaryVersion::class,
            'subject_id' => $version->id,
            'before_json' => $prior ? [
                'effective_from' => $date, 'salary_basis' => '[redacted]',
                'base_salary_minor' => '[redacted]', 'daily_rate_minor' => '[redacted]',
            ] : null,
            'after_json' => [
                'effective_from' => $date, 'salary_basis' => '[redacted]',
                'base_salary_minor' => '[redacted]', 'daily_rate_minor' => '[redacted]',
                '_payroll_ciphertext' => Crypt::encryptString(json_encode([
                    'before' => $before ?? [], 'after' => $values,
                ], JSON_THROW_ON_ERROR)),
            ],
            'ip' => request()?->ip(),
        ]);

        return $version;
    }

    public function mirrorSalaryVersion(EmployeeSalaryVersion $version): void
    {
        $values = [
            'salary_basis' => $version->salary_basis,
            'base_salary_minor' => $version->base_salary_minor,
            'daily_rate_minor' => $version->daily_rate_minor,
        ];
        if ($this->salary_basis === $values['salary_basis']
            && $this->base_salary_minor === $values['base_salary_minor']
            && $this->daily_rate_minor === $values['daily_rate_minor']) {
            return;
        }
        $this->skipSalaryVersionWrite = true;
        $this->setSalaryEffectiveFrom($version->effective_from->format('Y-m-d'));
        try {
            $this->fill($values)->save();
        } finally {
            $this->skipSalaryVersionWrite = false;
            $this->salaryEffectiveFrom = null;
        }
    }

    private function auditSensitiveChanges(bool $created): void
    {
        $changed = array_values(array_filter(
            ['base_salary_minor', 'daily_rate_minor', 'salary_basis', 'npwp', 'ptkp_status', 'bpjs_tk_enrolled', 'bpjs_kes_enrolled', 'payroll_active'],
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
        if (array_intersect($changed, ['base_salary_minor', 'daily_rate_minor', 'salary_basis'])) {
            $before['salary_effective_from'] = null;
            $after['salary_effective_from'] = $this->salaryEffectiveFrom
                ?? ($created ? '2026-10-01' : now('Asia/Jakarta')->startOfMonth()->addMonth()->format('Y-m-d'));
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

    public function salaryVersions(): HasMany
    {
        return $this->hasMany(EmployeeSalaryVersion::class);
    }
}
