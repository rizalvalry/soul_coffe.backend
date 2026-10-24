<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceDailyTarget extends Model
{
    protected $fillable = ['operating_date', 'company_cups', 'rider_cups', 'revision', 'updated_by'];

    protected function casts(): array
    {
        return [
            'operating_date' => 'date',
            'company_cups' => 'integer',
            'rider_cups' => 'integer',
            'revision' => 'integer',
        ];
    }
}
