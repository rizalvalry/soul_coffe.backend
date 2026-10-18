<?php

namespace App\Filament\Resources\EmployeeProfiles\Schemas;

use App\Enums\Role;
use App\Models\EmployeeProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                        ->label('Gerobak asal')
                        ->relationship('defaultCart', 'code')
                        ->searchable()
                        ->visible(fn (?EmployeeProfile $record): bool => $record?->user?->role === Role::STAFF)
                        ->helperText('Gerobak asal buat referensi. Gerobak Rider hari ini mengikuti Add Stock dari Produksi.'),
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
                    TextInput::make('npwp')
                        ->label('NPWP')
                        ->maxLength(32)
                        ->dehydrated(fn (): bool => Gate::allows('manage-payroll')),
                ]),
        ]);
    }
}
