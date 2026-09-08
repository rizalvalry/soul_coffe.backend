<?php

namespace App\Models;

use App\Enums\PanelModule;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row: what a role may do on one panel menu. See App\Services\Access\PermissionMatrix for
 * the actual authorisation logic — this model is deliberately dumb, so there is exactly one
 * place that decides what an ability check means.
 */
class RolePermission extends Model
{
    protected $fillable = [
        'role',
        'module',
        'abilities',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'module' => PanelModule::class,
            'abilities' => 'array',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
