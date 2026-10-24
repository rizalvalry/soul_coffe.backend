<?php

namespace App\Models;

use App\Casts\EncryptedInteger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryVersion extends Model
{
    protected $fillable = [
        'employee_profile_id', 'effective_from', 'salary_basis', 'base_salary_minor', 'daily_rate_minor',
    ];

    protected $hidden = ['salary_basis', 'base_salary_minor', 'daily_rate_minor'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'base_salary_minor' => EncryptedInteger::class,
            'daily_rate_minor' => EncryptedInteger::class,
        ];
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }
}
