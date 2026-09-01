<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
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
        ];
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
