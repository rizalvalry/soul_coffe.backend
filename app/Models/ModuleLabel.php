<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One renamed menu or navigation group. See the migration for why the key and the label are
 * deliberately different things.
 */
class ModuleLabel extends Model
{
    public const SCOPE_MODULE = 'module';

    public const SCOPE_GROUP = 'group';

    protected $fillable = [
        'scope',
        'module',
        'label',
        'updated_by',
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
