<?php

namespace App\Filament\Resources\Partners\Tables;

use App\Enums\Role;
use App\Models\Partner;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PartnersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nik')
                    ->label('NIK')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Nama Karyawan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (Role $state): string => $state->label()),

                TextColumn::make('size')
                    ->label('SIZE')
                    ->placeholder('—'),

                TextColumn::make('monthly_libur_quota')
                    ->label('Jatah Klibur')
                    ->alignCenter(),

                TextColumn::make('user.name')
                    ->label('Akun Aplikasi')
                    ->placeholder('Tanpa akun')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('nik')
            ->filters([
                SelectFilter::make('role')
                    ->label('Role')
                    ->options(collect(Role::cases())->mapWithKeys(fn (Role $r) => [$r->value => $r->label()])),

                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Aktif')
                    ->falseLabel('Non-aktif')
                    ->placeholder('Semua'),
            ])
            ->recordActions([
                EditAction::make(),
                // Deleting a partner takes their attendance history with it (cascade), which is
                // the wrong move for someone who simply stopped working here — hence the warning
                // rather than a silent confirm, and the "Aktif" toggle as the intended off-ramp.
                DeleteAction::make()
                    ->modalDescription(fn (Partner $record): string => sprintf(
                        'Seluruh riwayat absensi %s akan terhapus permanen. Untuk partner yang berhenti bekerja, matikan "Aktif" saja agar riwayatnya tetap tersimpan.',
                        $record->name,
                    )),
            ]);
    }
}
