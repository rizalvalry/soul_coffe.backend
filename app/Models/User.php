<?php

namespace App\Models;

use App\Enums\Role;
use App\Services\Access\PermissionMatrix;
use App\Services\EventPublisher;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone_e164',
        'password',
        'role',
        'kitchen_id',
        'pin_hash',
        'login_pin_hash',
        'is_active',
        'roster_onboarding',
        // Absensi fields remain on the user; payroll-specific fields live in employee_profiles.
        // Both refer to this one identity, rather than creating a second roster of people.
        'nik',
        'uniform_size',
        'monthly_libur_quota',
        // Biodata. All optional by design — see the add_employee_profile_to_users migration for
        // why nothing here may ever block saving a person.
        'email',
        'national_id',
        'birth_date',
        'birth_place',
        'gender',
        'marital_status',
        'address',
        'joined_at',
        'emergency_contact_name',
        'emergency_contact_phone',
        'bank_name',
        'bank_account_number',
        'bank_account_holder',
        'notes',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pin_hash',
        'login_pin_hash',
        'national_id',
        'bank_account_number',
        'roster_onboarding',
    ];

    public function setAttribute($key, $value)
    {
        if (in_array($key, ['national_id', 'bank_account_number'], true) && is_string($value) && trim($value) === '') {
            $value = null;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'roster_onboarding' => 'boolean',
            'login_pin_locked_until' => 'datetime',
            'monthly_libur_quota' => 'integer',
            'birth_date' => 'date',
            'joined_at' => 'date',
            'national_id' => 'encrypted',
            'bank_account_number' => 'encrypted',
        ];
    }

    /** Mask all employee financial/identity changes before they reach the audit trail. */
    protected static function booted(): void
    {
        static::created(fn (self $user) => $user->auditSensitiveChanges(true));
        static::saving(function (self $user): void {
            if ($user->is_super_admin && $user->role !== Role::ADMINISTRATOR) {
                throw ValidationException::withMessages(['role' => 'Super Administrator harus punya role Administrator.']);
            }

            $wasSuper = (bool) $user->getRawOriginal('is_super_admin');
            $actor = Auth::user();
            if ($user->exists && $user->isDirty('role') && $actor !== null
                && ! PermissionMatrix::canManagePermissions($actor)) {
                abort(403);
            }
            if ($user->exists && ($wasSuper || $user->isDirty('is_super_admin')) && $actor !== null
                && ! $actor->is_super_admin) {
                abort(403);
            }

            if ($user->exists && $wasSuper && ($user->isDirty('is_super_admin') || $user->isDirty('is_active'))
                && (! $user->is_super_admin || ! $user->is_active)
                && self::query()->where('role', Role::ADMINISTRATOR->value)
                    ->where('is_super_admin', true)->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['is_super_admin' => 'Super Administrator terakhir harus tetap aktif ya.']);
            }
        });
        static::updated(function (self $user): void {
            $user->auditSensitiveChanges(false);
            if ($user->wasChanged('role') || $user->wasChanged('is_active') || $user->wasChanged('is_super_admin')) {
                app(EventPublisher::class)->publish('AccessChanged', 'Hak akses diperbarui',
                    'Menu kamu sudah diperbarui ya.', ['user.'.$user->id]);
            }
            if (! $user->wasChanged('is_super_admin') && ! ($user->wasChanged('is_active')
                && ((bool) $user->getRawOriginal('is_super_admin') || $user->is_super_admin))) {
                return;
            }
            $actor = Auth::user();
            AuditLog::query()->create([
                'actor_id' => $actor?->id,
                'actor_role' => $actor?->role?->value ?? 'SYSTEM',
                'action' => 'super_admin.status_changed',
                'subject_type' => self::class,
                'subject_id' => $user->id,
                'before_json' => [
                    'is_super_admin' => (bool) $user->getRawOriginal('is_super_admin'),
                    'is_active' => (bool) $user->getRawOriginal('is_active'),
                ],
                'after_json' => ['is_super_admin' => $user->is_super_admin, 'is_active' => $user->is_active],
                'ip' => request()?->ip(),
            ]);
        });
    }

    public static function maskSensitiveValue(?string $value): ?string
    {
        return $value === null || $value === '' ? null : '****'.(mb_strlen($value) > 4 ? mb_substr($value, -4) : '');
    }

    private function auditSensitiveChanges(bool $created): void
    {
        $before = [];
        $after = [];

        foreach (['national_id', 'bank_name', 'bank_account_number', 'bank_account_holder'] as $field) {
            if (! $created && ! array_key_exists($field, $this->getChanges())) {
                continue;
            }

            $value = $this->getAttribute($field);
            if ($created && $value === null) {
                continue;
            }

            $before[$field] = $created ? null : self::maskSensitiveValue($this->getOriginal($field));
            $after[$field] = self::maskSensitiveValue($value);
        }

        if ($after === []) {
            return;
        }

        /** @var self|null $actor */
        $actor = Auth::user();
        AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'actor_role' => $actor?->role?->value,
            'action' => $created ? 'employee_sensitive_created' : 'employee_sensitive_updated',
            'subject_type' => self::class,
            'subject_id' => $this->getKey(),
            'before_json' => $before,
            'after_json' => $after,
            'ip' => request()?->ip(),
        ]);
    }

    /**
     * Who may open the admin panel.
     *
     * The panel is a second front door onto the same data the API guards. Only active accounts
     * with a CMS menu grant may enter; resource and page checks still guard the data itself.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // The Super Administrator always has panel access.
        if ($this->is_super_admin) {
            return true;
        }

        // Every other role gets in only when its effective grants open a CMS menu. Mobile-only
        // grants and action abilities behind other pages do not open the panel by themselves.
        //
        // Panel access is still not the authorisation. Each resource re-checks the matrix in its
        // own `canViewAny()`, so being through the door never implies sight of a refill, a price,
        // or a settlement.
        return PermissionMatrix::hasAnyAccess($this);
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * The kitchen this user (a BARISTA) is scoped to. Null for every other role.
     */
    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(CentralKitchen::class, 'kitchen_id');
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(StaffAssignment::class, 'user_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(UserPermission::class);
    }

    public function locationSetups(): HasMany
    {
        return $this->hasMany(RiderLocationSetup::class, 'rider_id');
    }

    public function employeeProfile(): HasOne
    {
        return $this->hasOne(EmployeeProfile::class);
    }

    public function refillRequestsAsStaff(): HasMany
    {
        return $this->hasMany(RefillRequest::class, 'staff_id');
    }

    public function refillRequestsAsFinance(): HasMany
    {
        return $this->hasMany(RefillRequest::class, 'finance_id');
    }

    public function refillRequestsAsBarista(): HasMany
    {
        return $this->hasMany(RefillRequest::class, 'barista_id');
    }

    public function refillRequestsAsRider(): HasMany
    {
        return $this->hasMany(RefillRequest::class, 'rider_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class, 'staff_id');
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class, 'user_id');
    }
}
