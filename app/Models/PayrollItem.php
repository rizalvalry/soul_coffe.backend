<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    protected $fillable = ['run_id', 'user_id', 'snapshot', 'slip_path'];

    protected $hidden = ['snapshot', 'slip_path'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
