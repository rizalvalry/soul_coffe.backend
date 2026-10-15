<?php

namespace App\Filament\Resources\EmployeeProfiles\Pages;

use App\Enums\Role;
use App\Filament\Resources\EmployeeProfiles\EmployeeProfileResource;
use App\Models\EmployeeProfile;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class EditEmployeeProfile extends EditRecord
{
    protected static string $resource = EmployeeProfileResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (Gate::denies('manage-payroll')) {
            unset(
                $data['base_salary_minor'], $data['npwp'], $data['ptkp_status'],
                $data['bpjs_tk_enrolled'], $data['bpjs_kes_enrolled'], $data['payroll_active'],
            );
        } else {
            /** @var EmployeeProfile $profile */
            $profile = $this->record;
            foreach (['base_salary_minor', 'npwp', 'ptkp_status',
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
            array_push($allowed, 'base_salary_minor', 'npwp', 'ptkp_status',
                'bpjs_tk_enrolled', 'bpjs_kes_enrolled', 'payroll_active');

            /** @var EmployeeProfile $profile */
            $profile = $this->record;
            if (array_key_exists('base_salary_minor', $data)
                && (string) $data['base_salary_minor'] === (string) $profile->base_salary_minor) {
                unset($data['base_salary_minor']);
            }
            if (array_key_exists('npwp', $data) && $data['npwp'] === $profile->npwp) {
                unset($data['npwp']);
            }
        }

        if ($this->record->user?->role !== Role::STAFF) {
            $data['default_cart_id'] = null;
        }

        return array_intersect_key($data, array_flip($allowed));
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless(EmployeeProfileResource::canEdit($record), 403);

        return DB::transaction(fn (): Model => parent::handleRecordUpdate($record, $data));
    }
}
