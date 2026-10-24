<?php

namespace App\Filament\Resources\EmployeeProfiles\Schemas;

use App\Enums\Role;
use App\Models\EmployeeProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

class EmployeeProfileForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Profil karyawan')
                ->columns(2)
                ->schema([
                    TextInput::make('division')->label('Divisi')->maxLength(255),
                    TextInput::make('position_title')->label('Jabatan')->maxLength(255),
                    Select::make('employment_status')
                        ->label('Status kerja')
                        ->options(['tetap' => 'Tetap', 'kontrak' => 'Kontrak', 'harian' => 'Harian']),
                    Select::make('default_cart_id')
                        ->label('Coffee Bike asal')
                        ->relationship('defaultCart', 'code')
                        ->searchable()
                        ->visible(fn (?EmployeeProfile $record): bool => $record?->user?->role === Role::STAFF)
                        ->helperText('Coffee Bike asal buat referensi. Coffee Bike yang dipakai Rider hari ini mengikuti Add Stock dari Produksi.'),
                ]),
            Section::make('Data payroll terbatas')
                ->visible(fn (): bool => Gate::allows('manage-payroll'))
                ->columns(2)
                ->schema([
                    Select::make('ptkp_status')
                        ->label('Status PTKP')
                        ->options(array_combine(EmployeeProfile::PTKP_STATUSES, EmployeeProfile::PTKP_STATUSES))
                        ->dehydrated(fn (): bool => Gate::allows('manage-payroll')),
                    Toggle::make('bpjs_tk_enrolled')
                        ->label('Peserta BPJS Ketenagakerjaan')
                        ->dehydrated(fn (): bool => Gate::allows('manage-payroll')),
                    Toggle::make('bpjs_kes_enrolled')
                        ->label('Peserta BPJS Kesehatan')
                        ->dehydrated(fn (): bool => Gate::allows('manage-payroll')),
                    Toggle::make('payroll_active')
                        ->label('Masuk payroll')
                        ->default(false)
                        ->dehydrated(fn (): bool => Gate::allows('manage-payroll')),
                    TextInput::make('base_salary_minor')
                        ->label('Gaji pokok (rupiah)')
                        ->numeric()
                        ->minValue(0)
                        ->rule('integer')
                        ->dehydrated(fn (): bool => Gate::allows('manage-payroll')),
                    Select::make('salary_basis')
                        ->label('Dasar gaji')
                        ->options(['bulanan' => 'Bulanan', 'harian' => 'Harian'])
                        ->default('bulanan')
                        ->dehydrated(fn (): bool => Gate::allows('manage-payroll')),
                    TextInput::make('salary_effective_month')
                        ->label('Berlaku mulai bulan')
                        ->type('month')
                        ->default(fn (): string => now('Asia/Jakarta')->startOfMonth()->addMonth()->format('Y-m'))
                        ->helperText('Bawaan bulan depan. Bulan ini bisa dipilih sebelum payroll disetujui.')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set, ?EmployeeProfile $record): void {
                            if (! $record || ! is_string($state)
                                || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $state)) {
                                return;
                            }
                            $version = $record->salaryVersionFor($state.'-01');
                            $set('salary_basis', $version?->salary_basis ?? 'bulanan');
                            $set('base_salary_minor', $version?->base_salary_minor);
                            $set('daily_rate_minor', $version?->daily_rate_minor);
                        })
                        ->dehydrated(fn (): bool => Gate::allows('manage-payroll')),
                    TextInput::make('daily_rate_minor')
                        ->label('Tarif harian (rupiah)')
                        ->helperText('Kosongkan untuk pakai gaji bulanan dibagi jumlah hari kerja di konfigurasi absensi.')
                        ->numeric()->minValue(0)->rule('integer')
                        ->visible(fn (?EmployeeProfile $record): bool => $record?->user?->role !== Role::RIDER)
                        ->dehydrated(fn (): bool => Gate::allows('manage-payroll')),
                    TextInput::make('npwp')
                        ->label('NPWP')
                        ->maxLength(32)
                        ->dehydrated(fn (): bool => Gate::allows('manage-payroll')),
                ]),
        ]);
    }
}
