<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Production extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'kitchen_id',
        'actor_id',
        'produced_at',
    ];

    protected function casts(): array
    {
        return [
            'produced_at' => 'datetime',
        ];
    }

    public function kitchen(): BelongsTo
    {
        return $this->belongsTo(CentralKitchen::class, 'kitchen_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionLine::class, 'production_id');
    }
}
