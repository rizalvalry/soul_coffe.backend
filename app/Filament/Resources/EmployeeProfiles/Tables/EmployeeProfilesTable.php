<?php

namespace App\Filament\Resources\EmployeeProfiles\Tables;

use App\Models\EmployeeProfile;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

class EmployeeProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('user.role')->label('Role')->formatStateUsing(fn ($state): string => $state->label()),
                TextColumn::make('division')->label('Divisi')->searchable()->sortable(),
                TextColumn::make('position_title')->label('Jabatan')->searchable(),
                TextColumn::make('defaultCart.code')->label('Coffee Bike asal')->placeholder('—'),
                IconColumn::make('payroll_active')
                    ->label('Payroll')
                    ->boolean()
                    ->visible(fn (): bool => Gate::allows('manage-payroll')),
                TextColumn::make('base_salary_minor')
                    ->label('Gaji pokok (jadwal terbaru)')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : 'Rp '.number_format($state, 0, ',', '.'))
                    ->visible(fn (): bool => Gate::allows('manage-payroll')),
            ])
            ->filters([
                SelectFilter::make('division')
                    ->label('Divisi')
                    ->options(fn (): array => EmployeeProfile::query()
                        ->whereNotNull('division')
                        ->distinct()
                        ->orderBy('division')
                        ->pluck('division', 'division')
                        ->all()),
            ])
            ->recordActions([EditAction::make()]);
    }
}
