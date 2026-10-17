<?php

namespace App\Models;

use App\Casts\EncryptedInteger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollComponentEntry extends Model
{
    protected $fillable = ['user_id', 'component_type_id', 'period_start', 'request_key', 'amount_minor', 'note', 'created_by'];

    protected $hidden = ['amount_minor', 'request_key'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer', 'component_type_id' => 'integer', 'created_by' => 'integer',
            'amount_minor' => EncryptedInteger::class, 'period_start' => 'date',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PayrollComponentType::class, 'component_type_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
