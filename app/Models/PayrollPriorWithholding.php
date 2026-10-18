<?php

namespace App\Models;

use App\Casts\EncryptedInteger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPriorWithholding extends Model
{
    protected $fillable = ['user_id', 'year', 'amount_minor', 'gross_minor', 'jht_jp_minor', 'months', 'reason', 'created_by'];

    protected $hidden = ['amount_minor', 'gross_minor', 'jht_jp_minor', 'months'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'amount_minor' => EncryptedInteger::class,
            'gross_minor' => EncryptedInteger::class,
            'jht_jp_minor' => EncryptedInteger::class,
            'months' => 'encrypted:array',
            'year' => 'integer',
        ];
    }
}
