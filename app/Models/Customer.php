<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $fillable = [
        'google_sub', 'email', 'email_verified', 'name', 'avatar_url',
        'status', 'privacy_accepted_at', 'last_login_at',
    ];

    protected $hidden = ['google_sub'];

    protected function casts(): array
    {
        return [
            'email_verified' => 'boolean',
            'privacy_accepted_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function apiProfile(): array
    {
        return $this->only([
            'id', 'email', 'email_verified', 'name', 'avatar_url', 'status',
            'privacy_accepted_at', 'last_login_at', 'created_at',
        ]);
    }
}
