<?php

namespace App\Models;

use App\Enums\Role;
use App\Services\Access\PermissionMatrix;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
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
        // Employment profile, read by the monthly absensi sheet. These live here rather than in
        // a separate roster because they describe the same person this row already describes —
        // see the move_employment_profile_onto_users migration.
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'login_pin_locked_until' => 'datetime',
            'monthly_libur_quota' => 'integer',
            'birth_date' => 'date',
            'joined_at' => 'date',
        ];
    }

    /**
     * Who may open the admin panel.
     *
     * The panel is a second front door onto the same data the API guards by role, so it applies
     * the same two conditions the API applies at login: the account must be active, and only
     * ADMINISTRATOR may enter. Without this method Filament lets every authenticated user in --
     * which would hand a STAFF account the user-management screen.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // ADMINISTRATOR runs the panel; CONTENT_CREATOR is let in for the news feed alone, which
        // is hardcoded rather than matrix-driven (see NewsPostResource).
        if (in_array($this->role, [Role::ADMINISTRATOR, Role::CONTENT_CREATOR], true)) {
            return true;
        }

        // Every other role gets in only once the access matrix has actually granted it a menu —
        // FINANCE ships with the absensi module, so it reaches the panel and sees nothing else.
        // Deriving the door from the matrix is what stops the two from disagreeing: a granted
        // module that cannot be reached, or a role in the building with nowhere to go.
        //
        // Panel access is still not the authorisation. Each resource re-checks the matrix in its
        // own `canViewAny()`, so being through the door never implies sight of a refill, a price,
        // or a settlement.
        return PermissionMatrix::hasAnyModule($this->role);
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
