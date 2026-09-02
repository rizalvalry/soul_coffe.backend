<?php

namespace App\Models;

use App\Enums\Role;
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
        return $this->is_active && $this->role === Role::ADMINISTRATOR;
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
