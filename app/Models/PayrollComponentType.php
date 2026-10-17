<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollComponentType extends Model
{
    protected $fillable = ['code', 'name', 'kind', 'taxable', 'effective_from', 'source_note'];

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'effective_from' => 'date'];
    }
}
