<?php

namespace App\Filament\Resources\EmployeeProfiles\Pages;

use App\Enums\Role;
use App\Filament\Resources\EmployeeProfiles\EmployeeProfileResource;
use App\Models\EmployeeProfile;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditEmployeeProfile extends EditRecord
{
    protected static string $resource = EmployeeProfileResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (Gate::denies('manage-payroll')) {
            unset(
                $data['base_salary_minor'], $data['daily_rate_minor'], $data['salary_basis'], $data['npwp'], $data['ptkp_status'],
                $data['bpjs_tk_enrolled'], $data['bpjs_kes_enrolled'], $data['payroll_active'],
            );
        } else {
            /** @var EmployeeProfile $profile */
            $profile = $this->record;
            foreach (['base_salary_minor', 'daily_rate_minor', 'salary_basis', 'npwp', 'ptkp_status',
                'bpjs_tk_enrolled', 'bpjs_kes_enrolled', 'payroll_active'] as $field) {
                $data[$field] = $profile->getAttribute($field);
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $allowed = [
            'division', 'position_title', 'employment_status', 'default_cart_id',
        ];
        if (Gate::allows('manage-payroll')) {
            array_push($allowed, 'base_salary_minor', 'daily_rate_minor', 'salary_basis', 'npwp', 'ptkp_status',
                'bpjs_tk_enrolled', 'bpjs_kes_enrolled', 'payroll_active');

            /** @var EmployeeProfile $profile */
            $profile = $this->record;
            if (array_key_exists('base_salary_minor', $data)
                && (string) $data['base_salary_minor'] === (string) $profile->base_salary_minor) {
                unset($data['base_salary_minor']);
            }
            if (array_key_exists('daily_rate_minor', $data)
                && (string) $data['daily_rate_minor'] === (string) $profile->daily_rate_minor) {
                unset($data['daily_rate_minor']);
            }
            if (array_key_exists('npwp', $data) && $data['npwp'] === $profile->npwp) {
                unset($data['npwp']);
            }
        }

        if ($this->record->user?->role !== Role::STAFF) {
            $data['default_cart_id'] = null;
        }

        if (Gate::allows('manage-payroll')) {
            if ($this->record->user?->role === Role::RIDER && ($data['salary_basis'] ?? 'bulanan') === 'harian') {
                throw ValidationException::withMessages(['data.salary_basis' => 'Gaji Runner tetap bulanan ya.']);
            }
            if (($data['salary_basis'] ?? $this->record->salary_basis) === 'harian'
                && ! isset($data['daily_rate_minor']) && $this->record->daily_rate_minor === null) {
                $salary = (int) ($data['base_salary_minor'] ?? $this->record->base_salary_minor);
                $days = max(1, (int) config('soul.attendance_working_days', 26));
                $data['daily_rate_minor'] = intdiv($salary, $days) + (int) (($salary % $days) * 2 >= $days);
            }
        }

        return array_intersect_key($data, array_flip($allowed));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(EmployeeProfileResource::canEdit($record), 403);

        return DB::transaction(fn (): Model => parent::handleRecordUpdate($record, $data));
    }
}
