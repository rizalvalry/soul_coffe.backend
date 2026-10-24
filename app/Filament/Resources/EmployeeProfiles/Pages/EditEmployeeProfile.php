<?php

namespace App\Filament\Resources\EmployeeProfiles\Pages;

use App\Enums\Role;
use App\Filament\Resources\EmployeeProfiles\EmployeeProfileResource;
use App\Models\Account;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditEmployeeProfile extends EditRecord
{
    protected static string $resource = EmployeeProfileResource::class;

    private ?string $salaryEffectiveMonth = null;

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
            $month = now('Asia/Jakarta')->startOfMonth()->addMonth()->format('Y-m');
            $version = $profile->salaryVersionFor($month.'-01');
            foreach (['base_salary_minor', 'daily_rate_minor', 'salary_basis', 'npwp', 'ptkp_status',
                'bpjs_tk_enrolled', 'bpjs_kes_enrolled', 'payroll_active'] as $field) {
                $data[$field] = in_array($field, ['base_salary_minor', 'daily_rate_minor', 'salary_basis'], true)
                    ? $version?->getAttribute($field) : $profile->getAttribute($field);
            }
            $data['salary_effective_month'] = $month;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $allowed = [
            'division', 'position_title', 'employment_status', 'default_cart_id',
        ];
        if (Gate::allows('manage-payroll')) {
            $this->salaryEffectiveMonth = $data['salary_effective_month'] ?? null;
            array_push($allowed, 'base_salary_minor', 'daily_rate_minor', 'salary_basis', 'npwp', 'ptkp_status',
                'bpjs_tk_enrolled', 'bpjs_kes_enrolled', 'payroll_active');

            /** @var EmployeeProfile $profile */
            $profile = $this->record;
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

        return DB::transaction(function () use ($record, $data): Model {
            // Approval and role changes take this lock before reading payroll state.
            Account::query()->orderBy('id')->lockForUpdate()->first();
            $record = EmployeeProfile::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            if (Gate::allows('manage-payroll')) {
                $month = $this->salaryEffectiveMonth;
                if (! is_string($month) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) {
                    throw ValidationException::withMessages(['data.salary_effective_month' => 'Pilih bulan berlaku gaji dulu ya.']);
                }
                $date = $month.'-01';
                $effective = $record->salaryVersionFor($date);
                $values = [
                    'salary_basis' => $data['salary_basis'] ?? $effective?->salary_basis ?? 'bulanan',
                    'base_salary_minor' => array_key_exists('base_salary_minor', $data)
                        ? ($data['base_salary_minor'] === null || $data['base_salary_minor'] === '' ? null : (int) $data['base_salary_minor'])
                        : $effective?->base_salary_minor,
                    'daily_rate_minor' => array_key_exists('daily_rate_minor', $data)
                        ? ($data['daily_rate_minor'] === null || $data['daily_rate_minor'] === '' ? null : (int) $data['daily_rate_minor'])
                        : $effective?->daily_rate_minor,
                ];
                if ($values['salary_basis'] === 'harian' && $values['daily_rate_minor'] === null
                    && $values['base_salary_minor'] !== null) {
                    $days = max(1, (int) config('soul.attendance_working_days', 26));
                    $salary = $values['base_salary_minor'];
                    $values['daily_rate_minor'] = intdiv($salary, $days) + (int) (($salary % $days) * 2 >= $days);
                }
                $current = now('Asia/Jakarta')->startOfMonth()->format('Y-m');
                if ($month < $current) {
                    throw ValidationException::withMessages(['data.salary_effective_month' => 'Bulan yang sudah lewat nggak bisa diubah ya.']);
                }
                if ($month === $current && PayrollRun::query()
                    ->whereDate('period_start', $month.'-01')
                    ->whereIn('status', ['APPROVED', 'PAID'])->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['data.salary_effective_month' => 'Payroll bulan ini sudah disetujui, pilih bulan depan ya.']);
                }
                $oldValues = $effective ? [
                    'salary_basis' => $effective->salary_basis,
                    'base_salary_minor' => $effective->base_salary_minor,
                    'daily_rate_minor' => $effective->daily_rate_minor,
                ] : null;
                if ($values !== $oldValues) {
                    $user = $record->user()->lockForUpdate()->first();
                    if ($user?->role === Role::RIDER && $values['salary_basis'] === 'harian'
                        && ($month > $current || $effective?->salary_basis !== 'harian')) {
                        throw ValidationException::withMessages(['data.salary_basis' => 'Gaji Runner untuk bulan depan tetap bulanan ya.']);
                    }
                    $version = $record->saveSalaryVersion($date, $values);
                    $latest = $record->salaryVersions()->orderByDesc('effective_from')->first();
                    if ($latest?->id === $version->id) {
                        $record->mirrorSalaryVersion($version);
                    }
                }
            }

            return parent::handleRecordUpdate($record, array_diff_key($data, array_flip([
                'base_salary_minor', 'daily_rate_minor', 'salary_basis',
            ])));
        });
    }
}
