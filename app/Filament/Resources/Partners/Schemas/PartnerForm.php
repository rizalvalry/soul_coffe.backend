<?php

namespace App\Filament\Resources\Partners\Schemas;

use App\Enums\Role;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('nik')
                    ->label('NIK')
                    ->maxLength(32)
                    // Nullable, not required: the reference sheet carries partners with no NIK at
                    // all, and refusing to record them would push those people back into a
                    // spreadsheet nobody can audit.
                    ->unique(ignoreRecord: true)
                    ->helperText('Boleh dikosongkan untuk partner yang belum punya NIK.'),

                TextInput::make('name')
                    ->label('Nama Karyawan')
                    ->required()
                    ->maxLength(255),

                Select::make('role')
                    ->label('Role')
                    ->options(collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => $r->label()]))
                    ->default(Role::RIDER->value)
                    ->required()
                    ->helperText('Menentukan di bawah judul mana partner ini muncul pada laporan absensi.'),

                Select::make('size')
                    ->label('SIZE (seragam)')
                    ->options(['S' => 'S', 'M' => 'M', 'L' => 'L', 'XL' => 'XL', 'XXL' => 'XXL'])
                    ->native(false),

                TextInput::make('monthly_libur_quota')
                    ->label('Jatah Klibur / bulan')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(31)
                    ->default(4)
                    ->required()
                    ->helperText('Hari libur berbayar per bulan. Selisih di atas angka ini muncul sebagai "Lebih dari Jatah".'),

                Select::make('user_id')
                    ->label('Akun Aplikasi (opsional)')
                    ->options(fn (): array => User::query()
                        ->where('is_active', true)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->helperText('Hubungkan bila partner ini juga punya akun untuk masuk aplikasi. Kosongkan bila tidak.'),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->helperText('Partner non-aktif tidak lagi muncul di laporan absensi bulan berikutnya.'),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }
}
